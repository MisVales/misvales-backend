<?php

namespace App\Services\Vale;

use App\Contracts\Credito\VerificadorDisponibilidadCredito;
use App\Enums\BaseStatus;
use App\Enums\EstadoDistribuidora;
use App\Enums\EstadoRestriccionUsoCredito;
use App\Enums\EstadoVale;
use App\Enums\TipoMovimientoLineaCredito;
use App\Enums\TipoVale;
use App\Enums\VersionStatus;
use App\Exceptions\ExcepcionVale;
use App\Helpers\AuditHelper;
use App\Models\AsignacionCategoriaDistribuidora;
use App\Models\AsignacionClienteDistribuidora;
use App\Models\BloqueoOperativoDistribuidora;
use App\Models\CategoryVersion;
use App\Models\Cliente;
use App\Models\Distribuidora;
use App\Models\LineaCredito;
use App\Models\MovimientoLineaCredito;
use App\Models\OutboxEvent;
use App\Models\ProductVersion;
use App\Models\RestriccionUsoCredito;
use App\Models\User;
use App\Models\Vale;
use App\Services\Relacion\ServicioSaldoValeRelacion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ServicioGeneracionVale
{
    public function __construct(
        private readonly CalculadorFinancieroVale $calculador,
        private readonly VerificadorDisponibilidadCredito $credito,
        private readonly ConfiguracionFinancieraVale $configuracionFinanciera,
        private readonly ServicioSaldoValeRelacion $saldosVale,
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
                $consulta->where('client_number', 'like', "%{$termino}%")
                    ->orWhere('first_name', 'like', "%{$termino}%")
                    ->orWhere('first_last_name', 'like', "%{$termino}%")
                    ->orWhere('second_last_name', 'like', "%{$termino}%");
            })
            ->orderBy('first_name')
            ->orderBy('first_last_name')
            ->limit(12)
            ->get();
    }

    /** @return array{category: array{name: string, percentage: string}} */
    public function contextoFinanciero(User $actor): array
    {
        $distribuidora = $this->resolverDistribuidoraActiva($actor);
        $categoria = $this->resolverCategoriaVigente($distribuidora);

        return [
            'category' => ['name' => $categoria->name, 'percentage' => (string) $categoria->profit_percentage],
        ];
    }

    /** @return Collection<int, ProductVersion> */
    public function productosElegibles(User $actor): Collection
    {
        $distribuidora = $this->resolverDistribuidoraActiva($actor);

        $operacionBloqueada = BloqueoOperativoDistribuidora::query()
            ->where('distributor_id', $distribuidora->id)
            ->where('type', 'DELINQUENCY')
            ->where('status', 'ACTIVE')
            ->exists()
            || Vale::query()
                ->where('distributor_id', $distribuidora->id)
                ->where('type', TipoVale::PREVALE)
                ->whereIn('status', [EstadoVale::GENERADO, EstadoVale::VALIDACION_CAJA, EstadoVale::CORRECCION_PENDIENTE, EstadoVale::LIBERADO])
                ->exists()
            || RestriccionUsoCredito::query()
                ->where('distributor_id', $distribuidora->id)
                ->where('status', EstadoRestriccionUsoCredito::RESERVADA)
                ->exists();

        if ($operacionBloqueada) {
            return collect();
        }

        try {
            // No se muestra un producto si la distribuidora no puede emitirlo.
            $categoria = $this->resolverCategoriaVigente($distribuidora);
            $disponibilidad = $this->credito->evaluar($distribuidora->id, '0.0000');
        } catch (ExcepcionVale|ModelNotFoundException) {
            return collect();
        }

        return ProductVersion::query()
            ->with('product')
            ->where('status', 'PUBLISHED')
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->whereNotNull('loan_commission_percentage')
            ->whereNotNull('simple_interest_percentage')
            ->whereNotNull('insurance_amount')
            ->whereNotNull('fortnights_count')
            ->whereNotNull('late_fee_amount')
            ->whereHas('product', fn ($query) => $query
                ->where('status', 'ACTIVE'))
            ->where('nominal_amount', '<=', $disponibilidad->available_balance)
            ->when(
                $disponibilidad->has_active_restriction,
                fn ($query) => $query
                    ->where('nominal_amount', '>=', $disponibilidad->lower_limit)
                    ->where('nominal_amount', '<=', $disponibilidad->upper_limit),
            )
            ->orderBy('nominal_amount')
            ->get()
            ->filter(fn (ProductVersion $version): bool => $this->productoEsSolicitable($version, $distribuidora->id, (string) $categoria->profit_percentage))
            ->values();
    }

    public function previsualizar(User $actor, string $clienteId, string $versionProductoId): array
    {
        $contexto = $this->resolverContexto($actor, $clienteId, $versionProductoId);

        return $this->respuestaPrevisualizacion($contexto);
    }

    public function generar(User $actor, string $clienteId, string $versionProductoId): Vale
    {
        return DB::transaction(function () use ($actor, $clienteId, $versionProductoId): Vale {
            $distribuidora = Distribuidora::query()->where('user_id', $actor->id)->firstOrFail();
            $linea = LineaCredito::query()->where('distributor_id', $distribuidora->id)->lockForUpdate()->firstOrFail();

            $contexto = $this->resolverContexto($actor, $clienteId, $versionProductoId);
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

            $usadoAntes = (string) $linea->used_balance;
            $usadoDespues = bcadd($usadoAntes, (string) $vale->capital, 4);
            if (bccomp($usadoDespues, (string) $linea->total_authorized, 4) > 0) {
                throw new ExcepcionVale('CREDIT_INSUFFICIENT', 'La emisión excedería la línea autorizada.', 409);
            }
            $linea->forceFill(['used_balance' => $usadoDespues, 'lock_version' => $linea->lock_version + 1])->save();
            $secuencia = ((int) MovimientoLineaCredito::query()->where('credit_line_id', $linea->id)->max('sequence')) + 1;
            MovimientoLineaCredito::query()->create([
                'credit_line_id' => $linea->id,
                'distributor_id' => $linea->distributor_id,
                'sequence' => $secuencia,
                'type' => TipoMovimientoLineaCredito::VOUCHER_ISSUED,
                'amount' => $vale->capital,
                'total_authorized_before' => $linea->total_authorized,
                'total_authorized_after' => $linea->total_authorized,
                'used_balance_before' => $usadoAntes,
                'used_balance_after' => $usadoDespues,
                'source_type' => 'VOUCHER_ISSUANCE',
                'source_id' => $vale->id,
                'reason' => 'Capital comprometido al emitir el vale',
                'performed_by' => $actor->id,
                'authorized_by' => $actor->id,
                'idempotency_key' => 'voucher-issued:'.$vale->id,
                'occurred_at' => now(),
            ]);

            if ($vale->credit_restriction_id !== null) {
                $reserved = RestriccionUsoCredito::query()
                    ->whereKey($vale->credit_restriction_id)
                    ->where('status', EstadoRestriccionUsoCredito::ACTIVA)
                    ->lockForUpdate()
                    ->update([
                        'status' => EstadoRestriccionUsoCredito::RESERVADA,
                        'reserved_voucher_id' => $vale->id,
                        'reserved_at' => now(),
                        'lock_version' => DB::raw('lock_version + 1'),
                    ]);
                if ($reserved !== 1) {
                    throw new ExcepcionVale('CREDIT_50_PERCENT_RESTRICTION_ALREADY_RESERVED', 'Existe otro vale pendiente de feriar que reservó la regla temporal del 50%.', 409);
                }
            }

            AuditHelper::log('VOUCHER_GENERATED', 'vouchers', $vale->id, $actor->id, $vale->branch_id, null, [
                'folio' => $folio, 'type' => $tipo->value, 'capital' => $vale->capital,
                'used_balance_before' => $usadoAntes, 'used_balance_after' => $usadoDespues,
            ]);
            OutboxEvent::query()->create(['event_type' => 'VoucherGenerated', 'payload' => ['voucher_id' => $vale->id, 'folio' => $folio], 'status' => 'PENDING']);

            return $vale->load(['cliente', 'distribuidora.usuario', 'producto', 'versionProducto', 'versionCategoria', 'parcialidades']);
        }, 3);
    }

    private function resolverContexto(User $actor, string $clienteId, string $versionProductoId): array
    {
        $distribuidora = $this->resolverDistribuidoraActiva($actor);
        if (BloqueoOperativoDistribuidora::query()->where('distributor_id', $distribuidora->id)->where('type', 'DELINQUENCY')->where('status', 'ACTIVE')->exists()) {
            throw new ExcepcionVale('DISTRIBUTOR_DELINQUENCY_BLOCK', 'La distribuidora tiene un bloqueo vigente por morosidad.', 409);
        }
        if (Vale::query()->where('distributor_id', $distribuidora->id)->where('type', TipoVale::PREVALE)->whereIn('status', [EstadoVale::GENERADO, EstadoVale::VALIDACION_CAJA, EstadoVale::CORRECCION_PENDIENTE, EstadoVale::LIBERADO])->exists()) {
            throw new ExcepcionVale('PENDING_PREVOUCHER_MUST_BE_CASHED', 'Debes feriar o cancelar el prevale pendiente antes de solicitar otro vale.', 409);
        }
        if (RestriccionUsoCredito::query()->where('distributor_id', $distribuidora->id)->where('status', EstadoRestriccionUsoCredito::RESERVADA)->exists()) {
            throw new ExcepcionVale('PENDING_RESTRICTED_VOUCHER_MUST_BE_CASHED', 'Debes feriar o cancelar el vale pendiente antes de solicitar otro y conservar la validación del 50%.', 409);
        }

        $cliente = Cliente::query()->findOrFail($clienteId);
        $asignacion = AsignacionClienteDistribuidora::query()->where('client_id', $cliente->id)->where('distributor_id', $distribuidora->id)->whereNull('ends_at')->first();
        if (! $asignacion || $asignacion->branch_id !== $distribuidora->branch_id) {
            throw new ExcepcionVale('CLIENT_NOT_ASSIGNED_TO_DISTRIBUTOR', 'El cliente no está asociado a la distribuidora activa.', 404);
        }

        $this->validarSaldoPendienteDelCliente($cliente);

        $versionProducto = ProductVersion::query()->with('product')->whereKey($versionProductoId)
            ->where('status', 'PUBLISHED')->where('effective_from', '<=', now())
            ->where(fn ($consulta) => $consulta->whereNull('effective_to')->orWhere('effective_to', '>', now()))->first();
        if (! $versionProducto || $versionProducto->product?->status !== BaseStatus::ACTIVE) {
            throw new ExcepcionVale('PRODUCT_NOT_AVAILABLE', 'El producto no está activo y publicado.', 409);
        }

        $categoria = $this->resolverCategoriaVigente($distribuidora);

        $valoresProducto = $this->configuracionFinanciera->resolver($versionProducto)['values'];
        $condiciones = [
            'commission_rate' => $valoresProducto['loan_commission_percentage'],
            'interest_rate' => $valoresProducto['simple_interest_percentage'],
            'insurance_amount' => $valoresProducto['insurance_amount'],
            'installment_count' => $valoresProducto['fortnights_count'],
            'late_fee_amount' => $valoresProducto['late_fee_amount'],
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

        return ['client' => $cliente, 'distributor' => $distribuidora, 'product' => $versionProducto->product, 'product_version' => $versionProducto, 'category_version' => $categoria, 'calculation' => $calculo, 'credit' => $credito, 'financial_conditions' => $condiciones, 'financial_configuration_versions' => []];
    }

    private function validarSaldoPendienteDelCliente(Cliente $cliente): void
    {
        $pending = Vale::query()
            ->where('client_id', $cliente->id)
            ->whereNotIn('status', [EstadoVale::CANCELADO, EstadoVale::RECHAZADO])
            ->get();

        foreach ($pending as $voucher) {
            $balance = $this->saldosVale->saldoPendienteVale($voucher);
            if (bccomp($balance, '0', 4) > 0) {
                throw new ExcepcionVale(
                    'CLIENT_HAS_PENDING_VOUCHER',
                    'El cliente todavía tiene un vale con saldo pendiente.',
                    409,
                    ['voucher_id' => $voucher->id, 'folio' => $voucher->folio, 'saldo_pendiente' => $balance],
                );
            }
        }
    }

    private function resolverCategoriaVigente(Distribuidora $distribuidora): CategoryVersion
    {
        $asignacionCategoria = AsignacionCategoriaDistribuidora::query()->with('versionCategoria.category')
            ->where('distributor_id', $distribuidora->id)->where('starts_at', '<=', now())
            ->where(fn ($consulta) => $consulta->whereNull('ends_at')->orWhere('ends_at', '>', now()))->latest('starts_at')->first();
        $version = $asignacionCategoria?->versionCategoria;
        if (! $version
            || $version->status !== VersionStatus::PUBLISHED
            || $version->category?->status !== BaseStatus::ACTIVE
            || $version->effective_from === null
            || $version->effective_from->isFuture()
            || ($version->effective_to !== null && ! $version->effective_to->isFuture())) {
            throw new ExcepcionVale('DISTRIBUTOR_CATEGORY_NOT_AVAILABLE', 'La distribuidora no tiene una categoría publicada vigente.', 409);
        }

        return $version;
    }

    private function productoEsSolicitable(ProductVersion $version, string $distribuidoraId, string $categoryRate): bool
    {
        try {
            $valores = $this->configuracionFinanciera->resolver($version)['values'];
            $calculo = $this->calculador->calcular(
                (string) $version->nominal_amount,
                $valores['loan_commission_percentage'],
                $valores['simple_interest_percentage'],
                $valores['fortnights_count'],
                $valores['insurance_amount'],
                $categoryRate,
            );
            $disponibilidad = $this->credito->evaluar($distribuidoraId, $calculo['capital']);

            return $disponibilidad->capital_is_available
                && $disponibilidad->capital_satisfies_restriction;
        } catch (ExcepcionVale|ModelNotFoundException|\InvalidArgumentException) {
            return false;
        }
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
                'payment_with_late_fee' => bcadd($calculo['client_payment_per_fortnight'], $condiciones['late_fee_amount'], 4),
                'two_payments_with_late_fee' => bcmul(bcadd($calculo['client_payment_per_fortnight'], $condiciones['late_fee_amount'], 4), '2', 4),
            ],
        ];
    }

    private function siguienteFolio(): string
    {
        $resultado = DB::selectFromWriteConnection('SELECT NEXT VALUE FOR voucher_folio_seq AS value');
        $secuencia = (int) ($resultado[0]->value ?? 1);

        return sprintf('VAL-%s-%08d', now()->format('Y'), $secuencia);
    }

    private function esValeDigital(string $distribuidoraId): bool
    {
        return Vale::query()
            ->where('distributor_id', $distribuidoraId)
            ->whereNotIn('status', [EstadoVale::CANCELADO, EstadoVale::RECHAZADO])
            ->exists();
    }
}
