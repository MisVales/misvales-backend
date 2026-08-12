<?php

namespace App\Services\Vale;

use App\Contracts\Credito\VerificadorDisponibilidadCredito;
use App\Enums\EstadoDistribuidora;
use App\Enums\EstadoVale;
use App\Enums\TipoVale;
use App\Exceptions\ExcepcionVale;
use App\Helpers\AuditHelper;
use App\Models\AsignacionCategoriaDistribuidora;
use App\Models\AsignacionClienteDistribuidora;
use App\Models\BloqueoOperativoDistribuidora;
use App\Models\Cliente;
use App\Models\Distribuidora;
use App\Models\LineaCredito;
use App\Models\OutboxEvent;
use App\Models\ProductVersion;
use App\Models\SolicitudTransferenciaCliente;
use App\Models\User;
use App\Models\Vale;
use Illuminate\Support\Facades\DB;

final class ServicioGeneracionVale
{
    public function __construct(
        private readonly CalculadorFinancieroVale $calculador,
        private readonly VerificadorDisponibilidadCredito $credito,
    ) {}

    public function previsualizar(User $actor, string $clienteId, string $versionProductoId): array
    {
        $contexto = $this->resolverContexto($actor, $clienteId, $versionProductoId);

        return $this->respuestaPrevisualizacion($contexto);
    }

    public function generar(User $actor, string $clienteId, string $versionProductoId): Vale
    {
        return DB::transaction(function () use ($actor, $clienteId, $versionProductoId): Vale {
            $distribuidora = Distribuidora::query()->where('user_id', $actor->id)->firstOrFail();
            LineaCredito::query()->where('distributor_id', $distribuidora->id)->lockForUpdate()->firstOrFail();

            $contexto = $this->resolverContexto($actor, $clienteId, $versionProductoId);
            $calculo = $contexto['calculation'];
            $tipo = $this->esValeDigital($clienteId) ? TipoVale::VALE_DIGITAL : TipoVale::PREVALE;
            $folio = $this->siguienteFolio();

            $snapshot = [
                'product' => ['id' => $contexto['product']->id, 'code' => $contexto['product']->code],
                'product_version' => ['id' => $contexto['product_version']->id, 'version' => $contexto['product_version']->version, 'name' => $contexto['product_version']->name],
                'category_version' => ['id' => $contexto['category_version']->id, 'version' => $contexto['category_version']->version, 'name' => $contexto['category_version']->name],
                'credit' => $contexto['credit'],
                'calculation' => collect($calculo)->except('installments')->all(),
                'generated_at' => now()->toIso8601String(),
            ];

            $vale = Vale::query()->create([
                'folio' => $folio,
                'type' => $tipo,
                'status' => EstadoVale::GENERADO,
                'client_id' => $contexto['client']->id,
                'distributor_id' => $contexto['distributor']->id,
                'branch_id' => $contexto['distributor']->branch_id,
                'credit_line_id' => $contexto['credit']->credit_line_id,
                'product_id' => $contexto['product']->id,
                'product_version_id' => $contexto['product_version']->id,
                'category_version_id' => $contexto['category_version']->id,
                'credit_restriction_id' => $contexto['credit']->restriction_id,
                ...collect($calculo)->except('installments')->all(),
                'financial_snapshot' => $snapshot,
                'created_by' => $actor->id,
                'generated_at' => now(),
            ]);

            $vale->parcialidades()->createMany(array_map(static fn (array $parcialidad): array => $parcialidad + ['due_at' => null], $calculo['installments']));

            AuditHelper::log('VOUCHER_GENERATED', 'vouchers', $vale->id, $actor->id, $vale->branch_id, null, [
                'folio' => $folio, 'type' => $tipo->value, 'capital' => $vale->capital,
            ]);
            OutboxEvent::query()->create(['event_type' => 'VoucherGenerated', 'payload' => ['voucher_id' => $vale->id, 'folio' => $folio], 'status' => 'PENDING']);

            return $vale->load(['cliente', 'distribuidora.usuario', 'producto', 'versionProducto', 'versionCategoria', 'parcialidades']);
        }, 3);
    }

    private function resolverContexto(User $actor, string $clienteId, string $versionProductoId): array
    {
        if (! $actor->hasPermissionTo('vouchers.create_own')) {
            throw new ExcepcionVale('VOUCHER_CREATE_FORBIDDEN', 'No tienes permiso para generar vales.', 403);
        }

        $distribuidora = Distribuidora::query()->where('user_id', $actor->id)->first();
        if (! $distribuidora || $distribuidora->status !== EstadoDistribuidora::ACTIVA) {
            throw new ExcepcionVale('DISTRIBUTOR_NOT_ACTIVE', 'La distribuidora no está activa.', 409);
        }
        if (BloqueoOperativoDistribuidora::query()->where('distributor_id', $distribuidora->id)->where('type', 'DELINQUENCY')->where('status', 'ACTIVE')->exists()) {
            throw new ExcepcionVale('DISTRIBUTOR_DELINQUENCY_BLOCK', 'La distribuidora tiene un bloqueo vigente por morosidad.', 409);
        }

        $cliente = Cliente::query()->findOrFail($clienteId);
        $asignacion = AsignacionClienteDistribuidora::query()->where('client_id', $cliente->id)->where('distributor_id', $distribuidora->id)->whereNull('ends_at')->first();
        if (! $asignacion || $asignacion->branch_id !== $distribuidora->branch_id) {
            throw new ExcepcionVale('CLIENT_NOT_ASSIGNED_TO_DISTRIBUTOR', 'El cliente no está asociado a la distribuidora activa.', 404);
        }

        $versionProducto = ProductVersion::query()->with('product')->whereKey($versionProductoId)
            ->where('status', 'PUBLISHED')->where('effective_from', '<=', now())
            ->where(fn ($consulta) => $consulta->whereNull('effective_to')->orWhere('effective_to', '>', now()))->first();
        if (! $versionProducto || $versionProducto->product->status->value !== 'ACTIVE') {
            throw new ExcepcionVale('PRODUCT_NOT_AVAILABLE', 'El producto no está activo y publicado.', 409);
        }

        $asignacionCategoria = AsignacionCategoriaDistribuidora::query()->with('versionCategoria')
            ->where('distributor_id', $distribuidora->id)->where('starts_at', '<=', now())
            ->where(fn ($consulta) => $consulta->whereNull('ends_at')->orWhere('ends_at', '>', now()))->latest('starts_at')->first();
        if (! $asignacionCategoria || $asignacionCategoria->versionCategoria->status->value !== 'PUBLISHED') {
            throw new ExcepcionVale('DISTRIBUTOR_CATEGORY_NOT_AVAILABLE', 'La distribuidora no tiene una categoría publicada vigente.', 409);
        }

        $calculo = $this->calculador->calcular(
            (string) $versionProducto->nominal_amount,
            (string) $versionProducto->loan_commission_percentage,
            (string) $versionProducto->simple_interest_percentage,
            (int) $versionProducto->fortnights_count,
            (string) $versionProducto->insurance_amount,
            (string) $asignacionCategoria->versionCategoria->profit_percentage,
        );
        $credito = $this->credito->evaluar($distribuidora->id, $calculo['capital']);
        if (! $credito->capital_is_available) {
            throw new ExcepcionVale('CREDIT_INSUFFICIENT', 'La línea disponible no cubre el capital del producto.', 409, ['available_balance' => $credito->available_balance]);
        }
        if (! $credito->capital_satisfies_restriction) {
            throw new ExcepcionVale('CREDIT_50_PERCENT_RULE_NOT_SATISFIED', 'El producto no está dentro del rango permitido por la restricción vigente.', 409, ['lower_limit' => $credito->lower_limit, 'upper_limit' => $credito->upper_limit]);
        }

        return ['client' => $cliente, 'distributor' => $distribuidora, 'product' => $versionProducto->product, 'product_version' => $versionProducto, 'category_version' => $asignacionCategoria->versionCategoria, 'calculation' => $calculo, 'credit' => $credito];
    }

    private function respuestaPrevisualizacion(array $contexto): array
    {
        return [
            'voucher_type' => $this->esValeDigital($contexto['client']->id) ? TipoVale::VALE_DIGITAL->value : TipoVale::PREVALE->value,
            'client' => ['id' => $contexto['client']->id, 'client_number' => $contexto['client']->client_number, 'full_name' => trim($contexto['client']->first_name.' '.$contexto['client']->first_last_name.' '.$contexto['client']->second_last_name)],
            'product' => ['id' => $contexto['product']->id, 'version_id' => $contexto['product_version']->id, 'code' => $contexto['product']->code, 'name' => $contexto['product_version']->name],
            'credit' => ['total_authorized' => $contexto['credit']->total_authorized, 'used_balance' => $contexto['credit']->used_balance, 'available_balance' => $contexto['credit']->available_balance, 'has_active_restriction' => $contexto['credit']->has_active_restriction, 'lower_limit' => $contexto['credit']->lower_limit, 'upper_limit' => $contexto['credit']->upper_limit],
            'calculation' => $contexto['calculation'],
        ];
    }

    private function siguienteFolio(): string
    {
        $secuencia = (int) DB::selectOne("SELECT nextval('voucher_folio_seq') AS value")->value;

        return sprintf('VAL-%s-%08d', now()->format('Y'), $secuencia);
    }

    private function esValeDigital(string $clienteId): bool
    {
        return Vale::query()->where('client_id', $clienteId)->exists()
            || SolicitudTransferenciaCliente::query()->where('client_id', $clienteId)->where('status', 'COMPLETED')->exists();
    }
}
