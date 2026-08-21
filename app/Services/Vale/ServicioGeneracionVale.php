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
use App\Models\User;
use App\Models\Vale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ServicioGeneracionVale
{
    public function __construct(
        private readonly CalculadorFinancieroVale $calculador,
        private readonly VerificadorDisponibilidadCredito $credito,
        private readonly ConfiguracionFinancieraVale $configuracionFinanciera,
    ) {}

    /** @return Collection<int, Cliente> */
    public function buscarClientesElegibles(User $actor, string $termino): Collection
    {
        $distribuidora = $this->resolverDistribuidoraActiva($actor);
        $termino = trim($termino);

        return Cliente::query()
            ->select(['id', 'client_number', 'first_name', 'first_last_name', 'second_last_name'])
            ->whereHas('asignacionVigente', fn ($asignacion) => $asignacion
                ->where('distributor_id', $distribuidora->id)
                ->where('branch_id', $distribuidora->branch_id))
            ->where(function ($consulta) use ($termino): void {
                $consulta->where('client_number', 'ilike', "%{$termino}%")
                    ->orWhere('first_name', 'ilike', "%{$termino}%")
                    ->orWhere('first_last_name', 'ilike', "%{$termino}%")
                    ->orWhere('second_last_name', 'ilike', "%{$termino}%");
            })
            ->orderBy('first_name')
            ->orderBy('first_last_name')
            ->limit(12)
            ->get();
    }

    /** @return array{category: array{name: string, percentage: string}, conditions: array{commission_rate: string, interest_rate: string, insurance_amount: string, late_fee_amount: string}} */
    public function contextoFinanciero(User $actor): array
    {
        $distribuidora = $this->resolverDistribuidoraActiva($actor);
        $categoria = $this->resolverCategoriaVigente($distribuidora);
        $configuracion = $this->configuracionFinanciera->resolver()['values'];

        return [
            'category' => ['name' => $categoria->name, 'percentage' => (string) $categoria->profit_percentage],
            'conditions' => [
                'commission_rate' => $configuracion['loan_commission_percentage'],
                'interest_rate' => $configuracion['simple_interest_percentage'],
                'insurance_amount' => $configuracion['insurance_amount'],
                'late_fee_amount' => $configuracion['late_fee_amount'],
            ],
        ];
    }

    public function previsualizar(User $actor, string $clienteId, string $versionProductoId, int $installmentCount): array
    {
        $contexto = $this->resolverContexto($actor, $clienteId, $versionProductoId, $installmentCount);

        return $this->respuestaPrevisualizacion($contexto);
    }

    public function generar(User $actor, string $clienteId, string $versionProductoId, int $installmentCount): Vale
    {
        return DB::transaction(function () use ($actor, $clienteId, $versionProductoId, $installmentCount): Vale {
            $distribuidora = Distribuidora::query()->where('user_id', $actor->id)->firstOrFail();
            LineaCredito::query()->where('distributor_id', $distribuidora->id)->lockForUpdate()->firstOrFail();

            $contexto = $this->resolverContexto($actor, $clienteId, $versionProductoId, $installmentCount);
            $calculo = $contexto['calculation'];
            $tipo = $this->esValeDigital($distribuidora->id) ? TipoVale::VALE_DIGITAL : TipoVale::PREVALE;
            $folio = $this->siguienteFolio();

            $snapshot = [
                'product' => ['id' => $contexto['product']->id, 'code' => $contexto['product']->code],
                'product_version' => ['id' => $contexto['product_version']->id, 'version' => $contexto['product_version']->version, 'name' => $contexto['product_version']->name],
                'category_version' => ['id' => $contexto['category_version']->id, 'version' => $contexto['category_version']->version, 'name' => $contexto['category_version']->name],
                'credit' => $contexto['credit'],
                'calculation' => collect($calculo)->except('installments')->all(),
                'financial_conditions' => $contexto['financial_conditions'],
                'financial_configuration_versions' => $contexto['financial_configuration_versions'],
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
                ...collect($calculo)->only([
                    'capital', 'loan_commission_percentage', 'loan_commission_amount',
                    'simple_interest_percentage', 'fortnights_count', 'insurance_amount',
                    'interest_total', 'misvales_total', 'misvales_payment_per_fortnight',
                    'distributor_profit_percentage', 'distributor_profit_total',
                    'distributor_profit_per_fortnight', 'client_payment_per_fortnight',
                    'client_total',
                ])->all(),
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

    private function resolverContexto(User $actor, string $clienteId, string $versionProductoId, int $installmentCount): array
    {
        $distribuidora = $this->resolverDistribuidoraActiva($actor);
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

        $categoria = $this->resolverCategoriaVigente($distribuidora);

        $configuracion = $this->configuracionFinanciera->resolver();
        $valoresGlobales = $configuracion['values'];
        $condiciones = [
            'commission_rate' => $valoresGlobales['loan_commission_percentage'],
            'interest_rate' => $valoresGlobales['simple_interest_percentage'],
            'insurance_amount' => $valoresGlobales['insurance_amount'],
            'installment_count' => $installmentCount,
            'late_fee_amount' => $valoresGlobales['late_fee_amount'],
            'category_rate' => (string) $categoria->profit_percentage,
        ];
        $calculo = $this->calculador->calcular(
            (string) $versionProducto->nominal_amount,
            $condiciones['commission_rate'],
            $condiciones['interest_rate'],
            $condiciones['installment_count'],
            $condiciones['insurance_amount'],
            $condiciones['category_rate'],
        );
        $credito = $this->credito->evaluar($distribuidora->id, $calculo['capital']);
        if (! $credito->capital_is_available) {
            throw new ExcepcionVale('CREDIT_INSUFFICIENT', 'La línea disponible no cubre el capital del producto.', 409, ['available_balance' => $credito->available_balance]);
        }
        if (! $credito->capital_satisfies_restriction) {
            throw new ExcepcionVale('CREDIT_50_PERCENT_RULE_NOT_SATISFIED', 'El producto no está dentro del rango permitido por la restricción vigente.', 409, ['lower_limit' => $credito->lower_limit, 'upper_limit' => $credito->upper_limit]);
        }

        return ['client' => $cliente, 'distributor' => $distribuidora, 'product' => $versionProducto->product, 'product_version' => $versionProducto, 'category_version' => $categoria, 'calculation' => $calculo, 'credit' => $credito, 'financial_conditions' => $condiciones, 'financial_configuration_versions' => $configuracion['versions']];
    }

    private function resolverCategoriaVigente(Distribuidora $distribuidora): \App\Models\CategoryVersion
    {
        $asignacionCategoria = AsignacionCategoriaDistribuidora::query()->with('versionCategoria')
            ->where('distributor_id', $distribuidora->id)->where('starts_at', '<=', now())
            ->where(fn ($consulta) => $consulta->whereNull('ends_at')->orWhere('ends_at', '>', now()))->latest('starts_at')->first();
        if (! $asignacionCategoria || $asignacionCategoria->versionCategoria->status->value !== 'PUBLISHED') {
            throw new ExcepcionVale('DISTRIBUTOR_CATEGORY_NOT_AVAILABLE', 'La distribuidora no tiene una categoría publicada vigente.', 409);
        }

        return $asignacionCategoria->versionCategoria;
    }

    private function resolverDistribuidoraActiva(User $actor): Distribuidora
    {
        if (! $actor->hasPermissionTo('vouchers.create_own')) {
            throw new ExcepcionVale('VOUCHER_CREATE_FORBIDDEN', 'No tienes permiso para generar vales.', 403);
        }

        $distribuidora = Distribuidora::query()->where('user_id', $actor->id)->first();
        if (! $distribuidora || $distribuidora->status !== EstadoDistribuidora::ACTIVA) {
            throw new ExcepcionVale('DISTRIBUTOR_NOT_ACTIVE', 'La distribuidora no está activa.', 409);
        }

        return $distribuidora;
    }

    private function respuestaPrevisualizacion(array $contexto): array
    {
        $calculo = $contexto['calculation'];
        $condiciones = $contexto['financial_conditions'];

        return [
            'voucher_type' => $this->esValeDigital($contexto['distributor']->id) ? TipoVale::VALE_DIGITAL->value : TipoVale::PREVALE->value,
            'client' => ['id' => $contexto['client']->id, 'client_number' => $contexto['client']->client_number, 'full_name' => trim($contexto['client']->first_name.' '.$contexto['client']->first_last_name.' '.$contexto['client']->second_last_name)],
            'product' => ['id' => $contexto['product']->id, 'version_id' => $contexto['product_version']->id, 'code' => $contexto['product']->code, 'name' => $contexto['product_version']->name],
            'credit' => ['total_authorized' => $contexto['credit']->total_authorized, 'used_balance' => $contexto['credit']->used_balance, 'available_balance' => $contexto['credit']->available_balance, 'has_active_restriction' => $contexto['credit']->has_active_restriction, 'lower_limit' => $contexto['credit']->lower_limit, 'upper_limit' => $contexto['credit']->upper_limit],
            'financial_conditions' => [
                'commission_rate' => $condiciones['commission_rate'],
                'interest_rate' => $condiciones['interest_rate'],
                'insurance_amount' => $condiciones['insurance_amount'],
                'installment_count' => $condiciones['installment_count'],
                'category_rate' => $condiciones['category_rate'],
                'late_fee_amount' => $condiciones['late_fee_amount'],
            ],
            'calculation' => $calculo + [
                'payment_with_late_fee' => bcadd($calculo['misvales_payment_per_fortnight'], $condiciones['late_fee_amount'], 4),
                'two_payments_with_late_fee' => bcmul(bcadd($calculo['misvales_payment_per_fortnight'], $condiciones['late_fee_amount'], 4), '2', 4),
            ],
        ];
    }

    private function siguienteFolio(): string
    {
        $secuencia = (int) DB::selectOne("SELECT nextval('voucher_folio_seq') AS value")->value;

        return sprintf('VAL-%s-%08d', now()->format('Y'), $secuencia);
    }

    private function esValeDigital(string $distribuidoraId): bool
    {
        return Vale::query()->where('distributor_id', $distribuidoraId)->exists();
    }
}
