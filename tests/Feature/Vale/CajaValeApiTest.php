<?php

namespace Tests\Feature\Vale;

use App\Enums\EstadoVale;
use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\AlertaRiesgoDistribuidora;
use App\Models\AsignacionCategoriaDistribuidora;
use App\Models\AsignacionClienteDistribuidora;
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
use App\Services\SolicitudDistribuidora\ProtectorDatosSolicitud;
use App\Services\Conciliacion\ServicioImportacionBancaria;
use App\Services\Excedente\ServicioExcedente;
use App\Services\Recargo\ServicioEvaluacionRecargo;
use App\Services\Relacion\ServicioGeneracionRelacion;
use App\Services\Riesgo\ServicioMorosidadDistribuidora;
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
        $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/cash", ['bank_transaction_number' => 'TX-CASH-001', 'lock_version' => $released->json('data.lock_version')])->assertSuccessful()->assertJsonPath('data.status', 'CASHED');
        $this->assertDatabaseHas('credit_lines', ['id' => $this->voucher->credit_line_id, 'used_balance' => '15000.0000']);
        $this->assertDatabaseHas('credit_line_movements', ['source_id' => $this->voucher->id, 'type' => 'VOUCHER_CASHED']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'VoucherCashed']);
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
            'bank_transaction_number' => 'TX-E2E-FINANCIAL',
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
        $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/cash", ['bank_transaction_number' => 'TX-WRONG', 'lock_version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'VOUCHER_STATUS_INVALID');
        $this->voucher->update(['status' => 'RELEASED']);
        LineaCredito::query()->whereKey($this->voucher->credit_line_id)->update(['used_balance' => '25000.0000']);
        $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/cash", ['bank_transaction_number' => 'TX-NO-CREDIT', 'lock_version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_INSUFFICIENT');
    }

    public function test_transaccion_bancaria_no_se_reutiliza_y_restriccion_se_consume_solo_al_feriar(): void
    {
        $definition = ConfigurationDefinition::query()->create(['key' => 'CREDIT_TOLERANCE_AMOUNT', 'name' => 'Tolerancia', 'value_type' => 'DECIMAL', 'status' => 'ACTIVE', 'created_by' => $this->distributorUser->id]);
        $version = ConfigurationVersion::query()->create(['configuration_definition_id' => $definition->id, 'version' => 1, 'value' => '500.0000', 'status' => 'PUBLISHED', 'effective_from' => now()->subDay(), 'reason' => 'Test', 'created_by' => $this->distributorUser->id, 'published_by' => $this->distributorUser->id, 'published_at' => now()]);
        $restriction = RestriccionUsoCredito::factory()->create(['credit_line_id' => $this->voucher->credit_line_id, 'distributor_id' => $this->voucher->distributor_id, 'status' => 'ACTIVE', 'base_total' => '20000.0000', 'tolerance_amount' => '500.0000', 'configuration_version_id' => $version->id, 'source_id' => (string) Str::uuid()]);
        Sanctum::actingAs($this->cashier);
        $release = $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/release", ['lock_version' => 1])->assertSuccessful();
        $this->assertSame('ACTIVE', $restriction->fresh()->status->value);
        $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/cash", ['bank_transaction_number' => 'TX-UNIQUE-01', 'lock_version' => $release->json('data.lock_version')])->assertSuccessful();
        $this->assertSame('CONSUMED', $restriction->fresh()->status->value);

        $other = $this->voucher->replicate(['id', 'folio', 'created_at', 'updated_at']);
        $other->id = (string) Str::uuid();
        $other->folio = 'VAL-2026-99999998';
        $other->status = EstadoVale::LIBERADO;
        $other->lock_version = 1;
        $other->save();
        $this->postIdempotent("/api/v1/cashier/vouchers/{$other->id}/cash", ['bank_transaction_number' => 'TX-UNIQUE-01', 'lock_version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'BANK_TRANSACTION_ALREADY_USED');
    }

    public function test_token_es_de_un_solo_uso_cajera_campo_y_cinco_minutos(): void
    {
        Sanctum::actingAs($this->cashier);
        $request = $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/modification-requests", ['fields' => ['curp'], 'reason' => 'Discrepancia'])->assertCreated();
        $authority = $this->user('branch_manager', $this->branch->id);
        Sanctum::actingAs($authority);
        $decision = $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request->json('data.id').'/decision', ['decision' => 'AUTHORIZE', 'reason' => 'Validado', 'lock_version' => 1])->assertSuccessful();
        $this->assertNotNull($decision->json('data.token'));
        $this->assertGreaterThanOrEqual(298, now()->diffInSeconds(CarbonImmutable::parse($decision->json('data.expires_at')), false));
        $this->assertLessThanOrEqual(300, now()->diffInSeconds(CarbonImmutable::parse($decision->json('data.expires_at')), false));
        $otherCashier = $this->user('cashier', $this->branch->id);
        Sanctum::actingAs($otherCashier);
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request->json('data.id').'/apply', ['token' => $decision->json('data.token'), 'lock_version' => 2, 'changes' => ['curp' => 'GODE561231HDFABC09']])->assertForbidden()->assertJsonPath('error.code', 'MODIFICATION_TOKEN_ACTOR_MISMATCH');
        Sanctum::actingAs($this->cashier);
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request->json('data.id').'/apply', ['token' => $decision->json('data.token'), 'lock_version' => 2, 'changes' => ['curp' => 'GODE561231HDFABC09']])->assertSuccessful()->assertJsonPath('data.status', 'APPLIED');
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request->json('data.id').'/apply', ['token' => $decision->json('data.token'), 'lock_version' => 3, 'changes' => ['curp' => 'GODE561231HDFABC09']])->assertStatus(409)->assertJsonPath('error.code', 'MODIFICATION_TOKEN_USED');
    }

    public function test_token_invalido_vencido_y_campo_no_autorizado_fallan(): void
    {
        Sanctum::actingAs($this->cashier);
        $request = $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/modification-requests", ['fields' => ['curp'], 'reason' => 'Error'])->json('data');
        $authority = $this->user('branch_manager', $this->branch->id);
        Sanctum::actingAs($authority);
        $decision = $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request['id'].'/decision', ['decision' => 'AUTHORIZE', 'reason' => 'SÃ­', 'lock_version' => 1])->json('data');
        Sanctum::actingAs($this->cashier);
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request['id'].'/apply', ['token' => 'DEADBEEF', 'lock_version' => 2, 'changes' => ['curp' => 'GODE561231HDFABC09']])->assertStatus(422)->assertJsonPath('error.code', 'MODIFICATION_TOKEN_INVALID');
        $address = ['street' => 'Uno', 'exterior_number' => '1', 'neighborhood' => 'Centro', 'postal_code' => '64000', 'municipality' => 'Monterrey', 'city' => 'Monterrey', 'state' => 'Nuevo LeÃ³n', 'country' => 'MX'];
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request['id'].'/apply', ['token' => $decision['token'], 'lock_version' => 2, 'changes' => ['address' => $address]])->assertForbidden()->assertJsonPath('error.code', 'MODIFICATION_FIELD_NOT_AUTHORIZED');
        SolicitudModificacionVale::query()->whereKey($request['id'])->update(['token_expires_at' => now()->subSecond()]);
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request['id'].'/apply', ['token' => $decision['token'], 'lock_version' => 2, 'changes' => ['curp' => 'GODE561231HDFABC09']])->assertStatus(409)->assertJsonPath('error.code', 'MODIFICATION_TOKEN_EXPIRED');
    }

    public function test_corte_genera_una_relacion_con_parcialidades_antiguas_snapshots_y_fecha_limite(): void
    {
        $cutoff = CarbonImmutable::parse('2027-01-25 00:05:00', 'America/Monterrey');
        $this->voucher->update(['status' => 'CASHED']);
        $this->voucher->parcialidades()->createMany([
            ['number' => 1, 'capital' => '2500.0000', 'loan_commission' => '250.0000', 'interest' => '200.0000', 'insurance' => '25.0000', 'distributor_profit' => '125.0000', 'misvales_payment' => '2975.0000', 'client_payment' => '3100.0000', 'due_at' => $cutoff->subMonths(2)],
            ['number' => 2, 'capital' => '2500.0000', 'loan_commission' => '250.0000', 'interest' => '200.0000', 'insurance' => '25.0000', 'distributor_profit' => '125.0000', 'misvales_payment' => '2975.0000', 'client_payment' => '3100.0000', 'due_at' => $cutoff->addDay()],
        ]);

        $service = app(ServicioGeneracionRelacion::class);
        $this->assertSame(1, $service->generar($cutoff));
        $this->assertSame(0, $service->generar($cutoff));
        $this->assertDatabaseCount('distributor_relations', 1);
        $this->assertDatabaseCount('distributor_relation_items', 1);
        $relation = RelacionDistribuidora::query()->firstOrFail();
        $this->assertSame('2975.0000', $relation->balance);
                $this->assertSame('CONV-TEST', $relation->bank_snapshot['agreement']);
        $this->assertArrayHasKey('EARLY_PAYMENT_PERIOD', $relation->header_snapshot['configuration_versions']);
        $this->assertArrayNotHasKey('points', $relation->header_snapshot);
        $this->assertSame('VAL-2026-99999999', $relation->partidas()->firstOrFail()->snapshot['folio']);
        $this->assertDatabaseCount('relation_process_runs', 2);
    }

    public function test_relacion_respeta_consulta_descarga_y_administrador_solo_lectura(): void
    {
        $this->voucher->update(['status' => 'CASHED']);
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

    public function test_corte_falla_cerrado_sin_periodo_anticipado_o_banco_publicado(): void
    {
        ConfigurationVersion::query()
            ->whereHas('definition', fn ($query) => $query->where('key', 'EARLY_PAYMENT_PERIOD'))
            ->delete();
        Cache::forget('configuracion:EARLY_PAYMENT_PERIOD');
        $this->expectExceptionMessage('RELATION_CONFIGURATION_INCOMPLETE');
        app(ServicioGeneracionRelacion::class)->generar(CarbonImmutable::now('America/Monterrey'));
    }

    public function test_xlsx_concilia_abono_liquidacion_excedente_no_conciliado_y_rechaza_doble_archivo(): void
    {
        Storage::fake('local');
        $this->voucher->update(['status' => 'CASHED']);
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
        $this->expectExceptionMessage('BANK_FILE_ALREADY_IMPORTED');
        app(ServicioImportacionBancaria::class)->importar($file, $this->cashier, $this->branch->id);
    }

    public function test_xlsx_sin_columna_se_rechaza_completo_y_registra_motivo(): void
    {
        Storage::fake('local');
        $file = $this->xlsx([], ['referencia de pago', 'monto', 'fecha', 'folio bancario']);
        try {
            app(ServicioImportacionBancaria::class)->importar($file, $this->cashier, $this->branch->id);
            $this->fail('DebiÃ³ fallar');
        } catch (\RuntimeException $e) {
            $this->assertSame('BANK_FILE_REQUIRED_COLUMNS_MISSING', $e->getMessage());
        }
        $this->assertDatabaseHas('bank_file_imports', ['status' => 'REJECTED', 'error' => 'BANK_FILE_REQUIRED_COLUMNS_MISSING']);
        $this->assertDatabaseCount('bank_movements', 0);
    }

    public function test_recargo_es_unico_y_se_difiere_sin_archivo_bancario_valido(): void
    {
        $this->voucher->update(['status' => 'CASHED']);
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
        $this->voucher->update(['status' => 'CASHED']);
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

        $movement = MovimientoBancario::create(['import_id' => ImportacionArchivoBancario::firstOrFail()->id, 'row_number' => 3, 'original_row' => [], 'payment_reference' => $relation->payment_reference, 'amount' => '300', 'paid_at' => now(), 'bank_folio' => 'BANK-REFUND', 'concept' => 'Excedente devoluciÃ³n', 'classification' => 'SURPLUS', 'relation_id' => $relation->id, 'surplus_amount' => '300']);
        $refundSurplus = ExcedenteDistribuidora::create(['distributor_id' => $this->distributorUser->distribuidora->id, 'bank_movement_id' => $movement->id, 'original_amount' => '300', 'available_amount' => '300']);
        $request = app(ServicioExcedente::class)->solicitarDevolucion($refundSurplus, $this->distributorUser);
        $this->assertSame('0.0000', $refundSurplus->fresh()->available_amount);
        $this->assertSame('300.0000', $refundSurplus->fresh()->reserved_amount);
        $manager = $this->user('branch_manager', $this->branch->id);
        Sanctum::actingAs($manager);
        $this->postIdempotent('/api/v1/refund-requests/'.$request->id.'/decision', ['decision' => 'AUTHORIZE', 'reason' => 'Procede'])->assertSuccessful()->assertJsonPath('data.status', 'AUTHORIZED');
        $media = MediaFile::create(['file_type' => 'DOCUMENT', 'disk' => 'local', 'path' => 'private/refund.pdf', 'original_name' => 'refund.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => hash('sha256', 'refund'), 'uploaded_by' => $this->cashier->id]);
        Sanctum::actingAs($this->cashier);
        $this->postIdempotent('/api/v1/refund-requests/'.$request->id.'/execute', ['method' => 'TRANSFERENCIA_EXTERNA', 'reference' => 'REF-001', 'evidence_media_id' => $media->id])->assertSuccessful()->assertJsonPath('data.status', 'EXECUTED');
        $this->assertSame('REFUNDED', $refundSurplus->fresh()->status);
        $this->assertSame('0.0000', $refundSurplus->fresh()->reserved_amount);
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
            'PAYMENT_DAYS_AFTER_CUT' => ['INTEGER', 20],
            'PAYMENT_DEADLINE_TIME' => ['TIME', '23:59:59'],
            'EARLY_PAYMENT_PERIOD' => ['JSON', ['start' => 0, 'end' => 10]],
            'RELATION_PAYMENT_BANK' => ['JSON', ['name' => 'Banco configurado', 'beneficiary' => 'MisVales', 'agreement' => 'CONV-TEST', 'clabe' => '012345678901234567']],
            'BANK_UPLOAD_DEADLINE_TIME' => ['TIME', '08:00'],
            'POST_DUE_EVALUATION_TIME' => ['TIME', '08:30'],
            'LATE_FEE_AMOUNT' => ['DECIMAL', '300.0000'],
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
                'effective_from' => now()->subDay(),
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
