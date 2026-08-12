<?php

namespace Tests\Feature\Vale;

use App\Enums\EstadoVale;
use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\AsignacionCategoriaDistribuidora;
use App\Models\AsignacionClienteDistribuidora;
use App\Models\Branch;
use App\Models\Category;
use App\Models\CategoryVersion;
use App\Models\Cliente;
use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Models\Distribuidora;
use App\Models\LineaCredito;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\RelacionDistribuidora;
use App\Models\RestriccionUsoCredito;
use App\Models\Role;
use App\Models\SolicitudModificacionVale;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\Vale;
use App\Services\Relacion\ServicioGeneracionRelacion;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
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
        $line = LineaCredito::factory()->create(['distributor_id' => $distributor->id, 'total_authorized' => '30000.0000', 'used_balance' => '5000.0000']);
        $client = Cliente::factory()->create(['created_by' => $this->distributorUser->id]);
        AsignacionClienteDistribuidora::factory()->create(['client_id' => $client->id, 'distributor_id' => $distributor->id, 'branch_id' => $this->branch->id, 'starts_at' => now()->subDay(), 'ends_at' => null, 'assigned_by' => $this->distributorUser->id]);
        $category = Category::query()->create(['code' => 'CASH-CAT', 'status' => 'ACTIVE', 'created_by' => $this->distributorUser->id]);
        $categoryVersion = CategoryVersion::query()->create(['category_id' => $category->id, 'version' => 1, 'name' => 'Base', 'profit_percentage' => '0.050000', 'status' => 'PUBLISHED', 'effective_from' => now()->subDay(), 'reason' => 'Test', 'created_by' => $this->distributorUser->id, 'published_by' => $this->distributorUser->id, 'published_at' => now()]);
        AsignacionCategoriaDistribuidora::query()->create(['distributor_id' => $distributor->id, 'category_version_id' => $categoryVersion->id, 'starts_at' => now()->subDay(), 'assigned_by' => $this->distributorUser->id]);
        $product = Product::query()->create(['code' => 'CASH-10000', 'status' => 'ACTIVE', 'created_by' => $this->distributorUser->id]);
        $productVersion = ProductVersion::query()->create(['product_id' => $product->id, 'version' => 1, 'name' => 'Vale caja', 'nominal_amount' => '10000.0000', 'loan_commission_percentage' => '0.100000', 'simple_interest_percentage' => '0.020000', 'insurance_amount' => '100.0000', 'fortnights_count' => 4, 'status' => 'PUBLISHED', 'effective_from' => now()->subDay(), 'reason' => 'Test', 'created_by' => $this->distributorUser->id, 'published_by' => $this->distributorUser->id, 'published_at' => now()]);
        $this->voucher = Vale::query()->create(['folio' => 'VAL-2026-99999999', 'type' => 'PREVALE', 'status' => EstadoVale::GENERADO, 'client_id' => $client->id, 'distributor_id' => $distributor->id, 'branch_id' => $this->branch->id, 'credit_line_id' => $line->id, 'product_id' => $product->id, 'product_version_id' => $productVersion->id, 'category_version_id' => $categoryVersion->id, 'capital' => '10000.0000', 'loan_commission_percentage' => '0.100000', 'loan_commission_amount' => '1000.0000', 'simple_interest_percentage' => '0.020000', 'fortnights_count' => 4, 'insurance_amount' => '100.0000', 'interest_total' => '800.0000', 'misvales_total' => '11900.0000', 'misvales_payment_per_fortnight' => '2975.0000', 'distributor_profit_percentage' => '0.050000', 'distributor_profit_total' => '500.0000', 'distributor_profit_per_fortnight' => '125.0000', 'client_payment_per_fortnight' => '3100.0000', 'client_total' => '12400.0000', 'financial_snapshot' => [], 'created_by' => $this->distributorUser->id, 'generated_at' => now()]);
    }

    public function test_cajera_busca_libera_y_feria_incrementando_saldo(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->getJson('/api/v1/cashier/vouchers/search?search=99999999')->assertSuccessful()->assertJsonPath('data.0.folio', $this->voucher->folio);
        $released = $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/release", ['lock_version' => 1])->assertSuccessful()->assertJsonPath('data.status', 'RELEASED');
        $this->postIdempotent("/api/v1/cashier/vouchers/{$this->voucher->id}/cash", ['bank_transaction_number' => 'TX-CASH-001', 'lock_version' => $released->json('data.lock_version')])->assertSuccessful()->assertJsonPath('data.status', 'CASHED');
        $this->assertDatabaseHas('credit_lines', ['id' => $this->voucher->credit_line_id, 'used_balance' => '15000.0000']);
        $this->assertDatabaseHas('credit_line_movements', ['source_id' => $this->voucher->id, 'type' => 'VOUCHER_CASHED']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'VoucherCashed']);
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
        $this->assertGreaterThanOrEqual(299, now()->diffInSeconds(CarbonImmutable::parse($decision->json('data.expires_at')), false));
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
        $decision = $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request['id'].'/decision', ['decision' => 'AUTHORIZE', 'reason' => 'Sí', 'lock_version' => 1])->json('data');
        Sanctum::actingAs($this->cashier);
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request['id'].'/apply', ['token' => 'DEADBEEF', 'lock_version' => 2, 'changes' => ['curp' => 'GODE561231HDFABC09']])->assertStatus(422)->assertJsonPath('error.code', 'MODIFICATION_TOKEN_INVALID');
        $address = ['street' => 'Uno', 'exterior_number' => '1', 'neighborhood' => 'Centro', 'postal_code' => '64000', 'municipality' => 'Monterrey', 'city' => 'Monterrey', 'state' => 'Nuevo León', 'country' => 'MX'];
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request['id'].'/apply', ['token' => $decision['token'], 'lock_version' => 2, 'changes' => ['address' => $address]])->assertForbidden()->assertJsonPath('error.code', 'MODIFICATION_FIELD_NOT_AUTHORIZED');
        SolicitudModificacionVale::query()->whereKey($request['id'])->update(['token_expires_at' => now()->subSecond()]);
        $this->postIdempotent('/api/v1/voucher-modification-requests/'.$request['id'].'/apply', ['token' => $decision['token'], 'lock_version' => 2, 'changes' => ['curp' => 'GODE561231HDFABC09']])->assertStatus(409)->assertJsonPath('error.code', 'MODIFICATION_TOKEN_EXPIRED');
    }

    public function test_corte_genera_una_relacion_con_parcialidades_antiguas_snapshots_y_fecha_limite(): void
    {
        config()->set('relations.advance_period_days', 10);
        config()->set('relations.bank', ['name' => 'Banco configurado', 'beneficiary' => 'MisVales', 'clabe' => '012345678901234567']);
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
        $this->assertSame('2027-02-14 23:59:59', $relation->payment_deadline_at->setTimezone('America/Monterrey')->format('Y-m-d H:i:s'));
        $this->assertSame('VAL-2026-99999999', $relation->partidas()->firstOrFail()->snapshot['folio']);
        $this->assertDatabaseCount('relation_process_runs', 2);
    }

    public function test_relacion_respeta_consulta_descarga_y_administrador_solo_lectura(): void
    {
        config()->set('relations.advance_period_days', 10);
        config()->set('relations.bank', ['name' => 'Banco configurado', 'beneficiary' => 'MisVales', 'clabe' => '012345678901234567']);
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
        config()->set('relations.advance_period_days', null);
        $this->expectExceptionMessage('RELATION_CONFIGURATION_INCOMPLETE');
        app(ServicioGeneracionRelacion::class)->generar(CarbonImmutable::now('America/Monterrey'));
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
