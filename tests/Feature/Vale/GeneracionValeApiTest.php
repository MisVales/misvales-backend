<?php

namespace Tests\Feature\Vale;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\AsignacionCategoriaDistribuidora;
use App\Models\AsignacionClienteDistribuidora;
use App\Models\BloqueoOperativoDistribuidora;
use App\Models\Branch;
use App\Models\Category;
use App\Models\CategoryVersion;
use App\Models\Cliente;
use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Models\Distribuidora;
use App\Models\LineaCredito;
use App\Models\MovimientoCarteraCliente;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\RestriccionUsoCredito;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\Vale;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class GeneracionValeApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Distribuidora $distribuidora;

    private Cliente $cliente;

    private ProductVersion $producto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
        $branch = Branch::factory()->create();
        $this->actor = $this->usuarioConRol('distributor', $branch->id);
        $this->distribuidora = Distribuidora::factory()->active()->create(['user_id' => $this->actor->id, 'branch_id' => $branch->id]);
        LineaCredito::factory()->create(['distributor_id' => $this->distribuidora->id, 'total_authorized' => '30000.0000', 'used_balance' => '5000.0000']);
        $this->cliente = Cliente::factory()->create(['created_by' => $this->actor->id]);
        AsignacionClienteDistribuidora::query()->create(['client_id' => $this->cliente->id, 'distributor_id' => $this->distribuidora->id, 'branch_id' => $branch->id, 'starts_at' => now()->subDay(), 'assigned_by' => $this->actor->id, 'reason' => 'Prueba']);

        $category = Category::query()->create(['code' => 'CAT-TEST', 'status' => 'ACTIVE', 'created_by' => $this->actor->id]);
        $categoryVersion = CategoryVersion::query()->create(['category_id' => $category->id, 'version' => 1, 'name' => 'Base', 'profit_percentage' => '0.050000', 'status' => 'PUBLISHED', 'effective_from' => now()->subDay(), 'reason' => 'Prueba', 'created_by' => $this->actor->id, 'published_by' => $this->actor->id, 'published_at' => now()]);
        AsignacionCategoriaDistribuidora::query()->create(['distributor_id' => $this->distribuidora->id, 'category_version_id' => $categoryVersion->id, 'starts_at' => now()->subDay(), 'assigned_by' => $this->actor->id, 'reason' => 'Prueba']);
        $product = Product::query()->create(['code' => 'VAL-10000', 'status' => 'ACTIVE', 'created_by' => $this->actor->id]);
        $this->producto = ProductVersion::query()->create(['product_id' => $product->id, 'version' => 1, 'name' => 'Vale 10000', 'nominal_amount' => '10000.0000', 'loan_commission_percentage' => '0.100000', 'simple_interest_percentage' => '0.020000', 'insurance_amount' => '100.0000', 'fortnights_count' => 4, 'status' => 'PUBLISHED', 'effective_from' => now()->subDay(), 'reason' => 'Prueba', 'created_by' => $this->actor->id, 'published_by' => $this->actor->id, 'published_at' => now()]);
        Sanctum::actingAs($this->actor);
    }

    public function test_primer_vale_es_prevale_y_materializa_snapshot_y_parcialidades(): void
    {
        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/vouchers', ['client_id' => $this->cliente->id, 'product_version_id' => $this->producto->id]);
        $response->assertSuccessful()->assertJsonPath('data.type', 'PREVALE')->assertJsonPath('data.status', 'GENERATED')
            ->assertJsonPath('data.capital', '10000.0000')->assertJsonPath('data.misvales_total', '11900.0000')
            ->assertJsonPath('data.distributor_profit_total', '500.0000')->assertJsonCount(4, 'data.installments');
        $this->assertMatchesRegularExpression('/^VAL-\d{4}-\d{8}$/', $response->json('data.folio'));
        $this->assertDatabaseCount('vouchers', 1);
        $this->assertDatabaseCount('voucher_installments', 4);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'VoucherGenerated']);
    }

    public function test_segundo_vale_global_del_cliente_es_digital_y_folios_no_se_reutilizan(): void
    {
        $primero = $this->crear()->json('data.folio');
        $segundo = $this->crear()->assertJsonPath('data.type', 'VALE_DIGITAL')->json('data.folio');
        $this->assertNotSame($primero, $segundo);
    }

    public function test_transferencia_no_reinicia_historial_de_prevale(): void
    {
        $this->crear()->assertJsonPath('data.type', 'PREVALE');
        $asignacion = AsignacionClienteDistribuidora::query()->where('client_id', $this->cliente->id)->whereNull('ends_at')->firstOrFail();
        $asignacion->update(['ends_at' => now(), 'reason' => 'Transferencia de prueba']);
        $branch = Branch::factory()->create();
        $nuevoActor = $this->usuarioConRol('distributor', $branch->id);
        $nuevaDistribuidora = Distribuidora::factory()->active()->create(['user_id' => $nuevoActor->id, 'branch_id' => $branch->id]);
        LineaCredito::factory()->create(['distributor_id' => $nuevaDistribuidora->id, 'total_authorized' => '30000.0000', 'used_balance' => '0.0000']);
        $versionCategoria = AsignacionCategoriaDistribuidora::query()->where('distributor_id', $this->distribuidora->id)->firstOrFail()->category_version_id;
        AsignacionCategoriaDistribuidora::query()->create(['distributor_id' => $nuevaDistribuidora->id, 'category_version_id' => $versionCategoria, 'starts_at' => now()->subMinute(), 'assigned_by' => $nuevoActor->id, 'reason' => 'Transferencia']);
        AsignacionClienteDistribuidora::query()->create(['client_id' => $this->cliente->id, 'distributor_id' => $nuevaDistribuidora->id, 'branch_id' => $branch->id, 'starts_at' => now(), 'assigned_by' => $nuevoActor->id, 'reason' => 'Transferencia']);
        Sanctum::actingAs($nuevoActor);
        $this->crear()->assertSuccessful()->assertJsonPath('data.type', 'VALE_DIGITAL');
    }

    public function test_secuencia_postgresql_reserva_folios_no_reutilizables(): void
    {
        $valores = collect(range(1, 20))->map(fn (): string => $this->crear()->assertSuccessful()->json('data.folio'));
        $this->assertCount(20, $valores->unique());
        $this->assertDatabaseCount('vouchers', 20);
    }

    public function test_bloqueo_de_morosidad_impide_generar_pero_adeudo_informativo_no_se_consulta(): void
    {
        BloqueoOperativoDistribuidora::query()->create(['distributor_id' => $this->distribuidora->id, 'type' => 'DELINQUENCY', 'status' => 'ACTIVE', 'source_type' => 'RISK_CASE', 'source_id' => (string) Str::uuid(), 'reason' => 'Morosidad', 'starts_at' => now(), 'created_by' => $this->actor->id]);
        $this->crear()->assertStatus(409)->assertJsonPath('error.code', 'DISTRIBUTOR_DELINQUENCY_BLOCK');
    }

    public function test_adeudo_informativo_vencido_del_cliente_no_bloquea(): void
    {
        MovimientoCarteraCliente::factory()->create(['client_id' => $this->cliente->id, 'distributor_id' => $this->distribuidora->id, 'entry_type' => 'DEBT', 'amount' => '3000.0000', 'informational_status' => 'PENDING', 'due_date' => now()->subDay(), 'recorded_by' => $this->actor->id]);
        $this->crear()->assertSuccessful()->assertJsonPath('data.type', 'PREVALE');
    }

    public function test_restriccion_del_cincuenta_rechaza_producto_fuera_del_rango(): void
    {
        $definition = ConfigurationDefinition::query()->create(['key' => 'CREDIT_TOLERANCE_AMOUNT', 'name' => 'Tolerancia', 'value_type' => 'DECIMAL', 'status' => 'ACTIVE', 'created_by' => $this->actor->id]);
        $version = ConfigurationVersion::query()->create(['configuration_definition_id' => $definition->id, 'version' => 1, 'value' => '500.0000', 'status' => 'PUBLISHED', 'effective_from' => now()->subDay(), 'reason' => 'Prueba', 'created_by' => $this->actor->id, 'published_by' => $this->actor->id, 'published_at' => now()]);
        $line = LineaCredito::query()->where('distributor_id', $this->distribuidora->id)->firstOrFail();
        RestriccionUsoCredito::factory()->create(['credit_line_id' => $line->id, 'distributor_id' => $this->distribuidora->id, 'status' => 'ACTIVE', 'base_total' => '30000.0000', 'tolerance_amount' => '500.0000', 'configuration_version_id' => $version->id, 'source_id' => (string) Str::uuid()]);
        $this->crear()->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_50_PERCENT_RULE_NOT_SATISFIED');
    }

    public function test_snapshot_no_cambia_cuando_cambia_el_catalogo(): void
    {
        $id = $this->crear()->json('data.id');
        $this->producto->update(['name' => 'Nombre posterior', 'loan_commission_percentage' => '0.200000']);
        $vale = Vale::query()->findOrFail($id);
        $this->assertSame('Vale 10000', $vale->financial_snapshot['product_version']['name']);
        $this->assertSame('0.100000', $vale->financial_snapshot['calculation']['loan_commission_percentage']);
    }

    public function test_administrador_solo_lectura_no_puede_generar(): void
    {
        Sanctum::actingAs($this->usuarioConRol('admin'));
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/vouchers', ['client_id' => $this->cliente->id, 'product_version_id' => $this->producto->id])->assertForbidden();
    }

    public function test_producto_inactivo_saldo_insuficiente_y_cliente_ajeno_fallan_cerrado(): void
    {
        $this->producto->product->update(['status' => 'INACTIVE']);
        $this->crear()->assertStatus(409)->assertJsonPath('error.code', 'PRODUCT_NOT_AVAILABLE');
        $this->producto->product->update(['status' => 'ACTIVE']);
        LineaCredito::query()->where('distributor_id', $this->distribuidora->id)->update(['used_balance' => '25000.0000']);
        $this->crear()->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_INSUFFICIENT');
        $ajeno = Cliente::factory()->create();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/vouchers', ['client_id' => $ajeno->id, 'product_version_id' => $this->producto->id])->assertStatus(404)->assertJsonPath('error.code', 'CLIENT_NOT_ASSIGNED_TO_DISTRIBUTOR');
    }

    private function crear()
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/vouchers', ['client_id' => $this->cliente->id, 'product_version_id' => $this->producto->id]);
    }

    private function usuarioConRol(string $rol, ?string $branchId = null): User
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $role = Role::query()->where('code', $rol)->firstOrFail();
        UserRoleScope::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'branch_id' => $branchId, 'assigned_by_user_id' => $user->id]);

        return $user;
    }
}
