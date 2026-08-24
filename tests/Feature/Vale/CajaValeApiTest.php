<?php

namespace Tests\Feature\Vale;

use App\Enums\EstadoVale;
use App\Exceptions\ExcepcionConciliacion;
use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\AclaracionPago;
use App\Models\AlertaRiesgoDistribuidora;
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
use App\Services\Cliente\ProtectorDatosCliente;
use App\Services\Conciliacion\ServicioImportacionBancaria;
use App\Services\Excedente\ServicioExcedente;
use App\Services\Pago\ServicioAplicacionPago;
use App\Services\Recargo\ServicioEvaluacionRecargo;
use App\Services\Relacion\ServicioGeneracionRelacion;
use App\Services\Riesgo\ServicioMorosidadDistribuidora;
use App\Services\SolicitudDistribuidora\ProtectorDatosSolicitud;
use App\Services\Vale\ServicioCalendarioParcialidadesVale;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

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
        $product = Product::query()->create(['code' => 'CASH-10000', 'status' => 'ACTIVE', 'created_by' => $this->distributorUser->id]);
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
        $authority = $this->user('branch_manager', $this->branch->id);
        Sanctum::actingAs($authority);
        $this->getJson('/api/v1/voucher-modification-requests')->assertSuccessful()->assertJsonPath('data.0.requested_changes.curp', 'GODE561231HDFABC09');
        $decision = $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request->json('data.id').'/decision', ['decision' => 'AUTHORIZE', 'reason' => 'Validado', 'lock_version' => 1])->assertSuccessful();
        $this->assertNotNull($decision->json('data.token'));
        $this->assertGreaterThanOrEqual(298, now()->diffInSeconds(CarbonImmutable::parse($decision->json('data.expires_at')), false));
        $this->assertLessThanOrEqual(300, now()->diffInSeconds(CarbonImmutable::parse($decision->json('data.expires_at')), false));
        $otherCashier = $this->user('cashier', $this->branch->id);
        Sanctum::actingAs($otherCashier);
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request->json('data.id').'/apply', ['token' => $decision->json('data.token'), 'lock_version' => 2])->assertForbidden()->assertJsonPath('error.code', 'MODIFICATION_TOKEN_ACTOR_MISMATCH');
        Sanctum::actingAs($this->cashier);
        $this->getJson("/api/v1/cashier/vouchers/{$this->voucher->id}")->assertSuccessful()->assertJsonPath('data.modification_request.status', 'AUTHORIZED');
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request->json('data.id').'/apply', ['token' => $decision->json('data.token'), 'lock_version' => 2])->assertSuccessful()->assertJsonPath('data.status', 'APPLIED');
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
        $this->assertSame('5950.0000', $relation->balance);
        $this->assertSame('CONV-TEST', $relation->bank_snapshot['agreement']);
        $this->assertArrayNotHasKey('EARLY_PAYMENT_PERIOD', $relation->header_snapshot['configuration_versions']);
        $this->assertArrayNotHasKey('points', $relation->header_snapshot);
        $this->assertSame($cutoff->utc()->toIso8601String(), $relation->advance_period_start->utc()->toIso8601String());
        $this->assertSame(
            $relation->payment_deadline_at->setTimezone('America/Monterrey')->subDay()->endOfDay()->utc()->toIso8601String(),
            $relation->advance_period_end->utc()->toIso8601String(),
        );
        $this->assertSame('VAL-2026-99999999', $relation->partidas()->firstOrFail()->snapshot['folio']);
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
        $this->assertDatabaseCount('distributor_relation_items', 3);
        $this->assertSame(8, $this->voucher->parcialidades()->count());
    }

    public function test_forzar_corte_usa_la_misma_seleccion_que_el_corte_normal(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-29 10:00:00', 'America/Monterrey'));
        $normal = $this->materializarCalendario($this->voucher, '2026-08-29 10:00:00', 8);
        app(ServicioGeneracionRelacion::class)->generar($this->corteSeptiembre());

        $forzado = $this->materializarCalendario($this->valeDeOtraDistribuidora('VAL-FORCED-CUTOFF'), '2026-08-29 10:00:00', 8);
        Sanctum::actingAs($this->user('general_manager'));
        $this->getJson('/api/v1/operations/current-cutoff')
            ->assertSuccessful()
            ->assertJsonPath('data.summary.operations', 3);
        $this->postIdempotent('/api/v1/operations/force-cutoff', ['motivo' => 'Comparar con corte normal'])
            ->assertSuccessful()
            ->assertJsonPath('data.simulated_cutoff_at', '2026-09-25T06:05:00+00:00');

        $this->assertSame($this->numerosRelacionados($normal), $this->numerosRelacionados($forzado));
        $this->assertSame([1, 2, 3], $this->numerosRelacionados($forzado));
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
        $service->generar(CarbonImmutable::parse('2026-10-25 00:05:00', 'America/Monterrey'));
        $relations = RelacionDistribuidora::query()->orderBy('cutoff_at')->get();

        $this->assertSame([1, 2, 3], $this->numerosRelacionados($this->voucher, $relations[0]));
        $this->assertSame([4, 5], $this->numerosRelacionados($this->voucher, $relations[1]));
    }

    public function test_vale_completamente_terminado_deja_de_aportar_parcialidades(): void
    {
        $this->materializarCalendario($this->voucher, '2026-08-29 10:00:00', 3);
        $service = app(ServicioGeneracionRelacion::class);

        $this->assertSame(1, $service->generar($this->corteSeptiembre()));
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
        $run = (string) Str::uuid();
        DB::table('relation_process_runs')->insert(['id' => $run, 'cutoff_at' => now()->subMonths(3), 'status' => 'COMPLETED', 'attempt' => 1, 'configuration_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([3, 2, 1] as $m) {
            RelacionDistribuidora::create(['process_run_id' => $run, 'distributor_id' => $d->id, 'branch_id' => $this->branch->id, 'cutoff_at' => now()->subMonths($m), 'advance_period_start' => now()->subMonths($m), 'advance_period_end' => now()->subMonths($m), 'payment_deadline_at' => now()->subMonths($m)->endOfMonth(), 'payment_reference' => 'REL-RISK-'.$m, 'financial_status' => 'OVERDUE', 'portfolio_total' => '100', 'misvales_total' => '100', 'balance' => '100', 'header_snapshot' => [], 'bank_snapshot' => []]);
        }
        $lastRelation = RelacionDistribuidora::query()->where('payment_reference', 'REL-RISK-1')->firstOrFail();
        ImportacionArchivoBancario::create(['private_path' => 'private/risk-e2e.xlsx', 'file_hash' => hash('sha256', 'risk-e2e-file'), 'uploaded_by' => $this->cashier->id, 'branch_id' => $this->branch->id, 'status' => 'PROCESSED', 'created_at' => $lastRelation->payment_deadline_at->addHours(2), 'updated_at' => $lastRelation->payment_deadline_at->addHours(2)]);
        $lateFees = app(ServicioEvaluacionRecargo::class);
        $this->assertSame(1, $lateFees->evaluar(now('America/Monterrey')->toImmutable())['applied']);
        $this->assertSame(0, $lateFees->evaluar(now('America/Monterrey')->toImmutable())['applied']);
        $this->assertDatabaseCount('relation_late_fees', 1);
        $service = app(ServicioMorosidadDistribuidora::class);
        $alert = $service->evaluar($d);
        $this->assertNotNull($alert);
        $this->assertDatabaseMissing('distributor_operational_blocks', ['distributor_id' => $d->id, 'status' => 'ACTIVE']);
        $manager = $this->user('branch_manager', $this->branch->id);
        $service->decidir($alert, $manager, true, 'Tres incumplimientos confirmados');
        $this->assertDatabaseHas('distributor_operational_blocks', ['distributor_id' => $d->id, 'type' => 'DELINQUENCY', 'status' => 'ACTIVE']);
        $coordinator = $this->user('coordinator', $this->branch->id);
        CoordinatorDistributorAssignment::create(['coordinator_id' => $coordinator->id, 'distributor_id' => $d->id, 'branch_id' => $this->branch->id, 'status' => 'ACTIVE', 'valid_from' => now()->subDay(), 'valid_to' => null, 'assigned_by' => $manager->id]);
        Sanctum::actingAs($this->distributorUser);
        $this->postIdempotent('/api/v1/vouchers', ['client_id' => $this->voucher->client_id, 'product_version_id' => $this->voucher->product_version_id, 'commission_rate' => 0.10, 'interest_rate' => 0.03, 'insurance_amount' => 100, 'installment_count' => 4])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DISTRIBUTOR_DELINQUENCY_BLOCK');
        try {
            $service->solicitarRetiro($d, $coordinator, 'Aun con saldo vencido');
            $this->fail('Debio exigir regularizacion financiera');
        } catch (\RuntimeException $exception) {
            $this->assertSame('DISTRIBUTOR_NOT_REGULARIZED', $exception->getMessage());
        }

        RelacionDistribuidora::query()->where('distributor_id', $d->id)->update([
            'balance' => '0.0000',
            'financial_status' => 'SETTLED',
            'settled_at' => now(),
        ]);
        $request = $service->solicitarRetiro($d, $coordinator, 'Pagos conciliados y saldo vencido cero');
        $this->assertDatabaseHas('distributor_operational_blocks', ['distributor_id' => $d->id, 'status' => 'ACTIVE']);
        $service->decidirRetiro($request, $manager, true, 'Regularizacion comprobada');
        $this->assertDatabaseHas('distributor_operational_blocks', ['distributor_id' => $d->id, 'status' => 'RELEASED']);

        $this->postIdempotent('/api/v1/vouchers', ['client_id' => $this->voucher->client_id, 'product_version_id' => $this->voucher->product_version_id, 'commission_rate' => 0.10, 'interest_rate' => 0.03, 'insurance_amount' => 100, 'installment_count' => 4])
            ->assertSuccessful();
    }

    public function test_regularizacion_no_desbloquea_hasta_retiro_autorizado(): void
    {
        $d = $this->distributorUser->distribuidora;
        $alert = AlertaRiesgoDistribuidora::create(['distributor_id' => $d->id, 'branch_id' => $this->branch->id, 'type' => 'THREE_CONSECUTIVE_DEFAULTS', 'consecutive_defaults' => 3, 'relation_ids' => [], 'overdue_balance' => '0']);
        $manager = $this->user('branch_manager', $this->branch->id);
        $service = app(ServicioMorosidadDistribuidora::class);
        $service->decidir($alert, $manager, true, 'Historial');
        $coordinator = $this->user('coordinator', $this->branch->id);
        CoordinatorDistributorAssignment::create(['coordinator_id' => $coordinator->id, 'distributor_id' => $d->id, 'branch_id' => $this->branch->id, 'status' => 'ACTIVE', 'valid_from' => now()->subDay(), 'valid_to' => null, 'assigned_by' => $manager->id]);
        $request = $service->solicitarRetiro($d, $coordinator, 'Saldo vencido cero');
        $this->assertDatabaseHas('distributor_operational_blocks', ['distributor_id' => $d->id, 'status' => 'ACTIVE']);
        $service->decidirRetiro($request, $manager, true, 'Autoriza retiro');
        $this->assertDatabaseHas('distributor_operational_blocks', ['distributor_id' => $d->id, 'status' => 'RELEASED']);
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
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $all = array_merge([$headers], $rows);
        $xml = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($all as $ri => $row) {
            $xml .= '<row r="'.($ri + 1).'">';
            foreach (array_values($row) as $ci => $value) {
                $col = chr(65 + $ci);
                $xml .= '<c r="'.$col.($ri + 1).'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1).'</t></is></c>';
            }$xml .= '</row>';
        }$xml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        return new UploadedFile($path, 'banco.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
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
            'BANK_UPLOAD_DEADLINE_TIME' => ['TIME', '08:00'],
            'POST_DUE_EVALUATION_TIME' => ['TIME', '08:30'],
            'LATE_FEE_AMOUNT' => ['DECIMAL', '300.0000'],
            'LOAN_COMMISSION_PERCENTAGE' => ['PERCENTAGE', '0.100000'],
            'INTEREST_RATE_PER_FORTNIGHT' => ['PERCENTAGE', '0.030000'],
            'VOUCHER_INSURANCE_AMOUNT' => ['DECIMAL', '100.0000'],
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
