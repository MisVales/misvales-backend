<?php

namespace Tests\Feature\Vale;

use App\Enums\EstadoVale;
use App\Exceptions\ExcepcionConciliacion;
use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\AclaracionPago;
use App\Models\AlertaRiesgoDistribuidora;
use App\Models\AplicacionExcedente;
use App\Models\AsignacionCategoriaDistribuidora;
use App\Models\AsignacionClienteDistribuidora;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\CategoryVersion;
use App\Models\Cliente;
use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\DatosPersonalesSolicitud;
use App\Models\Distribuidora;
use App\Models\ExcedenteDistribuidora;
use App\Models\ImportacionArchivoBancario;
use App\Models\LineaCredito;
use App\Models\MediaFile;
use App\Models\MediaFileBinding;
use App\Models\MovimientoBancario;
use App\Models\MovimientoLineaCredito;
use App\Models\PagoRelacion;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\RelacionDistribuidora;
use App\Models\RelacionPartidaDistribuidora;
use App\Models\RestriccionUsoCredito;
use App\Models\Role;
use App\Models\SolicitudModificacionVale;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\Vale;
use App\Notifications\NotificacionEventoDominio;
use App\Services\Cliente\ProtectorDatosCliente;
use App\Services\Conciliacion\LectorXlsxBancario;
use App\Services\Conciliacion\ServicioDisponibilidadConciliacion;
use App\Services\Conciliacion\ServicioImportacionBancaria;
use App\Services\Conciliacion\ServicioTransferenciasBancariasSimuladas;
use App\Services\Excedente\ServicioExcedente;
use App\Services\Pago\ServicioAplicacionPago;
use App\Services\Recargo\ServicioEvaluacionRecargo;
use App\Services\Relacion\ServicioGeneracionRelacion;
use App\Services\Relacion\ServicioPdfEstadoCuenta;
use App\Services\Relacion\ServicioSaldoValeRelacion;
use App\Services\Riesgo\ServicioMorosidadDistribuidora;
use App\Services\SolicitudDistribuidora\ProtectorDatosSolicitud;
use App\Services\Vale\CalculadorFinancieroVale;
use App\Services\Vale\ServicioCalendarioParcialidadesVale;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

final class CajaValeApiTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $distributorUser;

    private User $cashier;

    private Vale $voucher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
        $this->branch = Branch::factory()->create();
        $this->distributorUser = $this->user('distributor', $this->branch->id);
        $this->cashier = $this->user('cashier', $this->branch->id);
        $distributor = Distribuidora::factory()->active()->create(['user_id' => $this->distributorUser->id, 'branch_id' => $this->branch->id]);
        $protectorSolicitud = app(ProtectorDatosSolicitud::class);
        $datosPersonales = new DatosPersonalesSolicitud([
            'application_id' => $distributor->application_id,
            'first_name' => 'Distribuidora',
            'first_last_name' => 'Prueba',
            'nationality' => 'MEXICAN',
            'birth_country' => 'MX',
            'birth_date' => '1990-01-01',
            'birth_state' => 'Coahuila',
            'birth_city' => 'Torreón',
            'email' => 'distribuidora@example.test',
            'phone_number' => '8710000000',
            'identification_country' => 'MX',
            'official_id_type' => 'INE',
        ]);
        $datosPersonales->forceFill([
            'curp_ciphertext' => $protectorSolicitud->cifrarCurp('DIPR900101HCLSTR01'),
            'curp_hmac' => $protectorSolicitud->generarHmacCurp('DIPR900101HCLSTR01'),
            'official_id_number_ciphertext' => $protectorSolicitud->cifrarIdentificacion('ID-DISTRIBUIDORA-001'),
            'official_id_number_hmac' => $protectorSolicitud->generarHmacIdentificacion('ID-DISTRIBUIDORA-001'),
        ])->save();
        $line = LineaCredito::factory()->create(['distributor_id' => $distributor->id, 'total_authorized' => '30000.0000', 'used_balance' => '5000.0000']);
        $client = Cliente::factory()->create(['created_by' => $this->distributorUser->id]);
        AsignacionClienteDistribuidora::factory()->create(['client_id' => $client->id, 'distributor_id' => $distributor->id, 'branch_id' => $this->branch->id, 'starts_at' => now()->subDay(), 'ends_at' => null, 'assigned_by' => $this->distributorUser->id]);
        $addressProof = MediaFile::query()->create([
            'file_type' => 'ADDRESS_PROOF',
            'disk' => 'local',
            'path' => 'private/address-proof.pdf',
            'original_name' => 'address-proof.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => hash('sha256', 'address-proof'),
            'uploaded_by' => $this->cashier->id,
            'validation_status' => 'VALIDATED',
            'validated_at' => now(),
        ]);
        MediaFileBinding::query()->create([
            'media_file_id' => $addressProof->id,
            'owner_type' => 'distributor_application',
            'owner_id' => $distributor->application_id,
            'purpose' => 'ADDRESS_PROOF',
            'created_by' => $this->cashier->id,
        ]);
        $category = Category::query()->create(['code' => 'CASH-CAT', 'status' => 'ACTIVE', 'created_by' => $this->distributorUser->id]);
        $categoryVersion = CategoryVersion::query()->create(['category_id' => $category->id, 'version' => 1, 'name' => 'Base', 'profit_percentage' => '0.050000', 'status' => 'PUBLISHED', 'effective_from' => now()->subDay(), 'reason' => 'Test', 'created_by' => $this->distributorUser->id, 'published_by' => $this->distributorUser->id, 'published_at' => now()]);
        AsignacionCategoriaDistribuidora::query()->create(['distributor_id' => $distributor->id, 'category_version_id' => $categoryVersion->id, 'starts_at' => now()->subDay(), 'assigned_by' => $this->distributorUser->id]);
        $product = Product::query()->create([
            'code' => 'CASH-10000',
            'status' => 'ACTIVE',
            'loan_commission_percentage' => '0.100000',
            'simple_interest_percentage' => '0.020000',
            'insurance_amount' => '100.0000',
            'fortnights_count' => 4,
            'late_fee_amount' => '300.0000',
            'created_by' => $this->distributorUser->id,
        ]);
        $productVersion = ProductVersion::query()->create(['product_id' => $product->id, 'version' => 1, 'name' => 'Vale caja', 'nominal_amount' => '10000.0000', 'loan_commission_percentage' => '0.100000', 'simple_interest_percentage' => '0.020000', 'insurance_amount' => '100.0000', 'fortnights_count' => 4, 'status' => 'PUBLISHED', 'effective_from' => now()->subDay(), 'reason' => 'Test', 'created_by' => $this->distributorUser->id, 'published_by' => $this->distributorUser->id, 'published_at' => now()]);
        $this->voucher = Vale::query()->create(['folio' => 'VAL-2026-99999999', 'type' => 'PREVALE', 'status' => EstadoVale::GENERADO, 'client_id' => $client->id, 'distributor_id' => $distributor->id, 'branch_id' => $this->branch->id, 'credit_line_id' => $line->id, 'product_id' => $product->id, 'product_version_id' => $productVersion->id, 'category_version_id' => $categoryVersion->id, 'capital' => '10000.0000', 'loan_commission_percentage' => '0.100000', 'loan_commission_amount' => '1000.0000', 'simple_interest_percentage' => '0.020000', 'fortnights_count' => 4, 'insurance_amount' => '100.0000', 'interest_total' => '800.0000', 'misvales_total' => '11900.0000', 'misvales_payment_per_fortnight' => '2975.0000', 'distributor_profit_percentage' => '0.050000', 'distributor_profit_total' => '500.0000', 'distributor_profit_per_fortnight' => '125.0000', 'client_payment_per_fortnight' => '3100.0000', 'client_total' => '12400.0000', 'financial_snapshot' => [], 'created_by' => $this->distributorUser->id, 'generated_at' => now()]);
        $this->publishRelationConfiguration();
    }

    public function test_cajera_busca_libera_y_feria_incrementando_saldo(): void
    {
        Sanctum::actingAs($this->cashier);
        $numeroIdentificacion = app(ProtectorDatosSolicitud::class)->descifrar(
            $this->voucher->distribuidora->solicitud->datosPersonales->official_id_number_ciphertext,
        );
        $this->getJson('/api/v1/cashier/vouchers/search?search=99999999')
            ->assertSuccessful()
            ->assertJsonPath('data.0.folio', $this->voucher->folio)
            ->assertJsonPath('data.0.identity.official_id_number', $numeroIdentificacion);
        $released = $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/release", ['lock_version' => 1])->assertSuccessful()->assertJsonPath('data.status', 'RELEASED');
        $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/cash", ['bank_transaction_number' => '202608210001', 'lock_version' => $released->json('data.lock_version')])->assertSuccessful()->assertJsonPath('data.status', 'CASHED');
        $this->assertDatabaseHas('credit_lines', ['id' => $this->voucher->credit_line_id, 'used_balance' => '15000.0000']);
        $this->assertDatabaseHas('credit_line_movements', ['source_id' => $this->voucher->id, 'type' => 'VOUCHER_CASHED']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'VoucherCashed']);
    }

    public function test_feriar_programa_parcialidades_cada_quince_dias_desde_cashed_at(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-29 14:00:00', 'America/Monterrey'));
        $this->voucher->parcialidades()->createMany([
            ['number' => 1, 'capital' => '2500', 'loan_commission' => '250', 'interest' => '200', 'insurance' => '25', 'distributor_profit' => '125', 'misvales_payment' => '2975', 'client_payment' => '3100', 'due_at' => null],
            ['number' => 2, 'capital' => '2500', 'loan_commission' => '250', 'interest' => '200', 'insurance' => '25', 'distributor_profit' => '125', 'misvales_payment' => '2975', 'client_payment' => '3100', 'due_at' => null],
        ]);
        Sanctum::actingAs($this->cashier);

        $released = $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/release", ['lock_version' => 1])->assertSuccessful();
        $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/cash", [
            'bank_transaction_number' => '202608290001',
            'lock_version' => $released->json('data.lock_version'),
        ])->assertSuccessful();

        $installments = $this->voucher->parcialidades()->get();
        $this->assertSame('2026-09-13 14:00:00', $installments[0]->due_at->setTimezone('America/Monterrey')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-28 14:00:00', $installments[1]->due_at->setTimezone('America/Monterrey')->format('Y-m-d H:i:s'));
    }

    public function test_liberar_reutiliza_la_cuenta_bancaria_vigente_de_la_distribuidora(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/release", [
            'lock_version' => 1,
            'bank_name' => 'BBVA',
            'clabe' => '012345678901234567',
        ])->assertSuccessful();

        $distribuidora = $this->voucher->distribuidora;
        $this->assertDatabaseHas('distributor_bank_accounts', [
            'distributor_id' => $distribuidora->id,
            'bank_name' => 'BBVA',
            'account_holder_name' => $distribuidora->usuario->name,
        ]);

        $segundoVale = $this->voucher->replicate(['id', 'folio', 'client_id', 'created_at', 'updated_at']);
        $segundoVale->forceFill([
            'id' => (string) Str::uuid(),
            'folio' => 'VAL-2026-99999997',
            'client_id' => Cliente::factory()->create(['created_by' => $this->distributorUser->id])->id,
            'status' => EstadoVale::GENERADO,
            'lock_version' => 1,
        ])->save();

        $this->getJson("/api/v1/cashier/vouchers/{$segundoVale->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.bank_account.bank_name', 'BBVA')
            ->assertJsonPath('data.bank_account.clabe_masked', '**************4567');
        $this->postIdempotent("/api/v1/cashier/vouchers/{$segundoVale->id}/release", ['lock_version' => 1])
            ->assertSuccessful();
        $this->assertDatabaseCount('distributor_bank_accounts', 1);

        $tercerVale = $segundoVale->replicate(['id', 'folio', 'created_at', 'updated_at']);
        $tercerVale->forceFill([
            'id' => (string) Str::uuid(),
            'folio' => 'VAL-2026-99999996',
            'status' => EstadoVale::GENERADO,
            'lock_version' => 1,
        ])->save();
        $this->postIdempotent("/api/v1/cashier/vouchers/{$tercerVale->id}/release", [
            'lock_version' => 1,
            'bank_name' => 'Banorte',
            'clabe' => '987654321098765432',
        ])->assertSuccessful()
            ->assertJsonPath('data.bank_account.bank_name', 'Banorte')
            ->assertJsonPath('data.bank_account.clabe_masked', '**************5432');
        $this->assertDatabaseHas('distributor_bank_accounts', [
            'distributor_id' => $distribuidora->id,
            'bank_name' => 'BBVA',
            'is_current' => false,
        ]);
        $this->assertDatabaseCount('distributor_bank_accounts', 2);
    }

    public function test_cajera_puede_buscar_vale_de_cliente_final_sin_datos_opcionales(): void
    {
        $this->voucher->cliente->forceFill([
            'curp_ciphertext' => null,
            'curp_hmac' => null,
            'official_id_type' => null,
            'official_id_number_ciphertext' => null,
            'official_id_number_hmac' => null,
        ])->save();

        Sanctum::actingAs($this->cashier);

        $this->getJson('/api/v1/cashier/vouchers/search?search=99999999')
            ->assertSuccessful()
            ->assertJsonPath('data.0.folio', $this->voucher->folio)
            ->assertJsonMissingPath('data.0.identity.curp')
            ->assertJsonPath('data.0.bank_account', null);
    }

    public function test_flujo_financiero_integrado_desde_feria_hasta_liquidacion(): void
    {
        Storage::fake('local');

        Sanctum::actingAs($this->cashier);
        $released = $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/release", ['lock_version' => 1])->assertSuccessful();
        $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/cash", [
            'bank_transaction_number' => '202608210002',
            'lock_version' => $released->json('data.lock_version'),
        ])->assertSuccessful()->assertJsonPath('data.status', 'CASHED');
        $this->assertDatabaseHas('credit_lines', ['id' => $this->voucher->credit_line_id, 'used_balance' => '15000.0000']);

        $cutoff = CarbonImmutable::now('America/Monterrey');
        $this->voucher->parcialidades()->create([
            'number' => 1,
            'capital' => '2500.0000',
            'loan_commission' => '250.0000',
            'interest' => '200.0000',
            'insurance' => '25.0000',
            'distributor_profit' => '125.0000',
            'misvales_payment' => '2975.0000',
            'client_payment' => '3100.0000',
            'due_at' => $cutoff->subDay(),
        ]);
        $this->assertSame(1, app(ServicioGeneracionRelacion::class)->generar($cutoff));
        $relation = RelacionDistribuidora::firstOrFail();
        $this->assertSame('2975.0000', $relation->balance);
        $this->assertNotEmpty($relation->payment_reference);

        $firstPaymentAt = $cutoff->addDay()->format('Y-m-d H:i:s');
        app(ServicioImportacionBancaria::class)->importar($this->xlsx([
            [$relation->payment_reference, '1000.00', $firstPaymentAt, 'BANK-E2E-001', 'Abono parcial'],
        ]), $this->cashier, $this->branch->id);
        $this->assertSame('1975.0000', $relation->fresh()->balance);
        $this->assertDatabaseHas('credit_lines', ['id' => $this->voucher->credit_line_id, 'used_balance' => '14475.0000']);

        $secondPaymentAt = $cutoff->addDays(2)->format('Y-m-d H:i:s');
        app(ServicioImportacionBancaria::class)->importar($this->xlsx([
            [$relation->payment_reference, '1975.00', $secondPaymentAt, 'BANK-E2E-002', 'Liquidacion'],
        ]), $this->cashier, $this->branch->id);
        $relation->refresh();

        $this->assertSame('0.0000', $relation->balance);
        $this->assertSame('SETTLED', $relation->financial_status);
        $this->assertSame('EARLY', $relation->temporal_classification);
        $this->assertDatabaseHas('credit_lines', ['id' => $this->voucher->credit_line_id, 'used_balance' => '12500.0000']);
    }

    public function test_liquidacion_de_ocho_parcialidades_recupera_capital_exacto_y_el_reintento_no_duplica_linea(): void
    {
        Storage::fake('local');
        $calculation = app(CalculadorFinancieroVale::class)->calcular(
            '5000.0000',
            '0.100000',
            '0.050000',
            8,
            '100.0000',
            '0.060000',
        );
        $this->voucher->forceFill([
            'capital' => $calculation['capital'],
            'loan_commission_amount' => $calculation['loan_commission_amount'],
            'simple_interest_percentage' => $calculation['simple_interest_percentage'],
            'fortnights_count' => 8,
            'insurance_amount' => $calculation['insurance_amount'],
            'interest_total' => $calculation['interest_total'],
            'misvales_total' => $calculation['misvales_total'],
            'misvales_payment_per_fortnight' => $calculation['misvales_payment_per_fortnight'],
            'distributor_profit_total' => $calculation['distributor_profit_total'],
            'distributor_profit_per_fortnight' => $calculation['distributor_profit_per_fortnight'],
            'client_payment_per_fortnight' => $calculation['client_payment_per_fortnight'],
            'client_total' => $calculation['client_total'],
        ])->save();
        LineaCredito::query()->whereKey($this->voucher->credit_line_id)->update(['used_balance' => '0.0000']);
        $this->voucher->parcialidades()->delete();

        Sanctum::actingAs($this->cashier);
        $released = $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/release", ['lock_version' => 1])->assertSuccessful();
        $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/cash", [
            'bank_transaction_number' => '202608210099',
            'lock_version' => $released->json('data.lock_version'),
        ])->assertSuccessful();
        $this->assertDatabaseHas('credit_lines', ['id' => $this->voucher->credit_line_id, 'used_balance' => '5000.0000']);

        $cutoff = CarbonImmutable::now('America/Monterrey');
        $this->voucher->parcialidades()->createMany(array_map(
            static fn (array $installment): array => $installment + ['due_at' => $cutoff->subDay()],
            $calculation['installments'],
        ));
        self::assertSame(1, app(ServicioGeneracionRelacion::class)->generar($cutoff));
        $relation = RelacionDistribuidora::query()->firstOrFail();
        self::assertSame('7300.0000', $relation->balance);

        $file = $this->xlsxValido([[
            $relation->payment_reference,
            '7300.00',
            $cutoff->addDay()->format('Y-m-d H:i:s'),
            'BANK-CAPITAL-EXACTO-001',
            'Liquidación exacta',
        ]]);
        $import = app(ServicioImportacionBancaria::class)->importar($file, $this->cashier, $this->branch->id);

        self::assertSame('0.0000', $relation->fresh()->balance);
        self::assertSame('5000.0000', (string) DB::table('payment_allocations')->where('component', 'CAPITAL')->sum('amount'));
        $this->assertDatabaseHas('relation_payments', ['capital_applied' => '5000.0000', 'line_recovered' => '5000.0000']);
        $this->assertDatabaseHas('credit_lines', ['id' => $this->voucher->credit_line_id, 'used_balance' => '0.0000']);
        self::assertSame(1, MovimientoLineaCredito::query()->where('type', 'PAYMENT_RECOVERY')->count());

        $replayed = app(ServicioImportacionBancaria::class)->importar($file, $this->cashier, $this->branch->id);
        self::assertSame($import->id, $replayed->id);
        self::assertTrue($replayed->replayed);
        self::assertSame(1, PagoRelacion::query()->count());
        self::assertSame(1, MovimientoLineaCredito::query()->where('type', 'PAYMENT_RECOVERY')->count());
        $this->assertDatabaseHas('credit_lines', ['id' => $this->voucher->credit_line_id, 'used_balance' => '0.0000']);
    }

    public function test_estado_sucursal_transaccion_y_saldo_se_validan_al_feriar(): void
    {
        Sanctum::actingAs($this->user('cashier', Branch::factory()->create()->id));
        $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/release", ['lock_version' => 1])->assertNotFound();
        Sanctum::actingAs($this->cashier);
        $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/cash", ['bank_transaction_number' => '202608210003', 'lock_version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'VOUCHER_STATUS_INVALID');
        $this->voucher->update(['status' => 'RELEASED']);
        LineaCredito::query()->whereKey($this->voucher->credit_line_id)->update(['used_balance' => '25000.0000']);
        $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/cash", ['bank_transaction_number' => '202608210004', 'lock_version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_INSUFFICIENT');
    }

    public function test_transaccion_bancaria_no_se_reutiliza_y_restriccion_se_consume_solo_al_feriar(): void
    {
        $definition = ConfigurationDefinition::query()->create(['key' => 'CREDIT_TOLERANCE_AMOUNT', 'name' => 'Tolerancia', 'value_type' => 'DECIMAL', 'status' => 'ACTIVE', 'created_by' => $this->distributorUser->id]);
        $version = ConfigurationVersion::query()->create(['configuration_definition_id' => $definition->id, 'version' => 1, 'value' => '500.0000', 'status' => 'PUBLISHED', 'effective_from' => now()->subDay(), 'reason' => 'Test', 'created_by' => $this->distributorUser->id, 'published_by' => $this->distributorUser->id, 'published_at' => now()]);
        $restriction = RestriccionUsoCredito::factory()->create(['credit_line_id' => $this->voucher->credit_line_id, 'distributor_id' => $this->voucher->distributor_id, 'status' => 'ACTIVE', 'base_total' => '20000.0000', 'tolerance_amount' => '500.0000', 'configuration_version_id' => $version->id, 'source_id' => (string) Str::uuid()]);
        Sanctum::actingAs($this->cashier);
        $release = $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/release", ['lock_version' => 1])->assertSuccessful();
        $this->assertSame('ACTIVE', $restriction->fresh()->status->value);
        $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/cash", ['bank_transaction_number' => '202608210005', 'lock_version' => $release->json('data.lock_version')])->assertSuccessful();
        $this->assertSame('CONSUMED', $restriction->fresh()->status->value);

        $other = $this->voucher->replicate(['id', 'folio', 'created_at', 'updated_at']);
        $other->id = (string) Str::uuid();
        $other->folio = 'VAL-2026-99999998';
        $other->status = EstadoVale::LIBERADO;
        $other->lock_version = 1;
        $other->save();
        $this->postIdempotent("/api/v1/cashier/vouchers/{$other->id}/cash", ['bank_transaction_number' => '202608210005', 'lock_version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'BANK_TRANSACTION_ALREADY_USED');
    }

    public function test_token_es_de_un_solo_uso_cajera_campo_y_cinco_minutos(): void
    {
        Sanctum::actingAs($this->cashier);
        $request = $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/modification-requests", ['fields' => ['curp'], 'changes' => ['curp' => 'GODE561231HDFABC09'], 'reason' => 'Discrepancia'])->assertCreated();
        $stored = SolicitudModificacionVale::query()->findOrFail($request->json('data.id'));
        $this->assertSame('GODE561231HDFABC09', $stored->requested_changes['curp']);
        $this->assertStringNotContainsString('GODE561231HDFABC09', (string) $stored->getRawOriginal('requested_changes'));
        $curpActual = app(ProtectorDatosCliente::class)->descifrar($stored->cliente->curp_ciphertext);
        $this->assertSame(
            app(ProtectorDatosCliente::class)->enmascarar($curpActual, 4, 3),
            $stored->changes_before['curp'],
        );
        $solicitudAuditada = AuditLog::query()->where('event_name', 'VOUCHER_MODIFICATION_REQUESTED')->where('entity_id', $stored->id)->firstOrFail();
        $this->assertSame('curp', $solicitudAuditada->new_value['changes'][0]['field']);
        $this->assertSame(
            app(ProtectorDatosCliente::class)->enmascarar('GODE561231HDFABC09', 4, 3),
            $solicitudAuditada->new_value['changes'][0]['after'],
        );
        $authority = $this->user('branch_manager', $this->branch->id);
        Sanctum::actingAs($authority);
        $this->getJson('/api/v1/voucher-modification-requests')->assertSuccessful()->assertJsonPath('data.0.requested_changes.curp', 'GODE561231HDFABC09');
        $decision = $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request->json('data.id').'/decision', ['decision' => 'AUTHORIZE', 'reason' => 'Validado', 'lock_version' => 1])->assertSuccessful();
        $this->assertNotNull($decision->json('data.token'));
        $decisionAuditada = AuditLog::query()->where('event_name', 'VOUCHER_MODIFICATION_AUTHORIZED')->where('entity_id', $stored->id)->firstOrFail();
        $this->assertSame('curp', $decisionAuditada->new_value['changes'][0]['field']);
        $this->assertSame('AUTHORIZED', $decisionAuditada->new_value['status']);
        $this->assertGreaterThanOrEqual(298, now()->diffInSeconds(CarbonImmutable::parse($decision->json('data.expires_at')), false));
        $this->assertLessThanOrEqual(300, now()->diffInSeconds(CarbonImmutable::parse($decision->json('data.expires_at')), false));
        $otherCashier = $this->user('cashier', $this->branch->id);
        Sanctum::actingAs($otherCashier);
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request->json('data.id').'/apply', ['token' => $decision->json('data.token'), 'lock_version' => 2])->assertForbidden()->assertJsonPath('error.code', 'MODIFICATION_TOKEN_ACTOR_MISMATCH');
        Sanctum::actingAs($this->cashier);
        $this->getJson("/api/v1/cashier/vouchers/{$this->voucher->id}")->assertSuccessful()->assertJsonPath('data.modification_request.status', 'AUTHORIZED');
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request->json('data.id').'/apply', ['token' => $decision->json('data.token'), 'lock_version' => 2])->assertSuccessful()->assertJsonPath('data.status', 'APPLIED');
        $stored->refresh();
        $this->assertSame(
            app(ProtectorDatosCliente::class)->enmascarar($curpActual, 4, 3),
            $stored->changes_before['curp'],
        );
        $this->assertSame(
            app(ProtectorDatosCliente::class)->enmascarar('GODE561231HDFABC09', 4, 3),
            $stored->changes_after['curp'],
        );
        $aplicacionAuditada = AuditLog::query()->where('event_name', 'VOUCHER_MODIFICATION_APPLIED')->where('entity_id', $stored->id)->firstOrFail();
        $this->assertSame('curp', $aplicacionAuditada->new_value['changes'][0]['field']);
        $this->assertSame($stored->changes_before['curp'], $aplicacionAuditada->new_value['changes'][0]['before']);
        $this->assertSame($stored->changes_after['curp'], $aplicacionAuditada->new_value['changes'][0]['after']);
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request->json('data.id').'/apply', ['token' => $decision->json('data.token'), 'lock_version' => 3])->assertStatus(409)->assertJsonPath('error.code', 'MODIFICATION_TOKEN_USED');
    }

    public function test_token_invalido_vencido_y_cambios_distintos_a_campos_solicitados_fallan(): void
    {
        Sanctum::actingAs($this->cashier);
        $address = ['street' => 'Uno', 'exterior_number' => '1', 'neighborhood' => 'Centro', 'postal_code' => '64000', 'municipality' => 'Monterrey', 'city' => 'Monterrey', 'state' => 'Nuevo León', 'country' => 'MX'];
        $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/modification-requests", ['fields' => ['curp'], 'changes' => ['address' => $address], 'reason' => 'Error'])->assertUnprocessable();
        $curpInvalida = $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/modification-requests", ['fields' => ['curp'], 'changes' => ['curp' => 'ABCD123456HABCDE@1'], 'reason' => 'CURP inválida'])
            ->assertUnprocessable();
        $this->assertSame(['La CURP debe contener exactamente 18 letras o números.'], $curpInvalida->json('error.fields')['changes.curp']);
        $codigoPostalInvalido = $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/modification-requests", ['fields' => ['address'], 'changes' => ['address' => [...$address, 'postal_code' => '64A00']], 'reason' => 'Código postal inválido'])
            ->assertUnprocessable();
        $this->assertSame(['El código postal debe tener exactamente 5 dígitos numéricos.'], $codigoPostalInvalido->json('error.fields')['changes.address.postal_code']);
        $request = $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/modification-requests", ['fields' => ['curp'], 'changes' => ['curp' => 'GODE561231HDFABC09'], 'reason' => 'Error'])->json('data');
        $authority = $this->user('branch_manager', $this->branch->id);
        Sanctum::actingAs($authority);
        $decision = $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request['id'].'/decision', ['decision' => 'AUTHORIZE', 'reason' => 'SÃ­', 'lock_version' => 1])->json('data');
        Sanctum::actingAs($this->cashier);
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request['id'].'/apply', ['token' => 'DEADBEEF', 'lock_version' => 2])->assertStatus(422)->assertJsonPath('error.code', 'MODIFICATION_TOKEN_INVALID');
        SolicitudModificacionVale::query()->whereKey($request['id'])->update(['token_expires_at' => now()->subSecond()]);
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request['id'].'/apply', ['token' => $decision['token'], 'lock_version' => 2])->assertStatus(409)->assertJsonPath('error.code', 'MODIFICATION_TOKEN_EXPIRED');
    }

    public function test_rechazar_correccion_reactiva_el_vale_y_no_permite_solicitar_el_mismo_dato(): void
    {
        Sanctum::actingAs($this->cashier);
        $request = $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/modification-requests", [
            'fields' => ['curp'],
            'changes' => ['curp' => 'GODE561231HDFABC09'],
            'reason' => 'Discrepancia visible',
        ])->assertCreated()->json('data');

        $authority = $this->user('branch_manager', $this->branch->id);
        Sanctum::actingAs($authority);
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request['id'].'/decision', [
            'decision' => 'REJECT',
            'reason' => 'Debe capturarse nuevamente',
            'lock_version' => 1,
        ])->assertSuccessful()->assertJsonPath('data.request.status', 'REJECTED');
        $this->assertSame('GENERATED', $this->voucher->fresh()->status->value);

        $cliente = $this->voucher->cliente;
        $cliente->forceFill(['curp_hmac' => app(ProtectorDatosCliente::class)->hmacCurp('GODE561231HDFABC09')])->save();
        Sanctum::actingAs($this->cashier);
        $this->getJson("/api/v1/cashier/vouchers/{$this->voucher->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'GENERATED')
            ->assertJsonPath('data.modification_request', null);
        $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/modification-requests", [
            'fields' => ['curp'],
            'changes' => ['curp' => 'GODE561231HDFABC09'],
            'reason' => 'Sin cambio real',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'MODIFICATION_NO_CHANGES');
    }

    public function test_corte_genera_una_relacion_con_parcialidades_antiguas_snapshots_y_fecha_limite(): void
    {
        $cutoff = CarbonImmutable::parse('2027-01-25 00:05:00', 'America/Monterrey');
        $this->voucher->update(['status' => 'CASHED', 'cashed_at' => now()]);
        $this->voucher->parcialidades()->createMany([
            ['number' => 1, 'capital' => '2500.0000', 'loan_commission' => '250.0000', 'interest' => '200.0000', 'insurance' => '25.0000', 'distributor_profit' => '125.0000', 'misvales_payment' => '2975.0000', 'client_payment' => '3100.0000', 'due_at' => $cutoff->subMonths(2)],
            ['number' => 2, 'capital' => '2500.0000', 'loan_commission' => '250.0000', 'interest' => '200.0000', 'insurance' => '25.0000', 'distributor_profit' => '125.0000', 'misvales_payment' => '2975.0000', 'client_payment' => '3100.0000', 'due_at' => $cutoff->addDay()],
        ]);

        $service = app(ServicioGeneracionRelacion::class);
        $this->assertSame(1, $service->generar($cutoff));
        $this->assertSame(0, $service->generar($cutoff));
        $this->assertDatabaseCount('distributor_relations', 1);
        $this->assertDatabaseCount('distributor_relation_items', 2);
        $relation = RelacionDistribuidora::query()->firstOrFail();
        $this->assertSame('6200.0000', $relation->portfolio_total);
        $this->assertSame('5950.0000', $relation->misvales_total);
        $this->assertSame('5950.0000', $relation->balance);
        $this->assertSame('CONV-TEST', $relation->bank_snapshot['agreement']);
        $this->assertArrayNotHasKey('EARLY_PAYMENT_PERIOD', $relation->header_snapshot['configuration_versions']);
        $this->assertArrayNotHasKey('points', $relation->header_snapshot);
        $this->assertSame($cutoff->utc()->toIso8601String(), $relation->advance_period_start->utc()->toIso8601String());
        $this->assertSame(
            $relation->payment_deadline_at->setTimezone('America/Monterrey')->subDay()->endOfDay()->utc()->toIso8601String(),
            $relation->advance_period_end->utc()->toIso8601String(),
        );
        $snapshot = $relation->partidas()->firstOrFail()->snapshot;
        $this->assertSame('VAL-2026-99999999', $snapshot['folio']);
        $this->assertSame('125.0000', $snapshot['distributor_profit']);
        $this->assertSame('0.050000', $snapshot['distributor_profit_percentage']);
        $this->assertSame($this->voucher->category_version_id, $snapshot['category_version_id']);
        $riskAlert = AlertaRiesgoDistribuidora::create(['distributor_id' => $relation->distributor_id, 'branch_id' => $relation->branch_id, 'type' => 'THREE_CONSECUTIVE_DEFAULTS', 'consecutive_defaults' => 3, 'relation_ids' => [$relation->id], 'overdue_balance' => $relation->balance]);
        $this->assertSame('6200.0000', $riskAlert->relation_details[0]['portfolio_total']);
        $this->assertSame('250.0000', $riskAlert->relation_details[0]['distributor_profit_total']);
        $this->assertSame('5950.0000', $riskAlert->relation_details[0]['misvales_total']);
        $this->assertDatabaseCount('relation_process_runs', 2);
    }

    public function test_corte_manual_desde_29_agosto_simula_25_septiembre_y_limite_15_octubre(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-29 12:00:00', 'America/Monterrey'));
        $this->voucher->update(['status' => 'CASHED', 'cashed_at' => now()]);
        $this->voucher->parcialidades()->createMany([
            ['number' => 1, 'capital' => '2500', 'loan_commission' => '250', 'interest' => '200', 'insurance' => '25', 'distributor_profit' => '125', 'misvales_payment' => '2975', 'client_payment' => '3100', 'due_at' => CarbonImmutable::parse('2026-09-25 00:04:00', 'America/Monterrey')],
            ['number' => 2, 'capital' => '2500', 'loan_commission' => '250', 'interest' => '200', 'insurance' => '25', 'distributor_profit' => '125', 'misvales_payment' => '2975', 'client_payment' => '3100', 'due_at' => CarbonImmutable::parse('2026-10-16 00:00:00', 'America/Monterrey')],
        ]);
        Sanctum::actingAs($this->user('general_manager'));

        $this->getJson('/api/v1/operations/current-cutoff')
            ->assertSuccessful()
            ->assertJsonPath('data.period.projected_end', '2026-09-25T06:05:00+00:00')
            ->assertJsonPath('data.summary.operations', 1);

        $response = $this->postIdempotent('/api/v1/operations/force-cutoff', ['motivo' => 'Prueba controlada del ciclo'])
            ->assertSuccessful()
            ->assertJsonPath('data.simulated_cutoff_at', '2026-09-25T06:05:00+00:00')
            ->assertJsonPath('data.payment_deadline_at', '2026-10-16T05:59:59+00:00')
            ->assertJsonPath('data.relations_generated', 1);

        $relation = RelacionDistribuidora::query()->firstOrFail();
        $this->assertSame($response->json('data.process_run_id'), $relation->process_run_id);
        $this->assertSame('2026-10-16 05:59:59', $relation->payment_deadline_at->utc()->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('distributor_relation_items', 1);
        $this->getJson('/api/v1/operations/current-cutoff')
            ->assertSuccessful()
            ->assertJsonPath('data.payment_period.summary.distributors', 1)
            ->assertJsonPath('data.payment_period.summary.operations', 1)
            ->assertJsonPath('data.payment_period.summary.total', 3100);

        $runsBeforeRetry = DB::table('relation_process_runs')->count();
        $this->postIdempotent('/api/v1/operations/force-cutoff', ['motivo' => 'No debe saltar el vencimiento'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Antes de cerrar un nuevo corte, primero vence la fecha límite del periodo actual.');
        $this->assertSame($runsBeforeRetry, DB::table('relation_process_runs')->count());
        $this->getJson('/api/v1/operations/current-cutoff')
            ->assertSuccessful()
            ->assertJsonPath('data.payment_period.process_run_id', $response->json('data.process_run_id'))
            ->assertJsonPath('data.payment_period.status', 'OPEN');
    }

    public function test_corte_repara_calendario_faltante_de_un_vale_ya_cobrado(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-29 14:00:00', 'America/Monterrey'));
        $this->voucher->forceFill([
            'status' => EstadoVale::FERIADO,
            'cashed_at' => now(),
            'cashed_by' => $this->cashier->id,
        ])->save();
        $this->voucher->parcialidades()->create([
            'number' => 1,
            'capital' => '2500',
            'loan_commission' => '250',
            'interest' => '200',
            'insurance' => '25',
            'distributor_profit' => '125',
            'misvales_payment' => '2975',
            'client_payment' => '3100',
            'due_at' => null,
        ]);

        $generated = app(ServicioGeneracionRelacion::class)->generar(
            CarbonImmutable::parse('2026-09-25 00:05:00', 'America/Monterrey'),
        );

        $this->assertSame(1, $generated);
        $this->assertSame(
            '2026-09-13 14:00:00',
            $this->voucher->parcialidades()->firstOrFail()->due_at->setTimezone('America/Monterrey')->format('Y-m-d H:i:s'),
        );
        $this->assertDatabaseCount('distributor_relations', 1);
        $this->assertDatabaseCount('distributor_relation_items', 1);
    }

    public function test_permite_nuevo_corte_y_acumula_conciliaciones_pendientes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-29 12:00:00', 'America/Monterrey'));
        $this->voucher->update(['status' => 'CASHED', 'cashed_at' => now()]);
        $this->voucher->parcialidades()->create([
            'number' => 1,
            'capital' => '100.0000',
            'loan_commission' => '10.0000',
            'interest' => '5.0000',
            'insurance' => '1.0000',
            'distributor_profit' => '4.0000',
            'misvales_payment' => '116.0000',
            'client_payment' => '120.0000',
            'due_at' => CarbonImmutable::parse('2026-09-25 00:04:00', 'America/Monterrey'),
        ]);
        Sanctum::actingAs($this->user('general_manager'));
        $runId = $this->postIdempotent('/api/v1/operations/force-cutoff', ['motivo' => 'Primer corte'])
            ->assertSuccessful()
            ->json('data.process_run_id');
        AuditLog::query()->create([
            'entity_type' => 'relation_process_run',
            'entity_id' => $runId,
            'event_name' => 'PaymentDeadlineExpired',
            'new_value' => ['expired_at' => now()->toIso8601String()],
            'result' => 'SUCCESS',
        ]);

        $runsBefore = DB::table('relation_process_runs')->count();
        $secondRunId = $this->postIdempotent('/api/v1/operations/force-cutoff', ['motivo' => 'Continuar sin conciliación anterior'])
            ->assertSuccessful()
            ->json('data.process_run_id');
        $this->assertSame($runsBefore + 1, DB::table('relation_process_runs')->count());
        AuditLog::query()->create([
            'entity_type' => 'relation_process_run',
            'entity_id' => $secondRunId,
            'event_name' => 'PaymentDeadlineExpired',
            'new_value' => ['expired_at' => now()->addMonth()->toIso8601String()],
            'result' => 'SUCCESS',
        ]);
        $periods = app(ServicioDisponibilidadConciliacion::class)->periodosPendientes();
        $this->assertSame([2, 1], array_column($periods, 'reconciliation_number'));
        $this->assertSame([$secondRunId, $runId], array_column($periods, 'process_run_id'));
    }

    public function test_relacion_incluye_una_parcialidad_segun_fecha_limite(): void
    {
        $this->materializarCalendario($this->voucher, '2026-09-23 10:00:00', 8);

        app(ServicioGeneracionRelacion::class)->generar($this->corteSeptiembre());

        $this->assertSame([1], $this->numerosRelacionados($this->voucher));
    }

    public function test_vale_feriado_24_octubre_entra_al_corte_25_octubre_con_una_parcialidad(): void
    {
        $vale = $this->materializarCalendario($this->voucher, '2026-10-24 10:00:00', 8);

        app(ServicioGeneracionRelacion::class)->generar(
            CarbonImmutable::parse('2026-10-25 00:05:00', 'America/Monterrey'),
        );

        $relation = RelacionDistribuidora::query()->firstOrFail();
        $this->assertSame([1], $this->numerosRelacionados($vale, $relation));
        $this->assertSame('2026-11-14', $relation->payment_deadline_at->setTimezone('America/Monterrey')->format('Y-m-d'));
    }

    public function test_vale_feriado_26_octubre_entra_al_corte_25_noviembre_hasta_limite_15_diciembre(): void
    {
        $vale = $this->materializarCalendario($this->voucher, '2026-10-26 10:00:00', 8);

        app(ServicioGeneracionRelacion::class)->generar(
            CarbonImmutable::parse('2026-11-25 00:05:00', 'America/Monterrey'),
        );

        $relation = RelacionDistribuidora::query()->firstOrFail();
        $this->assertSame([1, 2, 3], $this->numerosRelacionados($vale, $relation));
        $this->assertSame(
            ['2026-11-10', '2026-11-25', '2026-12-10'],
            $relation->partidas->map(
                fn ($item): string => $item->installment->due_at->setTimezone('America/Monterrey')->format('Y-m-d'),
            )->all(),
        );
        $this->assertSame('2026-12-15', $relation->payment_deadline_at->setTimezone('America/Monterrey')->format('Y-m-d'));
    }

    public function test_forzar_corte_del_25_noviembre_respeta_el_vale_feriado_26_octubre(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'America/Monterrey'));
        $vale = $this->materializarCalendario($this->voucher, '2026-10-26 10:00:00', 8);
        Sanctum::actingAs($this->user('general_manager'));

        $this->postIdempotent('/api/v1/operations/force-cutoff', ['motivo' => 'Validar ventana quincenal'])
            ->assertSuccessful()
            ->assertJsonPath('data.simulated_cutoff_at', '2026-11-25T06:05:00+00:00')
            ->assertJsonPath('data.payment_deadline_at', '2026-12-16T05:59:59+00:00');

        $relation = RelacionDistribuidora::query()->firstOrFail();
        $this->assertSame([1, 2, 3], $this->numerosRelacionados($vale, $relation));
    }

    public function test_relacion_incluye_dos_parcialidades_segun_fecha_limite(): void
    {
        $this->materializarCalendario($this->voucher, '2026-09-10 10:00:00', 8);

        app(ServicioGeneracionRelacion::class)->generar($this->corteSeptiembre());

        $this->assertSame([1, 2], $this->numerosRelacionados($this->voucher));
    }

    public function test_relacion_incluye_tres_parcialidades_del_ejemplo_obligatorio(): void
    {
        $this->materializarCalendario($this->voucher, '2026-08-29 10:00:00', 8);

        app(ServicioGeneracionRelacion::class)->generar($this->corteSeptiembre());

        $this->assertSame([1, 2, 3], $this->numerosRelacionados($this->voucher));
        $this->assertSame(
            ['2026-09-13', '2026-09-28', '2026-10-13', '2026-10-28', '2026-11-12', '2026-11-27', '2026-12-12', '2026-12-27'],
            $this->voucher->parcialidades->map(
                fn ($installment): string => $installment->due_at->setTimezone('America/Monterrey')->format('Y-m-d'),
            )->all(),
        );
    }

    public function test_vales_de_una_distribuidora_materializan_calendarios_desde_feriados_distintos(): void
    {
        $valeA = $this->materializarCalendario($this->voucher, '2026-08-29 10:00:00', 8);
        $valeB = $this->materializarCalendario($this->clonarVale('VAL-CALENDAR-B'), '2026-09-10 10:00:00', 8);
        $valeC = $this->materializarCalendario($this->clonarVale('VAL-CALENDAR-C'), '2026-09-23 10:00:00', 8);

        $this->assertSame('2026-09-13', $valeA->parcialidades()->firstOrFail()->due_at->setTimezone('America/Monterrey')->format('Y-m-d'));
        $this->assertSame('2026-09-25', $valeB->parcialidades()->firstOrFail()->due_at->setTimezone('America/Monterrey')->format('Y-m-d'));
        $this->assertSame('2026-10-08', $valeC->parcialidades()->firstOrFail()->due_at->setTimezone('America/Monterrey')->format('Y-m-d'));
    }

    public function test_misma_relacion_recibe_uno_dos_y_tres_parcialidades_segun_cada_vale(): void
    {
        $valeA = $this->materializarCalendario($this->voucher, '2026-08-29 10:00:00', 8);
        $valeB = $this->materializarCalendario($this->clonarVale('VAL-RELATION-B'), '2026-09-10 10:00:00', 8);
        $valeC = $this->materializarCalendario($this->clonarVale('VAL-RELATION-C'), '2026-09-23 10:00:00', 8);

        app(ServicioGeneracionRelacion::class)->generar($this->corteSeptiembre());

        $this->assertDatabaseCount('distributor_relations', 1);
        $this->assertSame([1, 2, 3], $this->numerosRelacionados($valeA));
        $this->assertSame([1, 2], $this->numerosRelacionados($valeB));
        $this->assertSame([1], $this->numerosRelacionados($valeC));
    }

    public function test_vale_de_ocho_quincenas_nunca_genera_mas_de_ocho(): void
    {
        $this->materializarCalendario($this->voucher, '2026-08-29 10:00:00', 8);

        app(ServicioGeneracionRelacion::class)->generar(
            CarbonImmutable::parse('2026-12-25 00:05:00', 'America/Monterrey'),
        );

        $this->assertSame(range(1, 8), $this->numerosRelacionados($this->voucher));
        $this->assertSame(8, $this->voucher->parcialidades()->count());
    }

    public function test_una_parcialidad_nunca_aparece_en_dos_relaciones(): void
    {
        $this->materializarCalendario($this->voucher, '2026-08-29 10:00:00', 8);
        $service = app(ServicioGeneracionRelacion::class);
        $service->generar($this->corteSeptiembre());
        $primera = RelacionDistribuidora::query()->firstOrFail();
        $this->marcarCorteComoConciliado($primera);
        $service->generar(CarbonImmutable::parse('2026-10-25 00:05:00', 'America/Monterrey'));
        $segunda = RelacionDistribuidora::query()->latest('cutoff_at')->firstOrFail();

        $this->assertSame([1, 2, 3], $this->numerosRelacionados($this->voucher, $primera));
        $this->assertSame([4, 5], $this->numerosRelacionados($this->voucher, $segunda));
        $this->assertSame(5, RelacionPartidaDistribuidora::query()->distinct('voucher_installment_id')->count('voucher_installment_id'));
    }

    public function test_reintentar_mismo_corte_no_duplica_relacion_partidas_ni_calendario(): void
    {
        $this->materializarCalendario($this->voucher, '2026-08-29 10:00:00', 8);
        $service = app(ServicioGeneracionRelacion::class);

        $this->assertSame(1, $service->generar($this->corteSeptiembre()));
        $this->assertSame(0, $service->generar($this->corteSeptiembre()));

        $this->assertDatabaseCount('distributor_relations', 1);
        $this->assertDatabaseCount('distributor_relation_items', 1);
        $this->assertSame(8, $this->voucher->parcialidades()->count());
    }

    public function test_seis_cortes_forzados_reales_llegan_a_maria_seis_elena_cuatro_y_jose_uno_sin_duplicar(): void
    {
        Storage::fake('local');
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-08-27 12:00:00', 'America/Monterrey'));
        $manager = $this->user('general_manager');

        $maria = $this->voucher;
        $maria->cliente->forceFill(['first_name' => 'María', 'first_last_name' => 'Prueba'])->save();
        $this->materializarCalendario($maria, '2026-08-31 00:05:00', 8);

        $elena = null;
        $jose = null;
        $expected = [
            [[1], [], []],
            [[2], [], []],
            [[3], [1], []],
            [[4], [2], []],
            [[5], [3], []],
            [[6], [4], [1]],
        ];

        foreach (range(1, 6) as $cycle) {
            $index = $cycle - 1;
            if ($index === 2) {
                $elena = $this->clonarValeParaCliente('VAL-ELENA-8', 'Elena');
                $this->materializarCalendario($elena, '2026-11-24 00:05:00', 8);
            }
            if ($index === 5) {
                $jose = $this->clonarValeParaCliente('VAL-JOSE-8', 'José');
                $this->materializarCalendario($jose, '2027-02-24 00:05:00', 8);
            }

            Sanctum::actingAs($manager);
            $idempotencyKey = (string) Str::uuid();
            $response = $this->withHeader('Idempotency-Key', $idempotencyKey)
                ->postJson('/api/v1/operations/force-cutoff', [
                    'motivo' => 'Escenario controlado corte '.($index + 1),
                ])
                ->assertSuccessful()
                ->assertJsonPath('data.relations_generated', 1);

            $relation = RelacionDistribuidora::query()
                ->where('process_run_id', $response->json('data.process_run_id'))
                ->firstOrFail();
            $this->assertSame($expected[$index][0], $this->numerosRelacionados($maria, $relation));
            $this->assertSame($expected[$index][1], $elena === null ? [] : $this->numerosRelacionados($elena, $relation));
            $this->assertSame($expected[$index][2], $jose === null ? [] : $this->numerosRelacionados($jose, $relation));

            if ($index === 5) {
                $relationsBeforeReplay = RelacionDistribuidora::query()->count();
                $itemsBeforeReplay = RelacionPartidaDistribuidora::query()->count();
                $this->withHeader('Idempotency-Key', $idempotencyKey)
                    ->postJson('/api/v1/operations/force-cutoff', [
                        'motivo' => 'Escenario controlado corte 6',
                    ])
                    ->assertSuccessful()
                    ->assertJsonPath('data.process_run_id', $relation->process_run_id);
                $this->assertSame($relationsBeforeReplay, RelacionDistribuidora::query()->count());
                $this->assertSame($itemsBeforeReplay, RelacionPartidaDistribuidora::query()->count());
                break;
            }

            $this->pagarConciliarYCerrarCiclo($relation, $index + 1, $manager);
        }

        $this->assertSame(8, $maria->parcialidades()->count());
        $this->assertSame(8, $elena?->parcialidades()->count());
        $this->assertSame(8, $jose?->parcialidades()->count());
        $this->assertSame(
            RelacionPartidaDistribuidora::query()->count(),
            RelacionPartidaDistribuidora::query()->distinct()->count('voucher_installment_id'),
        );
    }

    public function test_corte_forzado_toma_solo_la_siguiente_parcialidad_de_cada_vale(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-29 10:00:00', 'America/Monterrey'));
        $vale = $this->materializarCalendario($this->voucher, '2026-08-29 10:00:00', 8);
        Sanctum::actingAs($this->user('general_manager'));
        $this->postIdempotent('/api/v1/operations/force-cutoff', ['motivo' => 'Comparar con corte normal'])
            ->assertSuccessful()
            ->assertJsonPath('data.simulated_cutoff_at', '2026-09-25T06:05:00+00:00');

        $this->assertSame([1], $this->numerosRelacionados($vale));
    }

    public function test_resumen_de_corte_no_exige_banco_pero_forzar_corte_si_lo_exige(): void
    {
        $bankDefinition = ConfigurationDefinition::query()
            ->where('key', 'RELATION_PAYMENT_BANK')
            ->firstOrFail();
        $bankDefinition->versions()->delete();
        Cache::forget('configuracion:RELATION_PAYMENT_BANK');

        Sanctum::actingAs($this->user('general_manager'));

        $this->getJson('/api/v1/operations/current-cutoff')
            ->assertSuccessful()
            ->assertJsonPath('data.projected_status', 'OPEN');

        $this->postIdempotent('/api/v1/operations/force-cutoff', ['motivo' => 'Validar configuración'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Falta configuración en el sistema (horarios/días) para poder generar el corte.');
    }

    public function test_cambiar_configuracion_no_recalcula_relacion_historica(): void
    {
        $this->materializarCalendario($this->voucher, '2026-08-29 10:00:00', 8);
        app(ServicioGeneracionRelacion::class)->generar($this->corteSeptiembre());
        $relation = RelacionDistribuidora::query()->firstOrFail();
        $deadline = $relation->payment_deadline_at->toIso8601String();
        $items = $relation->partidas()->pluck('voucher_installment_id')->all();

        $definition = ConfigurationDefinition::query()->where('key', 'PAYMENT_DAYS_AFTER_CUT')->firstOrFail();
        $definition->versions()->firstOrFail()->forceFill(['value' => 40])->save();
        Cache::forget('configuracion:PAYMENT_DAYS_AFTER_CUT');

        $relation->refresh();
        $this->assertSame($deadline, $relation->payment_deadline_at->toIso8601String());
        $this->assertSame($items, $relation->partidas()->pluck('voucher_installment_id')->all());
        $this->assertSame([1, 2, 3], $this->numerosRelacionados($this->voucher, $relation));
    }

    public function test_siguiente_corte_continua_desde_las_parcialidades_siguientes(): void
    {
        $this->materializarCalendario($this->voucher, '2026-08-29 10:00:00', 8);
        $service = app(ServicioGeneracionRelacion::class);
        $service->generar($this->corteSeptiembre());
        $this->marcarCorteComoConciliado(RelacionDistribuidora::query()->firstOrFail());
        $service->generar(CarbonImmutable::parse('2026-10-25 00:05:00', 'America/Monterrey'));
        $relations = RelacionDistribuidora::query()->orderBy('cutoff_at')->get();

        $this->assertSame([1, 2, 3], $this->numerosRelacionados($this->voucher, $relations[0]));
        $this->assertSame([4, 5], $this->numerosRelacionados($this->voucher, $relations[1]));
    }

    public function test_saldo_pendiente_se_traslada_una_sola_vez_y_la_relacion_anterior_queda_historica(): void
    {
        $this->materializarCalendario($this->voucher, '2026-08-29 10:00:00', 8);
        $service = app(ServicioGeneracionRelacion::class);

        $service->generar($this->corteSeptiembre());
        $first = RelacionDistribuidora::query()->firstOrFail();
        $this->assertSame('348.0000', $first->balance);

        $this->marcarCorteComoConciliado($first);
        $service->generar(CarbonImmutable::parse('2026-10-25 00:05:00', 'America/Monterrey'));
        $first->refresh();
        $second = RelacionDistribuidora::query()->latest('cutoff_at')->firstOrFail();

        $this->assertSame('ROLLED_FORWARD', $first->financial_status);
        $this->assertSame('0.0000', $first->balance);
        $this->assertSame('348.0000', $first->rolled_forward_amount);
        $this->assertSame($second->id, $first->rolled_forward_to_id);
        $this->assertSame('348.0000', $second->carried_balance);
        $this->assertSame('580.0000', $second->balance);

        $this->marcarCorteComoConciliado($second);
        $service->generar(CarbonImmutable::parse('2026-11-25 00:05:00', 'America/Monterrey'));
        $second->refresh();
        $third = RelacionDistribuidora::query()->latest('cutoff_at')->firstOrFail();

        $this->assertSame('ROLLED_FORWARD', $second->financial_status);
        $this->assertSame('580.0000', $second->rolled_forward_amount);
        $this->assertSame('580.0000', $third->carried_balance);
        $this->assertSame('812.0000', $third->balance);
        $this->assertSame(7, RelacionPartidaDistribuidora::query()->count());
    }

    public function test_genera_nueva_relacion_con_adeudo_aunque_ya_no_haya_parcialidades_nuevas(): void
    {
        $this->materializarCalendario($this->voucher, '2026-04-01 10:00:00', 8);
        $service = app(ServicioGeneracionRelacion::class);

        $service->generar($this->corteSeptiembre());
        $first = RelacionDistribuidora::query()->firstOrFail();
        $originalItems = $first->partidas()->count();
        $this->assertSame(8, $originalItems);

        $this->marcarCorteComoConciliado($first);
        $generated = $service->generar(CarbonImmutable::parse('2026-10-25 00:05:00', 'America/Monterrey'));
        $second = RelacionDistribuidora::query()->latest('cutoff_at')->firstOrFail();

        $this->assertSame(1, $generated);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(0, $second->partidas()->count());
        $this->assertSame($first->rolled_forward_amount, $second->carried_balance);
        $this->assertSame($second->carried_balance, $second->balance);
        $this->assertSame($originalItems, RelacionPartidaDistribuidora::query()->count());
    }

    public function test_ocurrencia_terminal_maria_es_asterisco_ocho_de_ocho_e_idempotente(): void
    {
        $this->voucher->forceFill([
            'fortnights_count' => 8,
            'client_payment_per_fortnight' => '1887.0000',
            'misvales_payment_per_fortnight' => '1812.0000',
            'distributor_profit_per_fortnight' => '75.0000',
        ])->save();
        $this->materializarCalendario($this->voucher, '2026-04-01 10:00:00', 8);
        $service = app(ServicioGeneracionRelacion::class);
        $service->generar($this->corteSeptiembre());
        $original = RelacionDistribuidora::query()->firstOrFail();
        $finalInstallment = $this->voucher->parcialidades()->where('number', 8)->firstOrFail();
        $finalItem = $original->partidas()->firstOrFail();
        $snapshot = $finalItem->snapshot;
        $snapshot['installment'] = 8;
        $snapshot['total_installments'] = 8;
        $snapshot['base_payment'] = '1887.0000';
        $snapshot['client_payment'] = '1887.0000';
        $snapshot['misvales_payment'] = '1812.0000';
        $snapshot['distributor_profit'] = '999.0000';
        $finalItem->forceFill(['voucher_installment_id' => $finalInstallment->id, 'source_voucher_installment_id' => $finalInstallment->id, 'snapshot' => $snapshot, 'portfolio_amount' => '1887.0000', 'misvales_amount' => '1812.0000'])->save();
        $this->voucher->parcialidades()->whereKeyNot($finalInstallment->id)->delete();
        $original->forceFill([
            'financial_status' => 'OVERDUE', 'carried_surcharge' => '2100.0000',
            'carried_interest' => '8000.0000', 'carried_insurance' => '168.0000',
            'carried_commission' => '1041.0000', 'carried_capital' => '4000.0000',
            'carried_balance' => '15309.0000', 'surcharge_total' => '2400.0000',
            'misvales_total' => '17121.0000', 'balance' => '17421.0000',
        ])->save();
        DB::table('relation_late_fees')->insert([
            'id' => (string) Str::uuid(), 'relation_id' => $original->id, 'type' => 'LATE_FEE',
            'amount' => '300.0000', 'applied_at' => now(), 'configuration_snapshot' => json_encode(['late_fee' => ['amount' => '300.0000']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->marcarCorteComoConciliado($original);

        $nextCutoff = CarbonImmutable::parse('2026-10-25 00:05:00', 'America/Monterrey');
        self::assertSame(1, $service->generar($nextCutoff));
        self::assertSame(0, $service->generar($nextCutoff));
        $next = RelacionDistribuidora::query()->latest('cutoff_at')->firstOrFail();
        $terminal = $next->partidas()->where('occurrence_type', 'TERMINAL_OVERDUE')->sole();

        self::assertSame('17496.0000', $next->balance);
        self::assertSame('TERMINAL_OVERDUE', $terminal->occurrence_type);
        self::assertNull($terminal->voucher_installment_id);
        self::assertSame($finalItem->voucher_installment_id, $terminal->source_voucher_installment_id);
        self::assertSame(1, $terminal->terminal_sequence);
        self::assertSame('75.0000', $terminal->snapshot['terminal_charge']);
        self::assertSame('8', (string) $terminal->snapshot['installment']);
        self::assertDatabaseCount('distributor_relation_items', 2);
        self::assertDatabaseMissing('distributor_relation_items', ['snapshot->installment' => 9]);

        $next->forceFill(['financial_status' => 'OVERDUE', 'surcharge_total' => '2700.0000', 'balance' => '17796.0000'])->save();
        DB::table('relation_late_fees')->insert([
            'id' => (string) Str::uuid(), 'relation_id' => $next->id, 'type' => 'LATE_FEE',
            'amount' => '300.0000', 'applied_at' => now(), 'configuration_snapshot' => json_encode(['late_fee' => ['amount' => '300.0000']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->marcarCorteComoConciliado($next);
        $thirdCutoff = CarbonImmutable::parse('2026-11-25 00:05:00', 'America/Monterrey');
        self::assertSame(1, $service->generar($thirdCutoff));
        $third = RelacionDistribuidora::query()->latest('cutoff_at')->firstOrFail();
        $secondTerminal = $third->partidas()->where('occurrence_type', 'TERMINAL_OVERDUE')->sole();
        self::assertSame('17871.0000', $third->balance);
        self::assertSame(2, $secondTerminal->terminal_sequence);
        self::assertSame($terminal->id, $secondTerminal->previous_terminal_occurrence_id);
        self::assertSame('75.0000', $secondTerminal->snapshot['terminal_charge']);
        self::assertDatabaseCount('distributor_relation_items', 3);
        self::assertDatabaseMissing('distributor_relation_items', ['snapshot->installment' => 9]);

        Sanctum::actingAs($this->distributorUser);
        $this->getJson('/api/v1/relations/'.$third->id)
            ->assertSuccessful()
            ->assertJsonPath('data.partidas.0.occurrence_type', 'TERMINAL_OVERDUE')
            ->assertJsonPath('data.partidas.0.terminal_sequence', 2)
            ->assertJsonPath('data.partidas.0.previous_terminal_occurrence_id', $terminal->id)
            ->assertJsonPath('data.partidas.0.snapshot.installment', 8)
            ->assertJsonPath('data.partidas.0.snapshot.total_installments', 8);
    }

    public function test_genera_y_repara_ocurrencia_terminal_aplicando_recargo_canonico_antes_del_rollover(): void
    {
        $this->voucher->forceFill([
            'fortnights_count' => 8,
            'client_payment_per_fortnight' => '1887.0000',
            'misvales_payment_per_fortnight' => '1812.0000',
            'distributor_profit_per_fortnight' => '75.0000',
        ])->save();
        $this->materializarCalendario($this->voucher, '2026-04-01 10:00:00', 8);
        $service = app(ServicioGeneracionRelacion::class);
        $service->generar($this->corteSeptiembre());
        $original = RelacionDistribuidora::query()->firstOrFail();
        $finalInstallment = $this->voucher->parcialidades()->where('number', 8)->firstOrFail();
        $finalItem = $original->partidas()->firstOrFail();
        $snapshot = array_merge($finalItem->snapshot, [
            'installment' => 8,
            'total_installments' => 8,
            'base_payment' => '1887.0000',
            'client_payment' => '1887.0000',
            'misvales_payment' => '1812.0000',
        ]);
        $finalItem->forceFill([
            'voucher_installment_id' => $finalInstallment->id,
            'source_voucher_installment_id' => $finalInstallment->id,
            'snapshot' => $snapshot,
            'portfolio_amount' => '1887.0000',
            'misvales_amount' => '1812.0000',
        ])->save();
        $this->voucher->parcialidades()->whereKeyNot($finalInstallment->id)->delete();
        $original->forceFill([
            'financial_status' => 'PENDING',
            'carried_surcharge' => '2100.0000',
            'carried_interest' => '8000.0000',
            'carried_insurance' => '168.0000',
            'carried_commission' => '1041.0000',
            'carried_capital' => '4000.0000',
            'carried_balance' => '15309.0000',
            'surcharge_total' => '2100.0000',
            'misvales_total' => '17121.0000',
            'balance' => '17121.0000',
        ])->save();
        $this->assertDatabaseMissing('relation_late_fees', ['relation_id' => $original->id]);

        $nextCutoff = CarbonImmutable::parse('2026-10-25 00:05:00', 'America/Monterrey');
        self::assertSame(1, $service->generar($nextCutoff));
        $next = RelacionDistribuidora::query()->latest('cutoff_at')->firstOrFail();
        $terminal = $next->partidas()->where('occurrence_type', 'TERMINAL_OVERDUE')->sole();
        self::assertSame(1, $terminal->terminal_sequence);
        self::assertSame('75.0000', $terminal->snapshot['terminal_charge']);
        self::assertSame('17496.0000', $next->balance);

        $terminal->delete();
        $next->forceFill([
            'portfolio_total' => bcsub((string) $next->portfolio_total, '75.0000', 4),
            'misvales_total' => bcsub((string) $next->misvales_total, '75.0000', 4),
            'balance' => bcsub((string) $next->balance, '75.0000', 4),
        ])->save();

        $this->artisan('relations:repair-terminal-occurrences', ['relation' => $next->id])
            ->expectsOutputToContain('Reparación completada: 1 ocurrencia(s) terminal(es)')
            ->assertSuccessful();
        $this->artisan('relations:repair-terminal-occurrences', ['relation' => $next->id])
            ->expectsOutput('La relación ya estaba reparada o no tiene ocurrencias terminales pendientes.')
            ->assertSuccessful();

        $next->refresh();
        $terminal = $next->partidas()->where('occurrence_type', 'TERMINAL_OVERDUE')->sole();
        self::assertSame('17496.0000', $next->balance);
        self::assertSame(1, $terminal->terminal_sequence);
        self::assertNull($terminal->previous_terminal_occurrence_id);
        self::assertDatabaseCount('distributor_relation_items', 2);

        Sanctum::actingAs($this->distributorUser);
        $this->getJson('/api/v1/relations/'.$next->id)
            ->assertSuccessful()
            ->assertJsonPath('data.partidas.0.occurrence_type', 'TERMINAL_OVERDUE')
            ->assertJsonPath('data.partidas.0.terminal_sequence', 1)
            ->assertJsonPath('data.partidas.0.previous_terminal_occurrence_id', null)
            ->assertJsonPath('data.partidas.0.voucher_installment_id', null)
            ->assertJsonPath('data.partidas.0.source_voucher_installment_id', $finalInstallment->id)
            ->assertJsonPath('data.partidas.0.snapshot.is_terminal_overdue_cycle', true);
    }

    public function test_reproduce_toda_la_progresion_de_cuatro_vales_y_genera_segundo_terminal(): void
    {
        $maria = $this->valeEscenarioFinanciero('VAL-TABLA-MARIA', 'Maria', '2026-09-01 10:00:00', '1887.0000', '1812.0000', '75.0000', '1250.0000', '500.0000');
        $luis = $this->valeEscenarioFinanciero('VAL-TABLA-LUIS', 'Luis', '2026-12-01 10:00:00', '2825.0000', '2712.0000', '113.0000', '1875.0000', '750.0000');
        $gabriela = $this->valeEscenarioFinanciero('VAL-TABLA-GABRIELA', 'Gabriela', '2027-03-01 10:00:00', '950.0000', '912.0000', '38.0000', '625.0000', '250.0000');
        $feliz = $this->valeEscenarioFinanciero('VAL-TABLA-FELIZ', 'Feliz', '2027-04-01 10:00:00', '950.0000', '912.0000', '38.0000', '625.0000', '250.0000');
        $service = app(ServicioGeneracionRelacion::class);
        $balances = app(ServicioSaldoValeRelacion::class);
        $cutoffs = [
            '2026-09-25 00:05:00', '2026-10-25 06:05:00', '2026-11-25 12:05:00',
            '2026-12-25 18:05:00', '2027-01-26 00:05:00', '2027-02-26 06:05:00',
            '2027-03-26 12:05:00', '2027-04-26 18:05:00', '2027-05-27 00:05:00',
        ];
        $expected = [
            $maria->id => ['1812.0000', '3999.0000', '6186.0000', '8373.0000', '10560.0000', '12747.0000', '14934.0000', '17121.0000', '17496.0000'],
            $luis->id => [3 => '2712.0000', 4 => '5837.0000', 5 => '8962.0000', 6 => '12087.0000', 7 => '15212.0000', 8 => '18337.0000'],
            $gabriela->id => [6 => '912.0000', 7 => '2162.0000', 8 => '3412.0000'],
            $feliz->id => [7 => '912.0000', 8 => '2162.0000'],
        ];

        foreach ($cutoffs as $index => $cutoff) {
            self::assertSame(1, $service->generar(CarbonImmutable::parse($cutoff, 'America/Monterrey')));
            $relation = RelacionDistribuidora::query()->latest('cutoff_at')->firstOrFail();
            $positions = $balances->posiciones($relation);
            foreach ($expected as $voucherId => $series) {
                if (array_key_exists($index, $series)) {
                    self::assertSame($series[$index], $positions[$voucherId]['balance'], "Saldo incorrecto para vale {$voucherId} en corte ".($index + 1));
                }
            }
        }

        $current = RelacionDistribuidora::query()->latest('cutoff_at')->firstOrFail();
        self::assertSame('PENDING', $current->financial_status);
        self::assertSame('41407.0000', $current->misvales_total);
        self::assertSame('41407.0000', $current->balance);
        self::assertSame('4800.0000', $current->carried_surcharge);
        self::assertSame('4800.0000', $current->surcharge_total);
        $summaries = $balances->resumenes($current);
        self::assertSame(
            ['17496.0000', '18337.0000', '3412.0000', '2162.0000'],
            collect($summaries)->pluck('cumulative_misvales_due')->all(),
        );
        self::assertSame('41407.0000', collect($summaries)->reduce(
            fn (string $total, array $summary): string => bcadd($total, $summary['cumulative_misvales_due'], 4),
            '0.0000',
        ));
        $mariaSummary = collect($summaries)->firstWhere('voucher_id', $maria->id);
        self::assertSame('2400.0000', $mariaSummary['cumulative_surcharge']);
        self::assertSame('525.0000', $mariaSummary['cumulative_forfeited_profit']);
        self::assertSame(
            ['1812.0000', '3999.0000', '6186.0000', '8373.0000', '10560.0000', '12747.0000', '14934.0000', '17121.0000', '17496.0000'],
            collect($mariaSummary['occurrences'])->pluck('cumulative_misvales_due')->all(),
        );
        Sanctum::actingAs($this->distributorUser);
        $this->getJson('/api/v1/relations/'.$current->id)
            ->assertSuccessful()
            ->assertJsonPath('data.voucher_balance_total', '41407.0000')
            ->assertJsonPath('data.voucher_summaries.0.cumulative_misvales_due', '17496.0000')
            ->assertJsonPath('data.voucher_summaries.1.cumulative_misvales_due', '18337.0000')
            ->assertJsonPath('data.voucher_summaries.2.cumulative_misvales_due', '3412.0000')
            ->assertJsonPath('data.voucher_summaries.3.cumulative_misvales_due', '2162.0000');
        $firstTerminal = $current->partidas()->where('occurrence_type', 'TERMINAL_OVERDUE')->sole();
        self::assertSame(1, $firstTerminal->terminal_sequence);

        $statementService = app(ServicioPdfEstadoCuenta::class);
        $statement = $statementService->preparar($current->distribuidora);
        self::assertCount(9, $statement['cuts']);
        self::assertSame('43477.3500', $statement['general']['client_collection']);
        self::assertSame('2070.3500', $statement['general']['commission']);
        self::assertSame('41407.0000', $statement['general']['misvales_payment']);
        self::assertSame('4800.0000', $statement['general']['surcharge']);
        self::assertSame('41407.0000', $statement['general']['total']);
        self::assertSame('41407.0000', $statement['general']['outstanding']);
        self::assertSame('5000.0000', $statement['credit']['used']);
        self::assertSame('41407.0000', collect($statement['cuts'])->last()['subtotal']['total']);
        self::assertSame(range(1, 9), collect($statement['cuts'])->map(
            fn (array $cut): int => collect($cut['clients'])->flatMap(fn (array $client) => $client['rows'])
                ->where('folio', 'VAL-TABLA-MARIA')->count(),
        )->all());
        $mariaRows = collect($statement['cuts'])->last()['clients'];
        $mariaRows = collect($mariaRows)->flatMap(fn (array $client) => $client['rows'])
            ->where('folio', 'VAL-TABLA-MARIA')->values();
        self::assertSame(['1/8', '2/8', '3/8', '4/8', '5/8', '6/8', '7/8', '8/8', '*8/8'], $mariaRows->pluck('installment')->all());
        self::assertSame(
            ['1812.0000', '3999.0000', '6186.0000', '8373.0000', '10560.0000', '12747.0000', '14934.0000', '17121.0000', '17496.0000'],
            $mariaRows->pluck('cumulative_total')->all(),
        );
        self::assertSame(['90.6000', '199.9500', '309.3000', '418.6500', '528.0000', '637.3500', '746.7000', '856.0500', '874.8000'], $mariaRows->pluck('commission')->all());
        self::assertSame('18370.8000', $mariaRows->last()['client_collection']);
        self::assertSame(['Vencida', 'Vencida', 'Vencida', 'Vencida', 'Vencida', 'Vencida', 'Vencida', 'Vencida', 'Pendiente'], $mariaRows->pluck('status')->all());
        $statementHtml = view('relations.account-statement', ['statement' => $statement, 'logo' => 'data:image/jpeg;base64,'])->render();
        self::assertStringContainsString('CORTE 1', $statementHtml);
        self::assertStringContainsString('Cliente: Maria', $statementHtml);
        self::assertStringContainsString('*8/8', $statementHtml);
        self::assertStringContainsString('Subtotal cliente Maria', $statementHtml);
        self::assertStringContainsString('CORTE 9 · RESUMEN GENERAL', $statementHtml);
        self::assertStringNotContainsString('<h2>TOTAL GENERAL</h2>', $statementHtml);
        self::assertStringStartsWith('%PDF-', $statementService->generar($current->distribuidora));

        $current->forceFill([
            'financial_status' => 'OVERDUE',
            'balance' => '42607.0000',
            'surcharge_total' => '6000.0000',
        ])->save();
        DB::table('relation_late_fees')->insert([
            'id' => (string) Str::uuid(),
            'relation_id' => $current->id,
            'type' => 'LATE_FEE',
            'amount' => '1200.0000',
            'applied_at' => now(),
            'configuration_snapshot' => json_encode([
                'late_fee_unit_amount' => '300.0000',
                'late_fee_units' => 4,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $overdueStatement = $statementService->preparar($current->distribuidora);
        self::assertSame('42607.0000', $overdueStatement['general']['misvales_payment']);
        self::assertSame('6000.0000', $overdueStatement['general']['surcharge']);
        self::assertSame('42607.0000', $overdueStatement['general']['total']);
        self::assertSame('42607.0000', $overdueStatement['general']['outstanding']);
        $overdueMaria = collect(collect($overdueStatement['cuts'])->last()['clients'])
            ->first(fn (array $client): bool => collect($client['rows'])->contains('folio', 'VAL-TABLA-MARIA'));
        self::assertNotNull($overdueMaria);
        self::assertSame('17796.0000', $overdueMaria['subtotal']['total']);
        self::assertSame('2700.0000', $overdueMaria['subtotal']['surcharge']);
        $overdueHtml = view('relations.account-statement', [
            'statement' => $overdueStatement,
            'logo' => 'data:image/jpeg;base64,',
        ])->render();
        self::assertStringContainsString('Total a pagar a MisVales: $17,796.00', $overdueHtml);
        self::assertStringContainsString('Total definitivo MisVales</span><strong>$42,607.00', $overdueHtml);

        DB::table('relation_late_fees')->where('relation_id', $current->id)->delete();
        $current->forceFill([
            'financial_status' => 'PENDING',
            'balance' => '41407.0000',
            'surcharge_total' => '4800.0000',
        ])->save();

        self::assertSame(1, $service->generar(CarbonImmutable::parse('2027-06-27 06:05:00', 'America/Monterrey')));
        $next = RelacionDistribuidora::query()->latest('cutoff_at')->firstOrFail();
        $secondTerminal = $next->partidas()->where('source_voucher_installment_id', $firstTerminal->source_voucher_installment_id)->where('occurrence_type', 'TERMINAL_OVERDUE')->sole();
        self::assertSame(2, $secondTerminal->terminal_sequence);
        self::assertSame($firstTerminal->id, $secondTerminal->previous_terminal_occurrence_id);
        self::assertSame('17871.0000', $balances->posiciones($next)[$maria->id]['balance']);
        self::assertDatabaseMissing('distributor_relation_items', ['snapshot->installment' => 9]);
        $nextStatement = $statementService->preparar($next->distribuidora);
        $nextMariaRows = collect(collect($nextStatement['cuts'])->last()['clients'])
            ->flatMap(fn (array $client) => $client['rows'])->where('folio', 'VAL-TABLA-MARIA');
        self::assertSame('*8/8 sec. 2', $nextMariaRows->last()['installment']);
    }

    public function test_pago_con_referencia_historica_se_aplica_a_relacion_vigente_y_primero_al_saldo_trasladado(): void
    {
        $this->materializarCalendario($this->voucher, '2026-08-29 10:00:00', 8);
        $service = app(ServicioGeneracionRelacion::class);
        $service->generar($this->corteSeptiembre());
        $historical = RelacionDistribuidora::query()->firstOrFail();
        $this->marcarCorteComoConciliado($historical);
        $service->generar(CarbonImmutable::parse('2026-10-25 00:05:00', 'America/Monterrey'));
        $current = RelacionDistribuidora::query()->latest('cutoff_at')->firstOrFail();
        $balanceBeforePayment = (string) $current->balance;

        app(ServicioImportacionBancaria::class)->importar($this->xlsx([
            [$historical->payment_reference, '100', '2026-10-26 10:00:00', 'BANK-ROLLED-REFERENCE', 'Abono con referencia anterior'],
        ]), $this->cashier, $this->branch->id);

        $movement = MovimientoBancario::query()->where('bank_folio', 'BANK-ROLLED-REFERENCE')->firstOrFail();
        $payment = PagoRelacion::query()->where('bank_movement_id', $movement->id)->firstOrFail();
        $this->assertSame($current->id, $movement->relation_id);
        $this->assertSame($current->id, $payment->relation_id);
        $this->assertSame(bcsub($balanceBeforePayment, '100.0000', 4), $current->fresh()->balance);
        $this->assertSame('100.0000', $payment->surcharge_applied);
        $this->assertSame('0.0000', $payment->interest_applied);
        $this->assertSame('0.0000', $payment->insurance_applied);
        $this->assertSame('0.0000', $payment->commission_applied);
        $this->assertSame('0.0000', $payment->capital_applied);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_estado_cuenta_marca_abono_y_separa_saldo_utilizado_de_deuda(): void
    {
        $relation = $this->createPaymentRelation();
        app(ServicioAplicacionPago::class)->aplicarSaldoFavor(
            '100.0000', now()->toImmutable(), $relation, (string) Str::uuid(),
        );

        $statement = app(ServicioPdfEstadoCuenta::class)->preparar($relation->distribuidora);
        $lastCut = collect($statement['cuts'])->last();
        $statuses = collect($lastCut['clients'])->flatMap(fn (array $client) => $client['rows'])->pluck('status');
        $this->assertContains('Abono', $statuses);
        $this->assertSame('100.0000', $lastCut['subtotal']['paid']);
        $this->assertSame('2875.0000', $lastCut['subtotal']['total']);
        $this->assertSame('143.7500', $lastCut['subtotal']['commission']);
        $this->assertSame('5000.0000', $statement['credit']['used']);
        $this->assertSame('2875.0000', $statement['general']['outstanding']);
    }

    public function test_vale_completamente_terminado_deja_de_aportar_parcialidades(): void
    {
        $this->materializarCalendario($this->voucher, '2026-08-29 10:00:00', 3);
        $service = app(ServicioGeneracionRelacion::class);

        $this->assertSame(1, $service->generar($this->corteSeptiembre()));
        $this->marcarCorteComoConciliado(RelacionDistribuidora::query()->firstOrFail());
        $this->assertSame(0, $service->generar(CarbonImmutable::parse('2026-10-25 00:05:00', 'America/Monterrey')));
        $this->assertSame([1, 2, 3], $this->numerosRelacionados($this->voucher));
        $this->assertDatabaseCount('distributor_relations', 1);
    }

    public function test_vale_feriado_despues_del_corte_no_modifica_ese_corte_y_entra_en_el_siguiente(): void
    {
        $service = app(ServicioGeneracionRelacion::class);
        $this->assertSame(0, $service->generar($this->corteSeptiembre()));

        $this->materializarCalendario($this->voucher, '2026-09-28 10:00:00', 8);

        $this->assertSame(0, $service->generar($this->corteSeptiembre()));
        $this->assertDatabaseCount('distributor_relations', 0);
        $this->assertDatabaseCount('distributor_relation_items', 0);

        $this->assertSame(1, $service->generar(
            CarbonImmutable::parse('2026-10-25 00:05:00', 'America/Monterrey'),
        ));
        $this->assertSame([1, 2, 3], $this->numerosRelacionados($this->voucher));
        $relation = RelacionDistribuidora::query()->firstOrFail();
        $this->assertSame('2026-10-25', $relation->cutoff_at->setTimezone('America/Monterrey')->format('Y-m-d'));
        $this->assertSame('2026-11-14', $relation->payment_deadline_at->setTimezone('America/Monterrey')->format('Y-m-d'));
    }

    public function test_pago_el_dia_limite_es_puntual_y_no_anticipado(): void
    {
        $relation = $this->createPaymentRelation();
        $cutoff = CarbonImmutable::parse('2026-08-25 00:05:00', 'America/Monterrey');
        $deadline = CarbonImmutable::parse('2026-09-14 23:59:59', 'America/Monterrey');
        $relation->update([
            'cutoff_at' => $cutoff,
            'advance_period_start' => $cutoff,
            'advance_period_end' => $deadline->subDay()->endOfDay(),
            'payment_deadline_at' => $deadline,
        ]);

        app(ServicioAplicacionPago::class)->aplicarSaldoFavor(
            $relation->balance,
            $deadline->startOfDay(),
            $relation,
            (string) Str::uuid(),
        );

        $this->assertSame('SETTLED', $relation->fresh()->financial_status);
        $this->assertSame('ON_TIME', $relation->fresh()->temporal_classification);
        $statement = app(ServicioPdfEstadoCuenta::class)->preparar($relation->distribuidora);
        $lastCut = collect($statement['cuts'])->last();
        $statuses = collect($lastCut['clients'])->flatMap(fn (array $client) => $client['rows'])->pluck('status')->unique()->values()->all();
        $this->assertSame(['Liquidada'], $statuses);
        $this->assertSame('0.0000', $lastCut['subtotal']['total']);
    }

    public function test_liquidacion_anticipada_acredita_puntos_por_capital_nuevo_y_trasladado(): void
    {
        $relation = $this->createPaymentRelation();
        $relation->forceFill([
            'advance_period_end' => now()->addDay(),
            'payment_deadline_at' => now()->addDays(2),
            'carried_balance' => '8415.0000',
            'carried_interest' => '1000.0000',
            'carried_insurance' => '300.0000',
            'carried_commission' => '1035.0000',
            'carried_capital' => '6080.0000',
            'portfolio_total' => '11515.0000',
            'misvales_total' => '11390.0000',
            'balance' => '11390.0000',
        ])->save();

        app(ServicioAplicacionPago::class)->aplicarSaldoFavor(
            '11390.0000',
            now()->toImmutable(),
            $relation,
            (string) Str::uuid(),
        );

        $this->assertSame('EARLY', $relation->fresh()->temporal_classification);
        $this->assertDatabaseHas('point_accounts', ['distributor_id' => $relation->distributor_id, 'balance' => 21]);
        $this->assertDatabaseHas('point_movements', [
            'distributor_id' => $relation->distributor_id,
            'source_type' => 'EARLY_RELATION_SETTLEMENT',
            'source_id' => $relation->id,
            'points' => 21,
            'amount' => '8580.0000',
        ]);
    }

    public function test_fin_de_pago_conserva_abonos_clasifica_tres_resultados_y_no_duplica_recargos(): void
    {
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-08-21 12:00:00', 'America/Monterrey'));
        $this->voucher->update(['status' => 'CASHED', 'cashed_at' => now()]);
        $this->voucher->parcialidades()->create(['number' => 1, 'capital' => '2500', 'loan_commission' => '250', 'interest' => '200', 'insurance' => '25', 'distributor_profit' => '125', 'misvales_payment' => '2975', 'client_payment' => '3100', 'due_at' => CarbonImmutable::parse('2026-08-25 00:04:00', 'America/Monterrey')]);
        $manager = $this->user('general_manager');
        Sanctum::actingAs($manager);
        $cutoff = $this->postIdempotent('/api/v1/operations/force-cutoff', ['motivo' => 'Inicio de simulación'])->assertSuccessful()->json('data');
        $partial = RelacionDistribuidora::query()->firstOrFail();

        $this->postIdempotent('/api/v1/operations/force-payment-deadline', ['motivo' => 'Evaluación previa'])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'DEADLINE_REACHED')
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.evaluated_at', '2026-09-15T05:59:59+00:00')
            ->assertJsonPath('data.overdue_evaluation_at', '2026-09-16T05:59:59+00:00');
        $this->assertDatabaseCount('relation_late_fees', 0);
        $this->assertSame('PENDING', $partial->fresh()->financial_status);
        $this->postIdempotent('/api/v1/operations/force-payment-deadline', ['motivo' => 'Día posterior sin archivo'])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'DEFERRED');
        $this->getJson('/api/v1/operations/current-cutoff')
            ->assertSuccessful()
            ->assertJsonPath('data.payment_period.status', 'EXPIRED');

        $settledDistributor = Distribuidora::factory()->active()->create(['user_id' => User::factory()->create(['state' => 'ACTIVE'])->id, 'branch_id' => $this->branch->id]);
        $settled = $partial->replicate(['id', 'distributor_id', 'payment_reference', 'created_at', 'updated_at']);
        $settled->forceFill(['id' => (string) Str::uuid(), 'distributor_id' => $settledDistributor->id, 'payment_reference' => 'REL-TEST-SETTLED', 'portfolio_total' => '100.0000', 'misvales_total' => '100.0000', 'reconciled_total' => '100.0000', 'balance' => '0.0000', 'financial_status' => 'SETTLED', 'settled_at' => CarbonImmutable::parse('2026-09-01 10:00:00', 'America/Monterrey'), 'temporal_classification' => 'EARLY'])->save();

        $unpaidDistributor = Distribuidora::factory()->active()->create(['user_id' => User::factory()->create(['state' => 'ACTIVE'])->id, 'branch_id' => $this->branch->id]);
        $unpaid = $partial->replicate(['id', 'distributor_id', 'payment_reference', 'created_at', 'updated_at']);
        $unpaid->forceFill(['id' => (string) Str::uuid(), 'distributor_id' => $unpaidDistributor->id, 'payment_reference' => 'REL-TEST-UNPAID', 'portfolio_total' => '200.0000', 'misvales_total' => '200.0000', 'reconciled_total' => '0.0000', 'balance' => '200.0000', 'financial_status' => 'PENDING', 'settled_at' => null, 'temporal_classification' => null])->save();

        app(ServicioImportacionBancaria::class)->importar(
            $this->xlsx([[$partial->payment_reference, '1000.00', '2026-09-01 10:00:00', 'BANK-FORCED-DEADLINE-1', 'Primer abono']]),
            $this->cashier,
            $this->branch->id,
        );

        Sanctum::actingAs($manager);
        $result = $this->postIdempotent('/api/v1/operations/force-payment-deadline', ['motivo' => 'Cierre al 14 de septiembre'])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.process_run_id', $cutoff['process_run_id'])
            ->assertJsonPath('data.evaluated_at', '2026-09-16T05:59:59+00:00')
            ->assertJsonPath('data.outcomes.settled', 1)
            ->assertJsonPath('data.outcomes.partially_paid', 1)
            ->assertJsonPath('data.outcomes.unpaid', 1)
            ->assertJsonPath('data.late_fees.applied', 2)
            ->assertJsonPath('data.relations_evaluated', 3)
            ->assertJsonPath('data.notifications', 3);

        $this->assertSame('2275.0000', $partial->fresh()->balance);
        $this->assertSame('500.0000', $unpaid->fresh()->balance);
        $this->assertSame('0.0000', $settled->fresh()->balance);
        $this->assertDatabaseCount('relation_payments', 1);
        $this->assertDatabaseCount('relation_late_fees', 2);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $cutoff['process_run_id'], 'event_name' => 'ForcePaymentDeadlineCompleted', 'result' => 'SUCCESS']);

        $this->postIdempotent('/api/v1/operations/force-payment-deadline', ['motivo' => 'Reintento'])
            ->assertSuccessful()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.late_fees.applied', 2);
        $this->assertDatabaseCount('relation_late_fees', 2);
    }

    public function test_relacion_respeta_consulta_descarga_y_administrador_solo_lectura(): void
    {
        $this->voucher->update(['status' => 'CASHED', 'cashed_at' => now()]);
        $this->voucher->parcialidades()->create(['number' => 1, 'capital' => '2500.0000', 'loan_commission' => '250.0000', 'interest' => '200.0000', 'insurance' => '25.0000', 'distributor_profit' => '125.0000', 'misvales_payment' => '2975.0000', 'client_payment' => '3100.0000', 'due_at' => now()->subDay()]);
        app(ServicioGeneracionRelacion::class)->generar(CarbonImmutable::now('America/Monterrey'));
        $relation = RelacionDistribuidora::query()->firstOrFail();

        Sanctum::actingAs($this->distributorUser);
        $this->getJson('/api/v1/relations/'.$relation->id)
            ->assertSuccessful()
            ->assertJsonPath('data.portfolio_total', '3100.0000')
            ->assertJsonPath('data.misvales_total', '2975.0000')
            ->assertJsonPath('data.partidas.0.snapshot.distributor_profit', '125.0000')
            ->assertJsonPath('data.partidas.0.snapshot.category_version_id', $this->voucher->category_version_id);
        $this->getJson('/api/v1/relations')->assertSuccessful()->assertJsonPath('data.data.0.payment_reference', $relation->payment_reference);
        $this->get('/api/v1/relations/'.$relation->id.'/download')->assertSuccessful()->assertHeader('content-disposition');

        Sanctum::actingAs($this->user('admin'));
        $this->getJson('/api/v1/relations/'.$relation->id)->assertSuccessful();
        $this->get('/api/v1/relations/'.$relation->id.'/download')->assertForbidden();
    }

    public function test_corte_no_requiere_periodo_anticipado_configurable_y_falla_sin_banco_publicado(): void
    {
        ConfigurationVersion::query()
            ->whereHas('definition', fn ($query) => $query->where('key', 'RELATION_PAYMENT_BANK'))
            ->delete();
        Cache::forget('configuracion:RELATION_PAYMENT_BANK');
        $this->expectExceptionMessage('RELATION_CONFIGURATION_INCOMPLETE');
        app(ServicioGeneracionRelacion::class)->generar(CarbonImmutable::now('America/Monterrey'));
    }

    public function test_xlsx_concilia_abono_y_no_conciliado_y_reprocesa_sin_duplicar_efectos(): void
    {
        Storage::fake('local');
        $this->voucher->update(['status' => 'CASHED', 'cashed_at' => now()]);
        $this->voucher->parcialidades()->create(['number' => 1, 'capital' => '2500', 'loan_commission' => '250', 'interest' => '200', 'insurance' => '25', 'distributor_profit' => '125', 'misvales_payment' => '2975', 'client_payment' => '3100', 'due_at' => now()->subDay()]);
        app(ServicioGeneracionRelacion::class)->generar(CarbonImmutable::now('America/Monterrey'));
        $relation = RelacionDistribuidora::firstOrFail();
        $file = $this->xlsx([[$relation->payment_reference, '1000.00', '2026-08-12 10:00:00', 'BANK-001', 'Abono'], ['NO-EXISTE', '50.00', '2026-08-12 10:01:00', 'BANK-002', 'Sin referencia']]);
        $import = app(ServicioImportacionBancaria::class)->importar($file, $this->cashier, $this->branch->id);
        $this->assertSame('PROCESSED', $import->status);
        $this->assertSame(1, $import->summary['partial_payments']);
        $this->assertSame(1, $import->summary['unreconciled']);
        $this->assertSame('1975.0000', $relation->fresh()->balance);
        $this->assertDatabaseHas('relation_payments', ['interest_applied' => '200.0000', 'insurance_applied' => '25.0000', 'commission_applied' => '250.0000', 'capital_applied' => '525.0000', 'line_recovered' => '525.0000']);
        $this->assertDatabaseHas('credit_lines', ['id' => $this->voucher->credit_line_id, 'used_balance' => '4475.0000']);
        $this->assertDatabaseHas('credit_line_movements', ['type' => 'PAYMENT_RECOVERY', 'amount' => '525.0000']);
        $replayed = app(ServicioImportacionBancaria::class)->importar($file, $this->cashier, $this->branch->id);
        $this->assertSame($import->id, $replayed->id);
        $this->assertTrue($replayed->replayed);
        $this->assertDatabaseCount('relation_payments', 1);
    }

    public function test_distribuidora_simula_transferencia_y_cajera_descarga_excel_bancario(): void
    {
        $relation = $this->createPaymentRelation();
        Sanctum::actingAs($this->distributorUser);

        $created = $this->postJson('/api/v1/bank-simulations', [
            'relation_id' => $relation->id,
            'amount' => '1000.00',
            'payment_type' => 'TRANSFER',
            'concept' => 'Abono de prueba',
            'paid_at' => '2026-08-13 10:30:00',
        ])->assertCreated()
            ->assertJsonPath('data.payment_reference', $relation->payment_reference)
            ->assertJsonPath('data.amount', '1000.0000');

        $this->assertDatabaseHas('simulated_bank_transfers', [
            'id' => $created->json('data.id'),
            'branch_id' => $this->branch->id,
            'relation_id' => $relation->id,
        ]);
        $this->postJson('/api/v1/bank-simulations', [
            'relation_id' => $relation->id,
            'amount' => '1000.00',
            'payment_type' => 'COUNTER',
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['payment_type']]]);

        $otherDistributorUser = $this->user('distributor', $this->branch->id);
        Distribuidora::factory()->active()->create([
            'user_id' => $otherDistributorUser->id,
            'branch_id' => $this->branch->id,
        ]);
        Sanctum::actingAs($otherDistributorUser);
        $this->postJson('/api/v1/bank-simulations', [
            'relation_id' => $relation->id,
            'amount' => '500.00',
            'payment_type' => 'TRANSFER',
        ])->assertForbidden();
        $this->get('/api/v1/bank-simulations/'.$created->json('data.id').'/ticket')->assertForbidden();

        Sanctum::actingAs($this->cashier);
        $balanceBeforeCounterCapture = $relation->fresh()->balance;
        $counterPayment = $this->postJson('/api/v1/bank-simulations', [
            'relation_id' => $relation->id,
            'amount' => '500.00',
            'payment_type' => 'COUNTER',
        ])->assertCreated()
            ->assertJsonPath('data.payment_reference', $relation->payment_reference)
            ->assertJsonPath('data.payment_type', 'COUNTER');
        $overpaymentCapture = $this->postJson('/api/v1/bank-simulations', [
            'relation_id' => $relation->id,
            'amount' => '10000.00',
            'payment_type' => 'COUNTER',
        ])->assertCreated();
        $this->assertDatabaseMissing('relation_payments', ['relation_id' => $relation->id]);
        $this->assertSame($balanceBeforeCounterCapture, $relation->fresh()->balance);
        $this->assertDatabaseHas('simulated_bank_transfers', [
            'id' => $overpaymentCapture->json('data.id'),
            'amount' => '10000.0000',
            'payment_type' => 'COUNTER',
        ]);
        $this->get('/api/v1/bank-simulations/'.$counterPayment->json('data.id').'/ticket')
            ->assertSuccessful()
            ->assertDownload();
        AuditLog::query()->create([
            'entity_type' => 'operation_cutoff',
            'event_name' => 'ForzarCorte',
            'entity_id' => $relation->process_run_id,
            'actor_id' => $this->cashier->id,
            'result' => 'SUCCESS',
        ]);
        $this->getJson('/api/v1/bank-simulations')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'RECONCILIATION_PERIOD_NOT_AVAILABLE');
        $this->get('/api/v1/bank-simulations/export')->assertStatus(409);
        AuditLog::query()->create([
            'entity_type' => 'relation_process_run',
            'event_name' => 'PaymentDeadlineExpired',
            'entity_id' => $relation->process_run_id,
            'actor_id' => $this->cashier->id,
            'result' => 'SUCCESS',
        ]);
        $simulations = $this->getJson('/api/v1/bank-simulations')->assertSuccessful();
        $this->assertTrue(collect($simulations->json('data'))->contains(
            fn (array $item): bool => $item['concept'] === 'Abono de prueba'
        ));
        $this->get('/api/v1/bank-simulations/export')
            ->assertSuccessful()
            ->assertDownload();
        AuditLog::query()->create([
            'entity_type' => 'relation_process_run',
            'event_name' => 'ForcePaymentDeadlineCompleted',
            'entity_id' => $relation->process_run_id,
            'actor_id' => $this->cashier->id,
            'result' => 'SUCCESS',
        ]);
        $this->getJson('/api/v1/bank-simulations')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'RECONCILIATION_PERIOD_NOT_AVAILABLE');
        $this->get('/api/v1/bank-simulations/'.$created->json('data.id').'/ticket')
            ->assertUnprocessable();
    }

    public function test_pago_simulado_del_corte_vigente_aparece_en_el_excel_del_mismo_proceso(): void
    {
        $relation = $this->createPaymentRelation();
        Sanctum::actingAs($this->distributorUser);
        $this->postJson('/api/v1/bank-simulations', [
            'relation_id' => $relation->id,
            'amount' => '116.00',
            'payment_type' => 'TRANSFER',
            'concept' => 'Pago de la parcialidad vigente',
        ])->assertCreated();

        $service = app(ServicioTransferenciasBancariasSimuladas::class);
        $this->assertCount(1, $service->listar($this->branch->id, $relation->process_run_id));
        $path = $service->exportar($this->branch->id, $relation->process_run_id);
        $rows = app(LectorXlsxBancario::class)->leer($path);

        $this->assertCount(2, $rows);
        $this->assertSame($relation->payment_reference, $rows[1][2]);
        $this->assertSame('116', (string) $rows[1][3]);
    }

    public function test_estado_actual_de_credito_expone_linea_y_adeudo_sin_confundirlos(): void
    {
        $relation = $this->createPaymentRelation();
        $line = $this->distributorUser->distribuidora->lineaCredito()->firstOrFail();
        Sanctum::actingAs($this->user('branch_manager', $this->branch->id));

        $this->getJson("/api/v1/distributors/{$relation->distributor_id}/credit-line")
            ->assertSuccessful()
            ->assertJsonPath('data.total_authorized', $line->total_authorized)
            ->assertJsonPath('data.used_balance', $line->used_balance)
            ->assertJsonPath('data.available_balance', $line->saldoDisponible())
            ->assertJsonPath('data.current_debt', $relation->balance);
    }

    public function test_corte_sin_relaciones_nuevas_incluye_en_excel_pago_de_relacion_anterior(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-27 10:00:00', 'America/Monterrey'));
        $relation = $this->createPaymentRelation();
        AuditLog::query()->create([
            'entity_type' => 'relation_process_run',
            'entity_id' => $relation->process_run_id,
            'event_name' => 'PaymentDeadlineExpired',
            'new_value' => ['expired_at' => now()->toIso8601String()],
            'result' => 'SUCCESS',
        ]);
        $this->marcarCorteComoConciliado($relation);

        Sanctum::actingAs($this->distributorUser);
        $this->postJson('/api/v1/bank-simulations', [
            'relation_id' => $relation->id,
            'amount' => '116.00',
            'payment_type' => 'TRANSFER',
            'concept' => 'Pago pendiente de la relación anterior',
            'paid_at' => '2026-08-28 10:00:00',
        ])->assertCreated();

        Sanctum::actingAs($this->user('general_manager'));
        $newRunId = $this->postIdempotent('/api/v1/operations/force-cutoff', ['motivo' => 'Reflejar pagos pendientes'])
            ->assertSuccessful()
            ->assertJsonPath('data.relations_generated', 1)
            ->json('data.process_run_id');

        $this->assertNotNull($newRunId);
        $this->assertDatabaseHas('distributor_relations', ['process_run_id' => $newRunId]);
        AuditLog::query()->create([
            'entity_type' => 'relation_process_run',
            'entity_id' => $newRunId,
            'event_name' => 'PaymentDeadlineExpired',
            'new_value' => ['expired_at' => now()->toIso8601String()],
            'result' => 'SUCCESS',
        ]);

        Sanctum::actingAs($this->cashier);
        $this->getJson('/api/v1/bank-reconciliation-periods')
            ->assertSuccessful()
            ->assertJsonPath('data.0.process_run_id', $newRunId)
            ->assertJsonPath('data.0.relations', 1);
        $path = app(ServicioTransferenciasBancariasSimuladas::class)->exportar($this->branch->id, $newRunId);
        $rows = app(LectorXlsxBancario::class)->leer($path);

        $this->assertCount(2, $rows);
        $this->assertSame($relation->payment_reference, $rows[1][2]);
        $this->assertSame('116', (string) $rows[1][3]);
    }

    public function test_xlsx_del_cliente_concilia_referencia_pago_fecha_y_hora(): void
    {
        Storage::fake('local');
        $relation = $this->createPaymentRelation();
        $file = $this->xlsx([
            [1, 'Abono a referencia '.$relation->payment_reference, $relation->payment_reference, '1000.00', 'CLIENTE-001', '13/08/2026', '10:30', 'Transferencia'],
        ], ['item', 'Concepto', 'Referencia', 'Pago', 'Folio de pago', 'Fecha de pago', 'Hora', 'tipo de pago']);

        $import = app(ServicioImportacionBancaria::class)->importar($file, $this->cashier, $this->branch->id);

        $this->assertSame(1, $import->summary['partial_payments']);
        $this->assertDatabaseHas('bank_movements', [
            'payment_reference' => $relation->payment_reference,
            'bank_folio' => 'CLIENTE-001',
            'classification' => 'PARTIAL_PAYMENT',
        ]);
    }

    public function test_xlsx_sin_columna_se_rechaza_completo_y_registra_motivo(): void
    {
        Storage::fake('local');
        $file = $this->xlsx([], ['referencia de pago', 'monto', 'fecha', 'folio bancario']);
        try {
            app(ServicioImportacionBancaria::class)->importar($file, $this->cashier, $this->branch->id);
            $this->fail('DebiÃ³ fallar');
        } catch (ExcepcionConciliacion $e) {
            $this->assertSame('BANK_FILE_REQUIRED_COLUMNS_MISSING', $e->errorCode);
        }
        $this->assertDatabaseHas('bank_file_imports', ['status' => 'REJECTED', 'error' => 'BANK_FILE_REQUIRED_COLUMNS_MISSING']);
        $this->assertDatabaseCount('bank_movements', 0);
    }

    public function test_xlsx_clasifica_liquidacion_excedente_y_folio_duplicado_sin_reaplicar(): void
    {
        Storage::fake('local');
        $relation = $this->createPaymentRelation();
        $first = app(ServicioImportacionBancaria::class)->importar($this->xlsx([
            [$relation->payment_reference, '2975', '2026-08-12 10:00:00', 'BANK-EXACT', 'Liquidación exacta'],
        ]), $this->cashier, $this->branch->id);

        $this->assertSame(1, $first->summary['settlements']);
        $this->assertSame('0.0000', $relation->fresh()->balance);
        $this->assertDatabaseHas('bank_movements', [
            'bank_folio' => 'BANK-EXACT',
            'classification' => 'SETTLEMENT',
            'reconciliation_status' => 'RECONCILED',
            'balance_before' => '2975.0000',
        ]);

        $next = $this->replicatePaymentRelation($relation, 'REL-SURPLUS-001', '2000.0000');
        $second = app(ServicioImportacionBancaria::class)->importar($this->xlsx([
            [$next->payment_reference, '2500', '2026-08-12 11:00:00', 'BANK-SURPLUS-002', 'Pago con excedente'],
            [$next->payment_reference, '2500', '2026-08-12 11:01:00', 'BANK-SURPLUS-002', 'Folio repetido'],
        ]), $this->cashier, $this->branch->id);

        $this->assertSame(1, $second->summary['surpluses']);
        $this->assertSame(1, $second->summary['duplicates']);
        $this->assertDatabaseHas('bank_movements', ['bank_folio' => 'BANK-SURPLUS-002', 'classification' => 'SURPLUS', 'surplus_amount' => '500.0000']);
        $this->assertDatabaseHas('bank_movements', ['bank_folio' => 'BANK-SURPLUS-002', 'classification' => 'DUPLICATE', 'applied_amount' => '0.0000']);
        $this->assertSame(2, MovimientoBancario::query()->where('bank_folio', 'BANK-SURPLUS-002')->count());
        $this->assertSame(2, DB::table('relation_payments')->count());
    }

    public function test_saldo_a_favor_programado_entra_como_movimiento_y_se_aplica_hasta_conciliar(): void
    {
        Storage::fake('local');
        $relation = $this->createPaymentRelation();
        $sourceImport = ImportacionArchivoBancario::create([
            'private_path' => 'bank-imports/source.xlsx',
            'file_hash' => hash('sha256', 'source-credit-balance'),
            'uploaded_by' => $this->cashier->id,
            'branch_id' => $this->branch->id,
            'status' => 'PROCESSED',
        ]);
        $sourceMovement = MovimientoBancario::create([
            'import_id' => $sourceImport->id,
            'row_number' => 1,
            'original_row' => [],
            'payment_reference' => $relation->payment_reference,
            'amount' => '400.0000',
            'paid_at' => now(),
            'bank_folio' => 'BANK-CREDIT-SOURCE-400',
            'concept' => 'Excedente previo',
            'classification' => 'SURPLUS',
            'relation_id' => $relation->id,
            'distributor_id' => $relation->distributor_id,
            'surplus_amount' => '400.0000',
        ]);
        $surplus = ExcedenteDistribuidora::create([
            'distributor_id' => $relation->distributor_id,
            'branch_id' => $relation->branch_id,
            'origin_relation_id' => $relation->id,
            'bank_movement_id' => $sourceMovement->id,
            'original_amount' => '400.0000',
            'available_amount' => '400.0000',
            'status' => 'CREDIT_BALANCE',
        ]);

        $scheduled = app(ServicioExcedente::class)->programarDisponibles($relation, $this->distributorUser);

        $this->assertNotNull($scheduled);
        $this->assertSame('400.0000', $scheduled->amount);
        $this->assertSame('CREDIT_BALANCE', $scheduled->payment_type);
        $this->assertDatabaseMissing('relation_payments', ['relation_id' => $relation->id]);
        $this->assertSame('2975.0000', $relation->fresh()->balance);

        $path = app(ServicioTransferenciasBancariasSimuladas::class)->exportar($this->branch->id, $relation->process_run_id);
        $file = UploadedFile::fake()->createWithContent('saldo-favor.xlsx', file_get_contents($path));
        app(ServicioImportacionBancaria::class)->importar($file, $this->cashier, $this->branch->id, $relation->process_run_id);

        $this->assertSame('2575.0000', $relation->fresh()->balance);
        $this->assertSame('CONSUMED', $surplus->fresh()->status);
        $this->assertDatabaseHas('relation_payments', [
            'relation_id' => $relation->id,
            'source_type' => 'CREDIT_BALANCE',
            'amount' => '400.0000',
        ]);
        $this->assertDatabaseHas('bank_movements', [
            'bank_folio' => $scheduled->bank_folio,
            'relation_id' => $relation->id,
            'distributor_id' => $relation->distributor_id,
            'classification' => 'PARTIAL_PAYMENT',
            'reconciliation_status' => 'RECONCILED',
            'applied_amount' => '400.0000',
        ]);
    }

    public function test_excel_sin_movimientos_es_valido_para_cerrar_periodo_sin_abonos(): void
    {
        Storage::fake('local');
        $relation = $this->createPaymentRelation();
        $this->assertDatabaseCount('simulated_bank_transfers', 0);

        $path = app(ServicioTransferenciasBancariasSimuladas::class)->exportar($this->branch->id, $relation->process_run_id);
        $file = UploadedFile::fake()->createWithContent('sin-movimientos.xlsx', file_get_contents($path));
        $import = app(ServicioImportacionBancaria::class)->importar($file, $this->cashier, $this->branch->id, $relation->process_run_id);

        $this->assertSame('PROCESSED', $import->status);
        $this->assertSame(0, $import->row_count);
        $this->assertSame([
            'partial_payments' => 0,
            'settlements' => 0,
            'surpluses' => 0,
            'unreconciled' => 0,
            'duplicates' => 0,
        ], $import->summary);
        $this->assertDatabaseCount('bank_movements', 0);
        $this->assertSame('2975.0000', $relation->fresh()->balance);
    }

    public function test_subir_conciliacion_con_referencia_desconocida_marca_automaticamente_relacion_sin_pago(): void
    {
        Storage::fake('local');
        $relation = $this->createPaymentRelation();
        $deadline = now()->subDay()->endOfDay();
        $relation->update(['payment_deadline_at' => $deadline]);
        AuditLog::create([
            'entity_type' => 'operation_cutoff',
            'event_name' => 'ForzarCorte',
            'entity_id' => $relation->process_run_id,
            'actor_id' => $this->cashier->id,
            'new_value' => [
                'simulated_cutoff_at' => $relation->cutoff_at->toIso8601String(),
                'payment_deadline_at' => $deadline->toIso8601String(),
            ],
            'result' => 'SUCCESS',
        ]);
        foreach (['PaymentDeadlineReached', 'PaymentDeadlineExpired'] as $event) {
            AuditLog::create([
                'entity_type' => 'relation_process_run',
                'event_name' => $event,
                'entity_id' => $relation->process_run_id,
                'actor_id' => $this->cashier->id,
                'new_value' => ['expired_at' => now()->toIso8601String()],
                'result' => 'SUCCESS',
            ]);
        }
        Sanctum::actingAs($this->cashier);

        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())->post('/api/v1/bank-imports', [
            'file' => $this->xlsx([
                ['REL-NO-EXISTE', '125.00', now()->format('Y-m-d H:i:s'), 'BANK-UNKNOWN-AUTO-125', 'Referencia alterada'],
            ]),
            'process_run_id' => $relation->process_run_id,
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('data.summary.unreconciled', 1);
        $this->assertDatabaseHas('bank_movements', [
            'bank_folio' => 'BANK-UNKNOWN-AUTO-125',
            'relation_id' => null,
            'distributor_id' => null,
            'classification' => 'UNRECONCILED',
            'reconciliation_status' => 'UNRECONCILED',
        ]);
        $this->assertSame('OVERDUE', $relation->fresh()->financial_status);
        $this->assertTrue(bccomp($relation->fresh()->surcharge_total, '0', 4) > 0);
        $this->assertDatabaseHas('relation_late_fees', ['relation_id' => $relation->id, 'type' => 'LATE_FEE']);
        $evaluation = AuditLog::query()
            ->where('entity_type', 'distributor_relation')
            ->where('entity_id', $relation->id)
            ->where('event_name', 'PaymentDeadlineEvaluated')
            ->firstOrFail();
        $this->assertSame('unpaid', $evaluation->new_value['outcome']);
        $this->assertSame(0, $evaluation->new_value['payments']);
        $this->assertTrue($evaluation->new_value['late_fee_applied']);
        $this->assertDatabaseHas('audit_logs', [
            'entity_id' => $relation->process_run_id,
            'event_name' => 'ForcePaymentDeadlineCompleted',
            'result' => 'SUCCESS',
        ]);
    }

    public function test_referencia_valida_se_asocia_antes_de_aplicar_varios_pagos_a_relacion_liquidada(): void
    {
        Storage::fake('local');
        $relation = $this->createPaymentRelation();
        $relation->update([
            'portfolio_total' => '2771.0000',
            'misvales_total' => '2771.0000',
            'balance' => '2771.0000',
        ]);

        $import = app(ServicioImportacionBancaria::class)->importar($this->xlsx([
            [$relation->payment_reference, '3000', '2026-08-12 10:00:00', 'BANK-VALID-3000', 'Liquida y genera excedente'],
            [$relation->payment_reference, '71', '2026-08-12 10:01:00', 'BANK-VALID-71', 'Excedente posterior'],
            [$relation->payment_reference, '100', '2026-08-12 10:02:00', 'BANK-VALID-100', 'Segundo excedente posterior'],
            ['REL-INEXISTENTE', '50', '2026-08-12 10:03:00', 'BANK-UNKNOWN-50', 'Referencia inexistente'],
        ]), $this->cashier, $this->branch->id, $relation->process_run_id);

        $this->assertSame(3, $import->summary['surpluses']);
        $this->assertSame(1, $import->summary['unreconciled']);
        $this->assertSame('0.0000', $relation->fresh()->balance);
        $this->assertSame('SETTLED', $relation->fresh()->financial_status);
        $this->assertSame('400.0000', (string) ExcedenteDistribuidora::query()->sum('available_amount'));
        $this->assertSame(3, PagoRelacion::query()->where('relation_id', $relation->id)->count());

        foreach (['BANK-VALID-3000' => '229.0000', 'BANK-VALID-71' => '71.0000', 'BANK-VALID-100' => '100.0000'] as $folio => $surplus) {
            $this->assertDatabaseHas('bank_movements', [
                'bank_folio' => $folio,
                'relation_id' => $relation->id,
                'distributor_id' => $relation->distributor_id,
                'classification' => 'SURPLUS',
                'reconciliation_status' => 'RECONCILED',
                'surplus_amount' => $surplus,
            ]);
        }
        $this->assertDatabaseHas('bank_movements', [
            'bank_folio' => 'BANK-UNKNOWN-50',
            'relation_id' => null,
            'distributor_id' => null,
            'classification' => 'UNRECONCILED',
            'reconciliation_status' => 'UNRECONCILED',
        ]);
    }

    public function test_segundo_abono_usa_el_saldo_pendiente_actualizado(): void
    {
        Storage::fake('local');
        $relation = $this->createPaymentRelation();
        app(ServicioImportacionBancaria::class)->importar($this->xlsx([
            [$relation->payment_reference, '1000', '2026-08-12 10:00:00', 'BANK-PARTIAL-1', 'Primer abono'],
        ]), $this->cashier, $this->branch->id);
        app(ServicioImportacionBancaria::class)->importar($this->xlsx([
            [$relation->payment_reference, '500', '2026-08-13 10:00:00', 'BANK-PARTIAL-2', 'Segundo abono'],
        ]), $this->cashier, $this->branch->id);

        $this->assertSame('1475.0000', $relation->fresh()->balance);
        $this->assertDatabaseHas('bank_movements', ['bank_folio' => 'BANK-PARTIAL-2', 'balance_before' => '1975.0000', 'classification' => 'PARTIAL_PAYMENT']);
        $this->assertSame(2, DB::table('relation_payments')->where('relation_id', $relation->id)->count());
    }

    public function test_conciliacion_manual_requiere_autorizacion_y_la_cajera_la_ejecuta(): void
    {
        [$relation, $movement, $clarification] = $this->prepareManualReconciliation();
        Sanctum::actingAs($this->cashier);
        $manual = $this->postJson("/api/v1/bank-movements/{$movement->id}/manual-reconciliation-requests", [
            'relation_id' => $relation->id,
            'clarification_id' => $clarification->id,
            'reason' => 'Comprobante y referencia verificados',
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/manual-reconciliation-requests/{$manual['id']}/execute")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'MANUAL_RECONCILIATION_NOT_AUTHORIZED');

        $manager = $this->user('branch_manager', $this->branch->id);
        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/manual-reconciliation-requests/{$manual['id']}/decision", ['decision' => 'AUTHORIZE'])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'AUTHORIZED');

        Sanctum::actingAs($this->cashier);
        $this->postJson("/api/v1/manual-reconciliation-requests/{$manual['id']}/execute")
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'EXECUTED');
        $this->assertDatabaseHas('bank_movements', ['id' => $movement->id, 'reconciliation_status' => 'MANUALLY_RECONCILED', 'relation_id' => $relation->id]);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $manual['id'], 'event_name' => 'MANUAL_RECONCILIATION_EXECUTED', 'authorizer_id' => $manager->id, 'executor_id' => $this->cashier->id]);
    }

    public function test_cajera_no_puede_autorizar_su_propia_solicitud_aunque_reciba_el_permiso(): void
    {
        [$relation, $movement, $clarification] = $this->prepareManualReconciliation();
        Sanctum::actingAs($this->cashier);
        $manualId = $this->postJson("/api/v1/bank-movements/{$movement->id}/manual-reconciliation-requests", [
            'relation_id' => $relation->id,
            'clarification_id' => $clarification->id,
            'reason' => 'Solicitar revisión',
        ])->assertCreated()->json('data.id');
        $roleId = Role::query()->where('code', 'cashier')->value('id');
        $permissionId = DB::table('permissions')->where('code', 'manual_reconciliation.authorize_branch')->value('id');
        DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $roleId, 'permission_id' => $permissionId, 'granted_at' => now()]);

        $this->postJson("/api/v1/manual-reconciliation-requests/{$manualId}/decision", ['decision' => 'AUTHORIZE'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'MANUAL_RECONCILIATION_SELF_AUTHORIZATION_DENIED');
        $this->assertDatabaseHas('manual_reconciliation_requests', ['id' => $manualId, 'status' => 'REQUESTED', 'authorized_by' => null]);
    }

    public function test_coordinador_ajeno_y_cajera_de_otra_sucursal_no_pueden_operar_la_conciliacion(): void
    {
        [$relation, $movement, $clarification] = $this->prepareManualReconciliation();
        $otherBranch = Branch::factory()->create();
        $otherCashier = $this->user('cashier', $otherBranch->id);
        Sanctum::actingAs($otherCashier);
        $this->postJson("/api/v1/bank-movements/{$movement->id}/manual-reconciliation-requests", [
            'relation_id' => $relation->id,
            'clarification_id' => $clarification->id,
            'reason' => 'Intento fuera de sucursal',
        ])->assertForbidden()->assertJsonPath('error.code', 'MANUAL_RECONCILIATION_SCOPE_DENIED');

        Sanctum::actingAs($this->cashier);
        $manualId = $this->postJson("/api/v1/bank-movements/{$movement->id}/manual-reconciliation-requests", [
            'relation_id' => $relation->id,
            'clarification_id' => $clarification->id,
            'reason' => 'Solicitud válida',
        ])->assertCreated()->json('data.id');
        $unassignedCoordinator = $this->user('coordinator', $this->branch->id);
        Sanctum::actingAs($unassignedCoordinator);
        $this->postJson("/api/v1/manual-reconciliation-requests/{$manualId}/decision", ['decision' => 'AUTHORIZE'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'MANUAL_RECONCILIATION_AUTHORIZATION_DENIED');
    }

    public function test_coordinador_responsable_puede_autorizar_la_conciliacion_manual(): void
    {
        [$relation, $movement, $clarification] = $this->prepareManualReconciliation();
        Sanctum::actingAs($this->cashier);
        $manualId = $this->postJson("/api/v1/bank-movements/{$movement->id}/manual-reconciliation-requests", [
            'relation_id' => $relation->id,
            'clarification_id' => $clarification->id,
            'reason' => 'Solicitud con comprobante',
        ])->assertCreated()->json('data.id');
        $coordinator = $this->user('coordinator', $this->branch->id);
        CoordinatorDistributorAssignment::query()->create([
            'coordinator_id' => $coordinator->id,
            'distributor_id' => $relation->distributor_id,
            'branch_id' => $this->branch->id,
            'status' => 'ACTIVE',
            'valid_from' => now()->subDay(),
            'assigned_by' => $coordinator->id,
        ]);

        Sanctum::actingAs($coordinator);
        $this->postJson("/api/v1/manual-reconciliation-requests/{$manualId}/decision", ['decision' => 'AUTHORIZE'])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'AUTHORIZED')
            ->assertJsonPath('data.authorized_by', $coordinator->id);
    }

    public function test_recargo_es_unico_y_se_difiere_sin_archivo_bancario_valido(): void
    {
        $this->voucher->update(['status' => 'CASHED', 'cashed_at' => now()->subDays(30)]);
        $this->voucher->parcialidades()->create(['number' => 1, 'capital' => '2500', 'loan_commission' => '250', 'interest' => '200', 'insurance' => '25', 'distributor_profit' => '125', 'misvales_payment' => '2975', 'client_payment' => '3100', 'due_at' => now()->subDays(30)]);
        app(ServicioGeneracionRelacion::class)->generar(now('America/Monterrey')->subDays(25)->toImmutable());
        $relation = RelacionDistribuidora::firstOrFail();
        $relation->update(['payment_deadline_at' => now()->subDay()->endOfDay()]);
        $service = app(ServicioEvaluacionRecargo::class);
        $this->assertSame(1, $service->evaluar(now('America/Monterrey')->toImmutable())['deferred']);
        $this->assertSame('2975.0000', $relation->fresh()->balance);
        ImportacionArchivoBancario::create(['private_path' => 'private/test.xlsx', 'file_hash' => hash('sha256', 'fee-file'), 'uploaded_by' => $this->cashier->id, 'branch_id' => $this->branch->id, 'status' => 'PROCESSED', 'created_at' => $relation->payment_deadline_at->addHours(2), 'updated_at' => $relation->payment_deadline_at->addHours(2)]);
        $this->assertSame(1, $service->evaluar(now('America/Monterrey')->toImmutable())['applied']);
        $this->assertSame(0, $service->evaluar(now('America/Monterrey')->toImmutable())['applied']);
        $this->assertSame('3275.0000', $relation->fresh()->balance);
        $this->assertDatabaseCount('relation_late_fees', 1);
    }

    public function test_excedente_no_se_duplica_y_puede_convertirse_en_saldo_a_favor(): void
    {
        Storage::fake('local');
        $this->voucher->update(['status' => 'CASHED', 'cashed_at' => now()]);
        $this->voucher->parcialidades()->create(['number' => 1, 'capital' => '2500', 'loan_commission' => '250', 'interest' => '200', 'insurance' => '25', 'distributor_profit' => '125', 'misvales_payment' => '2975', 'client_payment' => '3100', 'due_at' => now()->subDay()]);
        app(ServicioGeneracionRelacion::class)->generar(now('America/Monterrey')->toImmutable());
        $relation = RelacionDistribuidora::firstOrFail();
        app(ServicioImportacionBancaria::class)->importar($this->xlsx([[$relation->payment_reference, '4000', '2026-08-12 10:00:00', 'BANK-SURPLUS', 'Excedente']]), $this->cashier, $this->branch->id);
        $surplus = ExcedenteDistribuidora::firstOrFail();
        $this->assertSame('1025.0000', $surplus->available_amount);
        $this->assertSame('PENDING_DECISION', $surplus->status);
        app(ServicioExcedente::class)->elegirCredito($surplus, $this->distributorUser);
        $this->assertSame('CREDIT_BALANCE', $surplus->fresh()->status);
        $installment = $this->voucher->parcialidades()->create(['number' => 2, 'capital' => '2500', 'loan_commission' => '250', 'interest' => '200', 'insurance' => '25', 'distributor_profit' => '125', 'misvales_payment' => '2975', 'client_payment' => '3100', 'due_at' => now()->addDays(15)]);
        $next = $relation->replicate(['id', 'payment_reference', 'cutoff_at', 'created_at', 'updated_at']);
        $next->id = (string) Str::uuid();
        $next->payment_reference = 'REL-NEXT-999';
        $next->cutoff_at = now()->addMonth();
        $next->portfolio_total = '3100';
        $next->misvales_total = '2975';
        $next->reconciled_total = '0';
        $next->balance = '2975';
        $next->financial_status = 'PENDING';
        $next->settled_at = null;
        $next->temporal_classification = null;
        $next->save();
        RelacionPartidaDistribuidora::create(['relation_id' => $next->id, 'voucher_installment_id' => $installment->id, 'snapshot' => ['folio' => $this->voucher->folio, 'installment' => 2, 'total_installments' => 4, 'capital' => '2500', 'loan_commission' => '250', 'interest' => '200', 'insurance' => '25', 'distributor_profit' => '125', 'client_payment' => '3100', 'misvales_payment' => '2975'], 'portfolio_amount' => '3100', 'misvales_amount' => '2975']);
        app(ServicioExcedente::class)->aplicarDisponibles($next);
        $this->assertSame('1950.0000', $next->fresh()->balance);
        $this->assertSame('CONSUMED', $surplus->fresh()->status);
        $this->assertDatabaseHas('relation_payments', ['source_type' => 'CREDIT_BALANCE', 'amount' => '1025.0000', 'capital_applied' => '550.0000']);

        $simulations = app(ServicioTransferenciasBancariasSimuladas::class)->listar($this->branch->id, $next->process_run_id);
        $legacyCredit = $simulations->firstWhere('bank_folio', 'SALDO-FAVOR-'.$next->id);
        $this->assertNotNull($legacyCredit);
        $this->assertSame('1025.0000', $legacyCredit->amount);
        $legacyBalance = $next->fresh()->balance;
        $legacyPayments = PagoRelacion::query()->where('relation_id', $next->id)->count();
        $path = app(ServicioTransferenciasBancariasSimuladas::class)->exportar($this->branch->id, $next->process_run_id);
        app(ServicioImportacionBancaria::class)->importar(
            UploadedFile::fake()->createWithContent('saldo-favor-aplicado.xlsx', file_get_contents($path)),
            $this->cashier,
            $this->branch->id,
            $next->process_run_id,
        );
        $this->assertSame($legacyBalance, $next->fresh()->balance);
        $this->assertSame($legacyPayments, PagoRelacion::query()->where('relation_id', $next->id)->count());
        $this->assertDatabaseHas('bank_movements', [
            'bank_folio' => $legacyCredit->bank_folio,
            'relation_id' => $next->id,
            'applied_amount' => '1025.0000',
            'reconciliation_status' => 'RECONCILED',
        ]);

        $movement = MovimientoBancario::create(['import_id' => ImportacionArchivoBancario::firstOrFail()->id, 'row_number' => 3, 'original_row' => [], 'payment_reference' => $relation->payment_reference, 'amount' => '300', 'paid_at' => now(), 'bank_folio' => 'BANK-REFUND', 'concept' => 'Excedente devolución', 'classification' => 'SURPLUS', 'relation_id' => $relation->id, 'surplus_amount' => '300']);
        $refundSurplus = ExcedenteDistribuidora::create(['distributor_id' => $this->distributorUser->distribuidora->id, 'branch_id' => $this->branch->id, 'origin_relation_id' => $relation->id, 'bank_movement_id' => $movement->id, 'original_amount' => '300', 'available_amount' => '300']);
        $request = app(ServicioExcedente::class)->solicitarDevolucion($refundSurplus, $this->distributorUser);
        $this->assertSame('0.0000', $refundSurplus->fresh()->available_amount);
        $this->assertSame('300.0000', $refundSurplus->fresh()->reserved_amount);
        $manager = $this->user('branch_manager', $this->branch->id);
        Sanctum::actingAs($manager);
        $this->postIdempotent('/api/v1/refund-requests/'.$request->id.'/decision', ['decision' => 'AUTHORIZE', 'reason' => 'Procede'])->assertSuccessful()->assertJsonPath('data.status', 'AUTHORIZED');
        $media = MediaFile::create(['file_type' => 'REFUND_EVIDENCE', 'disk' => 'local', 'path' => 'private/refund.pdf', 'original_name' => 'refund.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => hash('sha256', 'refund'), 'uploaded_by' => $this->cashier->id, 'validation_status' => 'VALIDATED', 'validated_at' => now()]);
        Storage::disk('local')->put('private/refund.pdf', 'refund');
        MediaFileBinding::create(['media_file_id' => $media->id, 'owner_type' => 'surplus_refund_request', 'owner_id' => $request->id, 'purpose' => 'REFUND_EVIDENCE', 'created_by' => $this->cashier->id]);
        Sanctum::actingAs($this->cashier);
        $this->postIdempotent('/api/v1/refund-requests/'.$request->id.'/execute', ['amount' => '300.0000', 'executed_at' => now()->toIso8601String(), 'method' => 'TRANSFERENCIA_EXTERNA', 'reference' => 'REF-001', 'evidence_media_id' => $media->id, 'observations' => 'Operación externa confirmada'])->assertSuccessful()->assertJsonPath('data.status', 'EXECUTED');
        $this->assertSame('REFUNDED', $refundSurplus->fresh()->status);
        $this->assertSame('0.0000', $refundSurplus->fresh()->reserved_amount);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $request->id, 'event_name' => 'REFUND_COMPLETED', 'actor_id' => $this->cashier->id, 'authorizer_id' => $manager->id, 'executor_id' => $this->cashier->id]);
        Sanctum::actingAs($this->distributorUser);
        $this->get('/api/v1/media/'.$media->id.'/download')->assertSuccessful();

        $unrelatedDistributor = $this->user('distributor', $this->branch->id);
        Distribuidora::factory()->active()->create(['user_id' => $unrelatedDistributor->id, 'branch_id' => $this->branch->id]);
        Sanctum::actingAs($unrelatedDistributor);
        $this->get('/api/v1/media/'.$media->id.'/download')->assertForbidden();
    }

    public function test_pago_de_5500_sobre_saldo_de_4800_genera_un_solo_excedente_de_700(): void
    {
        Storage::fake('local');
        $relation = $this->createPaymentRelation();
        $item = $relation->partidas()->firstOrFail();
        $snapshot = $item->snapshot;
        $snapshot['capital'] = '4000.0000';
        $snapshot['loan_commission'] = '300.0000';
        $snapshot['interest'] = '300.0000';
        $snapshot['insurance'] = '200.0000';
        $item->update(['snapshot' => $snapshot, 'misvales_amount' => '4800.0000']);
        $relation->update(['misvales_total' => '4800.0000', 'balance' => '4800.0000']);

        app(ServicioImportacionBancaria::class)->importar($this->xlsx([
            [$relation->payment_reference, '5500', '2026-08-21 10:00:00', 'BANK-OVERPAY-700', 'Pago mayor al saldo'],
        ]), $this->cashier, $this->branch->id);

        $movement = MovimientoBancario::query()->where('bank_folio', 'BANK-OVERPAY-700')->firstOrFail();
        $surplus = ExcedenteDistribuidora::query()->firstOrFail();
        $this->assertSame('0.0000', $relation->fresh()->balance);
        $this->assertSame('SETTLED', $relation->fresh()->financial_status);
        $this->assertSame('4800.0000', $movement->applied_amount);
        $this->assertSame('700.0000', $movement->surplus_amount);
        $this->assertSame('700.0000', $surplus->original_amount);
        $this->assertSame('700.0000', $surplus->available_amount);
        $this->assertSame($relation->id, $surplus->origin_relation_id);
        $this->assertSame($this->branch->id, $surplus->branch_id);
        $this->assertDatabaseCount('distributor_surpluses', 1);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $surplus->id, 'event_name' => 'EXCESS_CREATED']);

        try {
            app(ServicioAplicacionPago::class)->aplicar($movement, $relation);
            $this->fail('El mismo movimiento no debe aplicarse dos veces.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('PAYMENT_ALREADY_ALLOCATED', $exception->getMessage());
        }
        $this->assertDatabaseCount('distributor_surpluses', 1);
        $this->assertDatabaseCount('relation_payments', 1);
    }

    public function test_excedentes_posteriores_se_acumulan_sin_reclasificar_la_liquidacion_anticipada(): void
    {
        $relation = $this->createPaymentRelation();
        $firstPaidAt = '2026-08-21 10:00:00';

        app(ServicioImportacionBancaria::class)->importar($this->xlsx([
            [$relation->payment_reference, '3025', $firstPaidAt, 'BANK-EARLY-SURPLUS-50', 'Liquidación anticipada con excedente'],
        ]), $this->cashier, $this->branch->id);

        $settled = $relation->fresh();
        $this->assertSame('EARLY', $settled->temporal_classification);
        $settledAt = $settled->settled_at?->toIso8601String();

        app(ServicioImportacionBancaria::class)->importar($this->xlsx([
            [$relation->payment_reference, '50', '2026-09-14 10:00:00', 'BANK-SECOND-SURPLUS-50', 'Excedente posterior'],
        ]), $this->cashier, $this->branch->id);

        $relation->refresh();
        $this->assertSame('EARLY', $relation->temporal_classification);
        $this->assertSame($settledAt, $relation->settled_at?->toIso8601String());
        $this->assertSame('100.0000', (string) ExcedenteDistribuidora::query()->sum('available_amount'));
        $this->assertSame(['2975.0000', '0.0000'], PagoRelacion::query()->orderBy('applied_at')->pluck('amount')->all());
        $this->assertDatabaseHas('point_accounts', [
            'distributor_id' => $relation->distributor_id,
            'balance' => 6,
        ]);
        $this->assertDatabaseCount('point_movements', 1);

        $surpluses = ExcedenteDistribuidora::query()->oldest()->get();
        $service = app(ServicioExcedente::class);
        $surpluses->each(fn (ExcedenteDistribuidora $surplus) => $service->elegirCredito($surplus, $this->distributorUser));
        $destination = $this->futureRelationWithCapital($relation, 'REL-CREDIT-AGGREGATED-100', 2, '500.0000');
        $service->aplicarDisponibles($destination);

        $creditPayment = PagoRelacion::query()->where('relation_id', $destination->id)->where('source_type', 'CREDIT_BALANCE')->firstOrFail();
        $this->assertSame('100.0000', $creditPayment->amount);
        $this->assertSame(1, PagoRelacion::query()->where('relation_id', $destination->id)->where('source_type', 'CREDIT_BALANCE')->count());
        $this->assertSame(2, AplicacionExcedente::query()->where('relation_id', $destination->id)->where('payment_id', $creditPayment->id)->count());
        $this->assertSame('400.0000', $destination->fresh()->balance);
    }

    public function test_devolucion_respeta_sucursal_separacion_de_funciones_cancelacion_e_historial(): void
    {
        Storage::fake('local');
        $relation = $this->createPaymentRelation();
        app(ServicioImportacionBancaria::class)->importar($this->xlsx([
            [$relation->payment_reference, '3275', '2026-08-21 10:00:00', 'BANK-REFUND-SCOPE', 'Excedente de devolución'],
        ]), $this->cashier, $this->branch->id);
        $surplus = ExcedenteDistribuidora::query()->firstOrFail();

        Sanctum::actingAs($this->distributorUser);
        $refundId = $this->postIdempotent('/api/v1/surpluses/'.$surplus->id.'/refund-requests', [])->assertCreated()->assertJsonPath('data.status', 'REQUESTED')->json('data.id');
        $this->postIdempotent('/api/v1/surpluses/'.$surplus->id.'/credit-balance', [])->assertStatus(409);

        $otherBranch = Branch::factory()->create();
        $otherManager = $this->user('branch_manager', $otherBranch->id);
        Sanctum::actingAs($otherManager);
        $this->postIdempotent('/api/v1/refund-requests/'.$refundId.'/decision', ['decision' => 'AUTHORIZE', 'reason' => 'Intento fuera de alcance'])->assertForbidden();

        $otherDistributorUser = $this->user('distributor', $otherBranch->id);
        Distribuidora::factory()->active()->create(['user_id' => $otherDistributorUser->id, 'branch_id' => $otherBranch->id]);
        Sanctum::actingAs($otherDistributorUser);
        $this->getJson('/api/v1/surpluses/'.$surplus->id)->assertForbidden();
        $this->postIdempotent('/api/v1/surpluses/'.$surplus->id.'/credit-balance', [])->assertForbidden();
        $this->postIdempotent('/api/v1/surpluses/'.$surplus->id.'/refund-requests', [])->assertForbidden();

        Sanctum::actingAs($this->cashier);
        $this->postIdempotent('/api/v1/refund-requests/'.$refundId.'/decision', ['decision' => 'AUTHORIZE', 'reason' => 'La cajera no autoriza'])->assertForbidden();
        $prematureEvidence = MediaFile::create(['file_type' => 'REFUND_EVIDENCE', 'disk' => 'local', 'path' => 'private/premature.pdf', 'original_name' => 'premature.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => hash('sha256', 'premature'), 'uploaded_by' => $this->cashier->id, 'validation_status' => 'VALIDATED', 'validated_at' => now()]);
        $this->postIdempotent('/api/v1/refund-requests/'.$refundId.'/execute', ['amount' => '300.0000', 'executed_at' => now()->toIso8601String(), 'method' => 'EFECTIVO', 'reference' => 'NO-AUTORIZADA', 'evidence_media_id' => $prematureEvidence->id])->assertStatus(409)->assertJsonPath('error.code', 'REFUND_NOT_AUTHORIZED');

        Sanctum::actingAs($this->distributorUser);
        $this->postIdempotent('/api/v1/refund-requests/'.$refundId.'/cancel', ['reason' => 'Prefiero volver a decidir'])->assertSuccessful()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame('PENDING_DECISION', $surplus->fresh()->status);
        $this->assertSame('300.0000', $surplus->fresh()->available_amount);
        $this->assertDatabaseHas('surplus_refund_requests', ['id' => $refundId, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $refundId, 'event_name' => 'REFUND_CANCELLED']);

        $secondRefundId = $this->postIdempotent('/api/v1/surpluses/'.$surplus->id.'/refund-requests', [])->assertCreated()->json('data.id');
        $manager = $this->user('branch_manager', $this->branch->id);
        Sanctum::actingAs($manager);
        $this->postIdempotent('/api/v1/refund-requests/'.$secondRefundId.'/decision', ['decision' => 'REJECT', 'reason' => 'Falta confirmar el método'])->assertSuccessful()->assertJsonPath('data.status', 'REJECTED');
        $this->assertDatabaseCount('surplus_refund_requests', 2);
        $this->assertSame('PENDING_DECISION', $surplus->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $secondRefundId, 'event_name' => 'REFUND_REJECTED']);
    }

    public function test_saldo_a_favor_se_aplica_en_orden_financiero_y_conserva_remanentes(): void
    {
        $destination = $this->createPaymentRelation();
        $item = $destination->partidas()->firstOrFail();
        $snapshot = $item->snapshot;
        $snapshot['capital'] = '1800.0000';
        $snapshot['loan_commission'] = '200.0000';
        $snapshot['interest'] = '300.0000';
        $snapshot['insurance'] = '200.0000';
        $item->update(['snapshot' => $snapshot, 'misvales_amount' => '2500.0000']);
        $destination->update(['misvales_total' => '2500.0000', 'balance' => '2500.0000']);
        $origin = $this->replicatePaymentRelation($destination, 'REL-ORIGIN-CREDIT', '0.0000');
        $origin->update(['financial_status' => 'SETTLED', 'settled_at' => now()]);
        $import = ImportacionArchivoBancario::create(['private_path' => 'private/credit-source.xlsx', 'file_hash' => hash('sha256', 'credit-source'), 'uploaded_by' => $this->cashier->id, 'branch_id' => $this->branch->id, 'status' => 'PROCESSED']);
        $movement = MovimientoBancario::create(['import_id' => $import->id, 'row_number' => 1, 'original_row' => [], 'payment_reference' => $origin->payment_reference, 'amount' => '700.0000', 'paid_at' => now(), 'bank_folio' => 'BANK-CREDIT-700', 'concept' => 'Saldo a favor', 'classification' => 'SURPLUS', 'relation_id' => $origin->id, 'applied_amount' => '0.0000', 'surplus_amount' => '700.0000']);
        $credit = ExcedenteDistribuidora::create(['distributor_id' => $destination->distributor_id, 'branch_id' => $this->branch->id, 'origin_relation_id' => $origin->id, 'bank_movement_id' => $movement->id, 'original_amount' => '700.0000', 'available_amount' => '700.0000']);
        $service = app(ServicioExcedente::class);
        $service->elegirCredito($credit, $this->distributorUser);
        $lineBefore = $this->distributorUser->distribuidora->lineaCredito->used_balance;
        $service->aplicarDisponibles($destination);

        $this->assertSame('1800.0000', $destination->fresh()->balance);
        $this->assertSame('CONSUMED', $credit->fresh()->status);
        $this->assertSame($lineBefore, $this->distributorUser->distribuidora->lineaCredito->fresh()->used_balance);
        $this->assertDatabaseHas('relation_payments', ['relation_id' => $destination->id, 'amount' => '700.0000', 'capital_applied' => '0.0000', 'line_recovered' => '0.0000']);
        $this->assertDatabaseHas('surplus_applications', ['surplus_id' => $credit->id, 'relation_id' => $destination->id, 'balance_before' => '700.0000', 'balance_after' => '0.0000']);

        $secondDestination = $this->futureRelationWithCapital($destination, 'REL-CREDIT-2500', 2, '2500.0000');
        $secondMovement = MovimientoBancario::create(['import_id' => $import->id, 'row_number' => 2, 'original_row' => [], 'payment_reference' => $origin->payment_reference, 'amount' => '3000.0000', 'paid_at' => now(), 'bank_folio' => 'BANK-CREDIT-3000', 'concept' => 'Saldo a favor amplio', 'classification' => 'SURPLUS', 'relation_id' => $origin->id, 'applied_amount' => '0.0000', 'surplus_amount' => '3000.0000']);
        $largeCredit = ExcedenteDistribuidora::create(['distributor_id' => $destination->distributor_id, 'branch_id' => $this->branch->id, 'origin_relation_id' => $origin->id, 'bank_movement_id' => $secondMovement->id, 'original_amount' => '3000.0000', 'available_amount' => '3000.0000']);
        $service->elegirCredito($largeCredit, $this->distributorUser);
        $service->aplicarDisponibles($secondDestination);
        $this->assertSame('0.0000', $secondDestination->fresh()->balance);
        $this->assertSame('500.0000', $largeCredit->fresh()->available_amount);
        $this->assertSame('PARTIALLY_APPLIED', $largeCredit->fresh()->status);
        $service->aplicarDisponibles($secondDestination);
        $this->assertSame(2, DB::table('surplus_applications')->count());
        $this->assertSame('500.0000', $largeCredit->fresh()->available_amount);

        $thirdDestination = $this->futureRelationWithCapital($destination, 'REL-CREDIT-REMAINDER', 3, '500.0000');
        $service->aplicarDisponibles($thirdDestination);
        $this->assertSame('0.0000', $thirdDestination->fresh()->balance);
        $this->assertSame('0.0000', $largeCredit->fresh()->available_amount);
        $this->assertSame('CONSUMED', $largeCredit->fresh()->status);
        $this->assertDatabaseCount('surplus_applications', 3);
        $this->assertSame(3, DB::table('audit_logs')->where('event_name', 'EXCESS_APPLIED')->count());
    }

    public function test_flujo_tardio_integra_alerta_bloqueo_regularizacion_retiro_y_nuevo_vale(): void
    {
        $d = $this->distributorUser->distribuidora;
        $generalManager = $this->user('general_manager');
        $manager = $this->user('branch_manager', $this->branch->id);
        $otherBranch = Branch::factory()->create();
        $otherBranchManager = $this->user('branch_manager', $otherBranch->id);
        Notification::fake();
        $run = (string) Str::uuid();
        DB::table('relation_process_runs')->insert(['id' => $run, 'cutoff_at' => now()->subMonths(3), 'status' => 'COMPLETED', 'attempt' => 1, 'configuration_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([3, 2, 1] as $m) {
            RelacionDistribuidora::create(['process_run_id' => $run, 'distributor_id' => $d->id, 'branch_id' => $this->branch->id, 'cutoff_at' => now()->subMonths($m), 'advance_period_start' => now()->subMonths($m), 'advance_period_end' => now()->subMonths($m), 'payment_deadline_at' => now()->subMonths($m)->endOfMonth(), 'payment_reference' => 'REL-RISK-'.$m, 'financial_status' => $m === 1 ? 'OVERDUE' : 'ROLLED_FORWARD', 'portfolio_total' => '100', 'misvales_total' => '100', 'balance' => $m === 1 ? '100' : '0', 'rolled_forward_amount' => $m === 1 ? '0' : '100', 'header_snapshot' => [], 'bank_snapshot' => []]);
        }
        $lastRelation = RelacionDistribuidora::query()->where('payment_reference', 'REL-RISK-1')->firstOrFail();
        ImportacionArchivoBancario::create(['private_path' => 'private/risk-e2e.xlsx', 'file_hash' => hash('sha256', 'risk-e2e-file'), 'uploaded_by' => $this->cashier->id, 'branch_id' => $this->branch->id, 'status' => 'PROCESSED', 'created_at' => $lastRelation->payment_deadline_at->addHours(2), 'updated_at' => $lastRelation->payment_deadline_at->addHours(2)]);
        $lateFees = app(ServicioEvaluacionRecargo::class);
        $this->assertSame(1, $lateFees->evaluar(now('America/Monterrey')->toImmutable())['applied']);
        $this->assertSame(0, $lateFees->evaluar(now('America/Monterrey')->toImmutable())['applied']);
        $this->assertDatabaseCount('relation_late_fees', 1);
        $service = app(ServicioMorosidadDistribuidora::class);
        $service->evaluarCorteConciliado($run, $this->branch->id);
        $alert = AlertaRiesgoDistribuidora::query()->where('distributor_id', $d->id)->firstOrFail();
        $this->assertSame('400.0000', $alert->overdue_balance);
        Notification::assertSentTo($generalManager, NotificacionEventoDominio::class);
        Notification::assertSentTo($manager, NotificacionEventoDominio::class);
        Notification::assertNotSentTo($otherBranchManager, NotificacionEventoDominio::class);
        $this->assertDatabaseMissing('distributor_operational_blocks', ['distributor_id' => $d->id, 'status' => 'ACTIVE']);
        $service->decidir($alert, $manager, true, 'Tres incumplimientos confirmados');
        $this->assertDatabaseHas('distributor_operational_blocks', ['distributor_id' => $d->id, 'type' => 'DELINQUENCY', 'status' => 'ACTIVE']);
        $coordinator = $this->user('coordinator', $this->branch->id);
        CoordinatorDistributorAssignment::create(['coordinator_id' => $coordinator->id, 'distributor_id' => $d->id, 'branch_id' => $this->branch->id, 'status' => 'ACTIVE', 'valid_from' => now()->subDay(), 'valid_to' => null, 'assigned_by' => $manager->id]);
        Sanctum::actingAs($this->distributorUser);
        $this->postIdempotent('/api/v1/vouchers', ['client_id' => $this->voucher->client_id, 'product_version_id' => $this->voucher->product_version_id])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DISTRIBUTOR_DELINQUENCY_BLOCK');
        try {
            $service->solicitarRetiro($d, $this->distributorUser, 'Aun con saldo vencido');
            $this->fail('Debio exigir regularizacion financiera');
        } catch (\RuntimeException $exception) {
            $this->assertSame('DISTRIBUTOR_NOT_REGULARIZED', $exception->getMessage());
        }

        RelacionDistribuidora::query()->where('distributor_id', $d->id)->update([
            'balance' => '0.0000',
            'financial_status' => 'SETTLED',
            'settled_at' => now(),
        ]);
        $request = $service->solicitarRetiro($d, $this->distributorUser, 'Pagos conciliados y saldo vencido cero');
        $this->assertDatabaseHas('distributor_operational_blocks', ['distributor_id' => $d->id, 'status' => 'ACTIVE']);
        $service->decidirRetiro($request, $manager, true, 'Regularizacion comprobada');
        $this->assertDatabaseHas('distributor_operational_blocks', ['distributor_id' => $d->id, 'status' => 'RELEASED']);

        $this->voucher->update(['status' => 'CASHED', 'cashed_at' => now()]);
        $this->postIdempotent('/api/v1/vouchers', ['client_id' => $this->voucher->client_id, 'product_version_id' => $this->voucher->product_version_id])
            ->assertSuccessful();
    }

    public function test_regularizacion_no_desbloquea_hasta_retiro_autorizado(): void
    {
        $d = $this->distributorUser->distribuidora;
        $relation = $this->createPaymentRelation();
        $relation->update(['balance' => '0.0000', 'financial_status' => 'SETTLED', 'settled_at' => now()]);
        $alert = AlertaRiesgoDistribuidora::create(['distributor_id' => $d->id, 'branch_id' => $this->branch->id, 'type' => 'THREE_CONSECUTIVE_DEFAULTS', 'consecutive_defaults' => 3, 'relation_ids' => [$relation->id], 'overdue_balance' => '0']);
        $manager = $this->user('branch_manager', $this->branch->id);
        $service = app(ServicioMorosidadDistribuidora::class);
        $service->decidir($alert, $manager, true, 'Historial');
        $coordinator = $this->user('coordinator', $this->branch->id);
        CoordinatorDistributorAssignment::create(['coordinator_id' => $coordinator->id, 'distributor_id' => $d->id, 'branch_id' => $this->branch->id, 'status' => 'ACTIVE', 'valid_from' => now()->subDay(), 'valid_to' => null, 'assigned_by' => $manager->id]);
        $request = $service->solicitarRetiro($d, $this->distributorUser, 'Saldo vencido cero');
        $this->assertDatabaseHas('distributor_operational_blocks', ['distributor_id' => $d->id, 'status' => 'ACTIVE']);
        $service->decidirRetiro($request, $manager, true, 'Autoriza retiro');
        $this->assertDatabaseHas('distributor_operational_blocks', ['distributor_id' => $d->id, 'status' => 'RELEASED']);
    }

    public function test_distribuidora_solicita_por_ciclo_regularizado_y_gerencia_retira_directamente(): void
    {
        Notification::fake();
        $distributor = $this->distributorUser->distribuidora;
        $manager = $this->user('branch_manager', $this->branch->id);
        $relation = $this->createPaymentRelation();
        $alert = AlertaRiesgoDistribuidora::create([
            'distributor_id' => $distributor->id,
            'branch_id' => $this->branch->id,
            'type' => 'THREE_CONSECUTIVE_DEFAULTS',
            'consecutive_defaults' => 3,
            'relation_ids' => [$relation->id],
            'overdue_balance' => $relation->balance,
        ]);
        $service = app(ServicioMorosidadDistribuidora::class);
        $service->decidir($alert, $manager, true, 'Tres incumplimientos');

        app(ServicioAplicacionPago::class)->aplicarSaldoFavor(
            $relation->balance,
            now()->toImmutable(),
            $relation,
            (string) Str::uuid(),
        );
        Notification::assertSentTo($manager, NotificacionEventoDominio::class, fn ($notification) => $notification->content['title'] === 'Deuda de morosidad liquidada: '.$distributor->distributor_number);

        $newRelation = $this->replicatePaymentRelation($relation->fresh(), 'REL-NEW-CYCLE', '1232.0000');
        $this->assertSame('PENDING', $newRelation->financial_status);
        $this->assertTrue($service->estadoRetiro($distributor)['regularized_relation'] !== null);

        $request = $service->solicitarRetiro($distributor, $this->distributorUser, 'Deuda del ciclo liquidada');
        $service->decidirRetiro($request, $manager, false, 'Solicitud rechazada');
        $this->assertDatabaseHas('distributor_operational_blocks', ['distributor_id' => $distributor->id, 'status' => 'ACTIVE']);

        $direct = $service->retirarDirectamente($distributor, $manager, 'Retiro validado por Gerencia');
        $this->assertSame('AUTHORIZED', $direct->status);
        $this->assertSame($relation->id, $direct->regularized_relation_id);
        $this->assertDatabaseHas('distributor_operational_blocks', ['distributor_id' => $distributor->id, 'status' => 'RELEASED']);
    }

    public function test_morosidad_permite_revisar_ciclos_sucesivos_sin_perder_historial(): void
    {
        $d = $this->distributorUser->distribuidora;
        $manager = $this->user('branch_manager', $this->branch->id);
        $service = app(ServicioMorosidadDistribuidora::class);

        $first = AlertaRiesgoDistribuidora::create([
            'distributor_id' => $d->id,
            'branch_id' => $this->branch->id,
            'type' => 'THREE_CONSECUTIVE_DEFAULTS',
            'consecutive_defaults' => 3,
            'relation_ids' => [],
            'overdue_balance' => '300.0000',
        ]);
        $service->decidir($first, $manager, false, 'Primer ciclo revisado');

        $second = AlertaRiesgoDistribuidora::create([
            'distributor_id' => $d->id,
            'branch_id' => $this->branch->id,
            'type' => 'THREE_CONSECUTIVE_DEFAULTS',
            'consecutive_defaults' => 3,
            'relation_ids' => [],
            'overdue_balance' => '450.0000',
        ]);
        $service->decidir($second, $manager, false, 'Segundo ciclo revisado');

        $this->assertDatabaseCount('distributor_risk_alerts', 2);
        $this->assertDatabaseHas('distributor_risk_alerts', ['id' => $first->id, 'status' => 'REVIEWED']);
        $this->assertDatabaseHas('distributor_risk_alerts', ['id' => $second->id, 'status' => 'REVIEWED']);
        $this->assertDatabaseCount('distributor_delinquency_decisions', 2);
    }

    private function corteSeptiembre(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-25 00:05:00', 'America/Monterrey');
    }

    private function marcarCorteComoConciliado(RelacionDistribuidora $relation): void
    {
        AuditLog::query()->create([
            'entity_type' => 'relation_process_run',
            'entity_id' => $relation->process_run_id,
            'event_name' => 'ForcePaymentDeadlineCompleted',
            'new_value' => ['status' => 'COMPLETED'],
            'result' => 'SUCCESS',
        ]);
    }

    private function materializarCalendario(Vale $vale, string $cashedAt, int $installmentCount): Vale
    {
        $cashedAt = CarbonImmutable::parse($cashedAt, 'America/Monterrey');
        $vale->forceFill([
            'status' => EstadoVale::FERIADO,
            'cashed_at' => $cashedAt,
            'cashed_by' => $this->cashier->id,
            'fortnights_count' => $installmentCount,
        ])->save();
        $vale->parcialidades()->createMany(
            collect(range(1, $installmentCount))->map(fn (int $number): array => [
                'number' => $number,
                'capital' => '100.0000',
                'loan_commission' => '10.0000',
                'interest' => '5.0000',
                'insurance' => '1.0000',
                'distributor_profit' => '4.0000',
                'misvales_payment' => '116.0000',
                'client_payment' => '120.0000',
                'due_at' => null,
            ])->all(),
        );
        app(ServicioCalendarioParcialidadesVale::class)->programar($vale, $cashedAt);

        return $vale->refresh();
    }

    private function clonarVale(string $folio): Vale
    {
        $vale = $this->voucher->replicate(['id', 'folio', 'created_at', 'updated_at']);
        $vale->forceFill([
            'id' => (string) Str::uuid(),
            'folio' => $folio,
            'status' => EstadoVale::GENERADO,
            'cashed_at' => null,
            'cashed_by' => null,
            'lock_version' => 1,
        ])->save();

        return $vale;
    }

    private function clonarValeParaCliente(string $folio, string $firstName): Vale
    {
        $client = Cliente::factory()->create([
            'created_by' => $this->distributorUser->id,
            'first_name' => $firstName,
            'first_last_name' => 'Prueba',
        ]);
        AsignacionClienteDistribuidora::factory()->create([
            'client_id' => $client->id,
            'distributor_id' => $this->voucher->distributor_id,
            'branch_id' => $this->branch->id,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'assigned_by' => $this->distributorUser->id,
        ]);
        $vale = $this->clonarVale($folio);
        $vale->forceFill(['client_id' => $client->id])->save();

        return $vale;
    }

    private function valeEscenarioFinanciero(string $folio, string $firstName, string $cashedAt, string $basePayment, string $misvalesPayment, string $profit, string $capital, string $interest): Vale
    {
        $vale = $this->clonarValeParaCliente($folio, $firstName);
        $cashed = CarbonImmutable::parse($cashedAt, 'America/Monterrey');
        $vale->forceFill([
            'status' => EstadoVale::FERIADO,
            'cashed_at' => $cashed,
            'cashed_by' => $this->cashier->id,
            'fortnights_count' => 8,
            'client_payment_per_fortnight' => $basePayment,
            'misvales_payment_per_fortnight' => $misvalesPayment,
            'distributor_profit_per_fortnight' => $profit,
        ])->save();
        $vale->parcialidades()->createMany(collect(range(1, 8))->map(fn (int $number): array => [
            'number' => $number,
            'capital' => $capital,
            'loan_commission' => bcsub($basePayment, bcadd(bcadd($capital, $interest, 4), $number === 8 && $firstName === 'Maria' ? '16.0000' : '12.0000', 4), 4),
            'interest' => $interest,
            'insurance' => $number === 8 && $firstName === 'Maria' ? '16.0000' : '12.0000',
            'distributor_profit' => $profit,
            'misvales_payment' => $misvalesPayment,
            'client_payment' => $basePayment,
            'due_at' => null,
        ])->all());
        app(ServicioCalendarioParcialidadesVale::class)->programar($vale, $cashed);

        return $vale->refresh();
    }

    private function pagarConciliarYCerrarCiclo(RelacionDistribuidora $relation, int $cycle, User $manager): void
    {
        Sanctum::actingAs($manager);
        $this->postIdempotent('/api/v1/operations/force-payment-deadline', ['motivo' => "Preparar conciliación ciclo {$cycle}"])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'DEADLINE_REACHED');
        $this->postIdempotent('/api/v1/operations/force-payment-deadline', ['motivo' => "Habilitar archivo ciclo {$cycle}"])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'DEFERRED');

        Sanctum::actingAs($this->cashier);
        $file = $this->xlsx([[
            $relation->payment_reference,
            $relation->balance,
            $relation->payment_deadline_at->format('Y-m-d H:i:s'),
            "SCENARIO-CYCLE-{$cycle}",
            "Liquidación completa ciclo {$cycle}",
        ]]);
        $this->post('/api/v1/bank-imports', [
            'file' => $file,
            'process_run_id' => $relation->process_run_id,
        ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame('0.0000', $relation->fresh()->balance);
        $this->assertDatabaseHas('audit_logs', [
            'entity_id' => $relation->process_run_id,
            'event_name' => 'ForcePaymentDeadlineCompleted',
            'result' => 'SUCCESS',
        ]);
    }

    private function valeDeOtraDistribuidora(string $folio): Vale
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $distributor = Distribuidora::factory()->active()->create([
            'user_id' => $user->id,
            'branch_id' => $this->branch->id,
        ]);
        $line = LineaCredito::factory()->create([
            'distributor_id' => $distributor->id,
            'total_authorized' => '30000.0000',
            'used_balance' => '0.0000',
        ]);
        $vale = $this->clonarVale($folio);
        $vale->forceFill([
            'distributor_id' => $distributor->id,
            'branch_id' => $distributor->branch_id,
            'credit_line_id' => $line->id,
        ])->save();

        return $vale;
    }

    /** @return list<int> */
    private function numerosRelacionados(Vale $vale, ?RelacionDistribuidora $relation = null): array
    {
        return RelacionPartidaDistribuidora::query()
            ->when($relation !== null, fn ($query) => $query->where('relation_id', $relation->id))
            ->whereHas('installment', fn ($query) => $query->where('voucher_id', $vale->id))
            ->with('installment')
            ->get()
            ->map(fn (RelacionPartidaDistribuidora $item): int => $item->installment->number)
            ->sort()
            ->values()
            ->all();
    }

    private function createPaymentRelation(): RelacionDistribuidora
    {
        $this->voucher->update(['status' => 'CASHED', 'cashed_at' => now()]);
        $this->voucher->parcialidades()->create([
            'number' => 1,
            'capital' => '2500',
            'loan_commission' => '250',
            'interest' => '200',
            'insurance' => '25',
            'distributor_profit' => '125',
            'misvales_payment' => '2975',
            'client_payment' => '3100',
            'due_at' => now()->subDay(),
        ]);
        app(ServicioGeneracionRelacion::class)->generar(CarbonImmutable::now('America/Monterrey'));

        return RelacionDistribuidora::query()->firstOrFail();
    }

    private function replicatePaymentRelation(RelacionDistribuidora $relation, string $reference, string $balance, int $monthsAfter = 1): RelacionDistribuidora
    {
        $copy = $relation->replicate(['id', 'payment_reference', 'cutoff_at', 'created_at', 'updated_at']);
        $copy->forceFill([
            'id' => (string) Str::uuid(),
            'payment_reference' => $reference,
            'cutoff_at' => $relation->cutoff_at->addMonths($monthsAfter),
            'portfolio_total' => $balance,
            'misvales_total' => $balance,
            'reconciled_total' => '0.0000',
            'balance' => $balance,
            'financial_status' => 'PENDING',
            'review_status' => 'NO_REVIEW',
            'settled_at' => null,
            'temporal_classification' => null,
        ])->save();

        return $copy;
    }

    private function futureRelationWithCapital(RelacionDistribuidora $base, string $reference, int $installmentNumber, string $amount): RelacionDistribuidora
    {
        $relation = $this->replicatePaymentRelation($base, $reference, $amount, $installmentNumber);
        $installment = $this->voucher->parcialidades()->create(['number' => $installmentNumber, 'capital' => $amount, 'loan_commission' => '0.0000', 'interest' => '0.0000', 'insurance' => '0.0000', 'distributor_profit' => '0.0000', 'misvales_payment' => $amount, 'client_payment' => $amount, 'due_at' => now()->addDays($installmentNumber * 15)]);
        RelacionPartidaDistribuidora::create(['relation_id' => $relation->id, 'voucher_installment_id' => $installment->id, 'snapshot' => ['folio' => $this->voucher->folio, 'installment' => $installmentNumber, 'total_installments' => 4, 'capital' => $amount, 'loan_commission' => '0.0000', 'interest' => '0.0000', 'insurance' => '0.0000', 'distributor_profit' => '0.0000', 'client_payment' => $amount, 'misvales_payment' => $amount], 'portfolio_amount' => $amount, 'misvales_amount' => $amount]);

        return $relation;
    }

    private function prepareManualReconciliation(): array
    {
        Storage::fake('local');
        $relation = $this->createPaymentRelation();
        app(ServicioImportacionBancaria::class)->importar($this->xlsx([
            ['REFERENCIA-INEXISTENTE', '1000', '2026-08-12 10:00:00', 'BANK-MANUAL-001', 'Pago por aclarar'],
        ]), $this->cashier, $this->branch->id);
        $movement = MovimientoBancario::query()->where('bank_folio', 'BANK-MANUAL-001')->firstOrFail();
        $evidence = MediaFile::query()->create([
            'file_type' => 'CLARIFICATION',
            'disk' => 'local',
            'path' => 'private/clarification.pdf',
            'original_name' => 'comprobante.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 20,
            'sha256' => hash('sha256', 'manual-evidence'),
            'uploaded_by' => $this->distributorUser->id,
            'validation_status' => 'VALIDATED',
            'validated_at' => now(),
        ]);
        MediaFileBinding::query()->create([
            'media_file_id' => $evidence->id,
            'owner_type' => 'distributor_relation',
            'owner_id' => $relation->id,
            'purpose' => 'CLARIFICATION',
            'created_by' => $this->distributorUser->id,
        ]);
        Sanctum::actingAs($this->distributorUser);
        $clarificationId = $this->postJson("/api/v1/relations/{$relation->id}/clarifications", [
            'evidence_media_id' => $evidence->id,
            'reason' => 'El pago corresponde a esta relación.',
        ])->assertCreated()->json('data.id');

        return [$relation, $movement, AclaracionPago::query()->findOrFail($clarificationId)];
    }

    private function xlsx(array $rows, array $headers = ['referencia de pago', 'monto', 'fecha', 'folio bancario', 'concepto']): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'bank').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'banco.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function xlsxValido(array $rows, array $headers = ['referencia de pago', 'monto', 'fecha', 'folio bancario', 'concepto']): UploadedFile
    {
        return $this->xlsx($rows, $headers);
    }

    private function publishRelationConfiguration(): void
    {
        $values = [
            'BUSINESS_TIMEZONE' => ['TIMEZONE', 'America/Monterrey'],
            'CUT_DAY_OF_MONTH' => ['INTEGER', 25],
            'CUT_TIME' => ['TIME', '00:05'],
            'PAYMENT_DAYS_AFTER_CUT' => ['INTEGER', 20],
            'PAYMENT_DEADLINE_TIME' => ['TIME', '23:59:59'],
            'RELATION_PAYMENT_BANK' => ['JSON', ['name' => 'Banco configurado', 'beneficiary' => 'MisVales', 'agreement' => 'CONV-TEST', 'clabe' => '012345678901234567']],
            'LATE_FEE_AMOUNT' => ['DECIMAL', '300.0000'],
            'BANK_UPLOAD_DEADLINE_TIME' => ['TIME', '08:00'],
            'POST_DUE_EVALUATION_TIME' => ['TIME', '08:30'],
        ];

        foreach ($values as $key => [$type, $value]) {
            Cache::forget("configuracion:{$key}");
            $definition = ConfigurationDefinition::query()->create([
                'key' => $key,
                'name' => $key,
                'value_type' => $type,
                'status' => 'ACTIVE',
                'created_by' => $this->distributorUser->id,
            ]);
            ConfigurationVersion::query()->create([
                'configuration_definition_id' => $definition->id,
                'version' => 1,
                'value' => $value,
                'status' => 'PUBLISHED',
                'effective_from' => now()->subYear(),
                'reason' => 'Configuración de prueba',
                'created_by' => $this->distributorUser->id,
                'published_by' => $this->distributorUser->id,
                'published_at' => now(),
            ]);
        }
    }

    private function postIdempotent(string $uri, array $data)
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($uri, $data);
    }

    private function user(string $roleCode, ?string $branchId = null): User
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        UserRoleScope::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'branch_id' => $branchId, 'assigned_by_user_id' => $user->id]);

        return $user;
    }
}
