<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ApplicationAuthorizationDecision;
use App\Enums\ApplicationEvaluationResult;
use App\Enums\ApplicationStatus;
use App\Enums\EstadoDistribuidora;
use App\Enums\TipoMovimientoLineaCredito;
use App\Enums\VerificationVisitResult;
use App\Enums\VerificationVisitStatus;
use App\Models\AccountInvitation;
use App\Models\ApplicationAuthorization;
use App\Models\ApplicationEvaluation;
use App\Models\ApplicationStateTransition;
use App\Models\AsignacionCategoriaDistribuidora;
use App\Models\Category;
use App\Models\CategoryVersion;
use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\DatosPersonalesSolicitud;
use App\Models\Distribuidora;
use App\Models\DistributorApplication;
use App\Models\DomicilioSolicitud;
use App\Models\FamiliarSolicitud;
use App\Models\LineaCredito;
use App\Models\MovimientoLineaCredito;
use App\Models\RestriccionUsoCredito;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\VerificationVisit;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use App\Services\SolicitudDistribuidora\ProtectorDatosSolicitud;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class DemoDistributorApplicationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        DB::transaction(function (): void {
            $existing = DistributorApplication::query()
                ->where('application_number', 'SOL-2026-900001')
                ->first();

            if ($existing !== null) {
                return;
            }

            $branch = BranchRecord::query()
                ->where('code', 'MATAMOROS')
                ->where('status', 'ACTIVE')
                ->firstOrFail();

            $coordinator = User::query()
                ->where('normalized_email', 'coordinador@gmail.com')
                ->firstOrFail();

            $verifier = User::query()
                ->where('normalized_email', 'verificador@gmail.com')
                ->firstOrFail();

            $branchManager = User::query()
                ->where('normalized_email', 'gerentesucursal@gmail.com')
                ->firstOrFail();

            $generalManager = User::query()
                ->where('normalized_email', 'gerentegeneral@gmail.com')
                ->firstOrFail();

            $protector = app(ProtectorDatosSolicitud::class);

            // ── 1. Solicitud (status ACTIVE = ya terminó todo el flujo) ──

            $solicitud = DistributorApplication::forceCreate([
                'application_number' => 'SOL-2026-900001',
                'branch_id' => $branch->id,
                'coordinator_id' => $coordinator->id,
                'status' => ApplicationStatus::ACTIVE,
                'section_declarations' => [
                    'personal_data' => 'COMPLETED',
                    'residence' => 'COMPLETED',
                    'partner' => 'NOT_APPLICABLE',
                    'children' => 'COMPLETED',
                    'family_references' => 'COMPLETED',
                    'vehicles' => 'NOT_APPLICABLE',
                    'assets' => 'NOT_APPLICABLE',
                    'liabilities' => 'NOT_APPLICABLE',
                    'employment' => 'NOT_APPLICABLE',
                    'commercial_credits' => 'NOT_APPLICABLE',
                ],
                'pending_sections' => null,
                'created_by' => $coordinator->id,
                'submitted_by' => $coordinator->id,
                'submitted_at' => now()->subDays(7),
                'lock_version' => 1,
            ]);

            // ── 2. Datos personales (cifrados) ──

            $curp = 'TESA930515HRCLA04';
            $rfc = 'TESA930515XXX';
            $ine = '1234567890123';
            $email = 'alberto.trejo.demo@gmail.com';

            DatosPersonalesSolicitud::forceCreate([
                'application_id' => $solicitud->id,
                'first_name' => 'Alberto',
                'first_last_name' => 'Trejo',
                'second_last_name' => 'Saucedo',
                'birth_date' => '1993-05-15',
                'birth_place' => 'Matamoros, Coahuila, Mexico',
                'birth_state' => 'Coahuila',
                'birth_city' => 'Matamoros',
                'email' => $email,
                'phone_number' => '8688001234',
                'official_id_type' => 'INE',
                'curp_ciphertext' => $protector->cifrarCurp($curp),
                'curp_hmac' => $protector->generarHmacCurp($curp),
                'rfc_ciphertext' => $protector->cifrarRfc($rfc),
                'rfc_hmac' => $protector->generarHmacRfc($rfc),
                'official_id_number_ciphertext' => $protector->cifrarIdentificacion($ine),
                'official_id_number_hmac' => $protector->generarHmacIdentificacion($ine),
            ]);

            // ── 3. Domicilio ──

            DomicilioSolicitud::forceCreate([
                'application_id' => $solicitud->id,
                'is_current' => true,
                'street' => 'Calle Pabellon',
                'exterior_number' => '28',
                'interior_number' => null,
                'neighborhood' => 'Centro',
                'postal_code' => '27440',
                'municipality' => 'Matamoros',
                'city' => 'Matamoros',
                'state' => 'Coahuila',
                'country' => 'MX',
                'housing_tenure' => 'OWNED',
                'financing_status' => 'PAID',
                'width_meters' => 10.00,
                'length_meters' => 20.00,
                'built_area_square_meters' => 150.00,
            ]);

            // ── 4. Familiares (hijos + referencias) ──

            FamiliarSolicitud::forceCreate([
                'application_id' => $solicitud->id,
                'relationship' => 'CHILD',
                'first_name' => 'Sofia',
                'first_last_name' => 'Trejo',
                'second_last_name' => 'Lopez',
                'birth_date' => '2018-03-12',
                'declared_age' => 8,
                'school_name' => 'Primaria Benito Juarez',
                'is_family_reference' => false,
            ]);

            FamiliarSolicitud::forceCreate([
                'application_id' => $solicitud->id,
                'relationship' => 'MOTHER',
                'first_name' => 'Maria',
                'first_last_name' => 'Saucedo',
                'second_last_name' => 'Garcia',
                'birth_date' => '1965-11-20',
                'declared_age' => 60,
                'school_name' => null,
                'is_family_reference' => true,
                'details_payload' => json_encode([
                    'phone' => '8688005678',
                    'address' => 'Calle Hidalgo 15, Centro, Matamoros, Coah.',
                ]),
            ]);

            FamiliarSolicitud::forceCreate([
                'application_id' => $solicitud->id,
                'relationship' => 'SIBLING',
                'first_name' => 'Carlos',
                'first_last_name' => 'Trejo',
                'second_last_name' => 'Saucedo',
                'birth_date' => '1990-07-08',
                'declared_age' => 36,
                'school_name' => null,
                'is_family_reference' => true,
                'details_payload' => json_encode([
                    'phone' => '8688009012',
                    'address' => 'Calle Morelos 42, Centro, Matamoros, Coah.',
                ]),
            ]);

            // ── 5. Visita de verificacion (COMPLETED + FAVORABLE) ──

            $visit = VerificationVisit::forceCreate([
                'application_id' => $solicitud->id,
                'verifier_id' => $verifier->id,
                'assigned_by' => $coordinator->id,
                'assigned_at' => now()->subDays(5),
                'scheduled_for' => now()->subDays(4),
                'started_at' => now()->subDays(4)->setTime(10, 0),
                'completed_at' => now()->subDays(4)->setTime(11, 0),
                'visited_at' => now()->subDays(4)->setTime(10, 30),
                'status' => VerificationVisitStatus::COMPLETED,
                'result' => VerificationVisitResult::FAVORABLE,
                'observations' => 'Domicilio validado. Solicitante presente y cooperativo.',
                'lock_version' => 1,
            ]);

            // ── 6. Evaluacion del coordinador (COMPLIES) ──

            ApplicationEvaluation::forceCreate([
                'application_id' => $solicitud->id,
                'verification_visit_id' => $visit->id,
                'result' => ApplicationEvaluationResult::COMPLIES,
                'reason' => 'Cumple satisfactoriamente con todos los requisitos de verificacion.',
                'evaluated_by' => $coordinator->id,
                'evaluated_at' => now()->subDays(3),
            ]);

            // ── 7. Autorizacion del gerente (APPROVED, $50,000) ──

            $creditAmount = '50000.0000';

            $authorization = ApplicationAuthorization::forceCreate([
                'application_id' => $solicitud->id,
                'decision' => ApplicationAuthorizationDecision::APPROVED,
                'initial_credit_line_amount' => $creditAmount,
                'reason' => 'Distribuidora demo aprobada con linea de credito de $50,000.',
                'authorized_by' => $generalManager->id,
                'authorized_at' => now()->subDays(2),
            ]);

            // ── 8. Usuario distribuidora (ACTIVE, listo para login) ──

            $distributorUser = User::forceCreate([
                'name' => 'Alberto Trejo Saucedo',
                'email' => $email,
                'normalized_email' => $email,
                'password' => Hash::make('1234'),
                'state' => 'ACTIVE',
                'email_verified_at' => now(),
                'password_changed_at' => now(),
                'lock_version' => 0,
            ]);

            // ── 9. Registro Distribuidora (ACTIVE) ──

            $distribuidora = Distribuidora::forceCreate([
                'application_id' => $solicitud->id,
                'user_id' => $distributorUser->id,
                'distributor_number' => 'DIS-2026-900001',
                'branch_id' => $branch->id,
                'status' => EstadoDistribuidora::ACTIVA,
                'activated_at' => now()->subDay(),
                'activated_by' => $distributorUser->id,
                'lock_version' => 1,
            ]);

            // ── 10. Rol de distribuidora ──

            $distributorRole = Role::query()->where('code', 'distributor')->firstOrFail();

            UserRoleScope::forceCreate([
                'user_id' => $distributorUser->id,
                'role_id' => $distributorRole->id,
                'branch_id' => $branch->id,
                'scope_type' => 'DISTRIBUTOR',
                'scope_id' => $distribuidora->id,
                'status' => 'ACTIVE',
                'assigned_by_user_id' => $generalManager->id,
                'assigned_at' => now()->subDay(),
                'assignment_reason' => 'Activacion de distribuidora demo via seeder.',
            ]);

            // ── 11. Asignacion de coordinador ──

            CoordinatorDistributorAssignment::forceCreate([
                'coordinator_id' => $coordinator->id,
                'distributor_id' => $distribuidora->id,
                'branch_id' => $branch->id,
                'valid_from' => now()->subDay(),
                'valid_to' => null,
                'status' => 'ACTIVE',
                'assigned_by' => $generalManager->id,
                'assignment_reason' => 'Asignacion inicial de coordinador demo.',
                'lock_version' => 0,
            ]);

            // ── 12. Categoria (buscar existente o crear demo) ──

            $category = Category::query()->where('status', 'ACTIVE')->first();

            if ($category === null) {
                $category = Category::forceCreate([
                    'code' => 'DEMO-A',
                    'status' => 'ACTIVE',
                    'lock_version' => 0,
                    'created_by' => $generalManager->id,
                ]);
            }

            $categoryVersion = CategoryVersion::query()
                ->where('category_id', $category->id)
                ->where('status', 'PUBLISHED')
                ->whereNull('effective_to')
                ->first();

            if ($categoryVersion === null) {
                $categoryVersion = CategoryVersion::forceCreate([
                    'category_id' => $category->id,
                    'version' => 1,
                    'name' => 'Categoria Demo',
                    'description' => 'Categoria demo para distribuidoras de prueba.',
                    'profit_percentage' => '0.050000',
                    'status' => 'PUBLISHED',
                    'effective_from' => now()->subYear(),
                    'effective_to' => null,
                    'reason' => 'Categoria inicial para demo local.',
                    'created_by' => $generalManager->id,
                    'published_by' => $generalManager->id,
                    'published_at' => now()->subYear(),
                    'lock_version' => 0,
                ]);
            }

            AsignacionCategoriaDistribuidora::forceCreate([
                'distributor_id' => $distribuidora->id,
                'category_version_id' => $categoryVersion->id,
                'starts_at' => now()->subDay(),
                'ends_at' => null,
                'assigned_by' => $generalManager->id,
                'reason' => 'Asignacion de categoria inicial.',
            ]);

            // ── 13. Linea de credito ($50,000) ──

            $lineaCredito = LineaCredito::forceCreate([
                'distributor_id' => $distribuidora->id,
                'total_authorized' => $creditAmount,
                'used_balance' => '0.0000',
                'lock_version' => 1,
            ]);

            // ── 14. Movimiento inicial de credito ──

            MovimientoLineaCredito::forceCreate([
                'credit_line_id' => $lineaCredito->id,
                'distributor_id' => $distribuidora->id,
                'sequence' => 1,
                'type' => TipoMovimientoLineaCredito::AUTORIZACION_INICIAL,
                'amount' => $creditAmount,
                'total_authorized_before' => $creditAmount,
                'total_authorized_after' => $creditAmount,
                'used_balance_before' => '0.0000',
                'used_balance_after' => '0.0000',
                'source_type' => 'DISTRIBUTOR_APPLICATION_AUTHORIZATION',
                'source_id' => $authorization->id,
                'reason' => 'Linea de credito inicial autorizada.',
                'performed_by' => $generalManager->id,
                'authorized_by' => $generalManager->id,
                'idempotency_key' => 'initial-authorization:' . $authorization->id,
                'occurred_at' => now()->subDay(),
            ]);

            // ── 15. Restriccion inicial 50% ──

            $toleranceDef = ConfigurationDefinition::query()
                ->where('key', 'CREDIT_TOLERANCE_AMOUNT')
                ->first();

            $toleranceVersionId = null;
            if ($toleranceDef !== null) {
                $toleranceVersion = ConfigurationVersion::query()
                    ->where('configuration_definition_id', $toleranceDef->id)
                    ->where('status', 'PUBLISHED')
                    ->first();
                $toleranceVersionId = $toleranceVersion?->id;
            }

            if ($toleranceVersionId !== null) {
                RestriccionUsoCredito::forceCreate([
                    'credit_line_id' => $lineaCredito->id,
                    'distributor_id' => $distribuidora->id,
                    'type' => 'INITIAL_50_PERCENT',
                    'status' => 'ACTIVE',
                    'base_total' => $creditAmount,
                    'tolerance_amount' => '0.0000',
                    'configuration_version_id' => $toleranceVersionId,
                    'source_type' => 'DISTRIBUTOR_APPLICATION_AUTHORIZATION',
                    'source_id' => $authorization->id,
                    'activated_at' => now()->subDay(),
                    'created_by' => $generalManager->id,
                    'lock_version' => 1,
                ]);
            }

            // ── 16. Invitacion consumida (cuenta ya activada) ──

            AccountInvitation::forceCreate([
                'user_id' => $distributorUser->id,
                'created_by_user_id' => $generalManager->id,
                'purpose' => 'ACCOUNT_ACTIVATION',
                'token_hash' => hash('sha256', Str::random(60)),
                'state' => 'CONSUMED',
                'expires_at' => now()->addHours(48),
                'consumed_at' => now()->subDay(),
            ]);

            // ── 17. Historial completo de transiciones ──

            $transitions = [
                [null, ApplicationStatus::DRAFT, $coordinator->id, 'Solicitud creada'],
                [ApplicationStatus::DRAFT, ApplicationStatus::COORDINATOR_REVIEW, $coordinator->id, 'Enviada a revision del coordinador'],
                [ApplicationStatus::COORDINATOR_REVIEW, ApplicationStatus::VERIFIER_ASSIGNED, $coordinator->id, 'Verificador asignado'],
                [ApplicationStatus::VERIFIER_ASSIGNED, ApplicationStatus::PHYSICAL_VERIFICATION, $verifier->id, 'Visita de verificacion iniciada'],
                [ApplicationStatus::PHYSICAL_VERIFICATION, ApplicationStatus::COORDINATOR_EVALUATION, $coordinator->id, 'Evaluacion favorable completada'],
                [ApplicationStatus::COORDINATOR_EVALUATION, ApplicationStatus::MANAGER_AUTHORIZATION, $coordinator->id, 'Enviada a autorizacion de gerencia'],
                [ApplicationStatus::MANAGER_AUTHORIZATION, ApplicationStatus::AUTHORIZED_PENDING_ACTIVATION, $generalManager->id, 'Autorizada por gerencia general'],
                [ApplicationStatus::AUTHORIZED_PENDING_ACTIVATION, ApplicationStatus::ACTIVE, $generalManager->id, 'Distribuidora materializada y activada'],
            ];

            foreach ($transitions as $i => [$from, $to, $userId, $reason]) {
                ApplicationStateTransition::forceCreate([
                    'application_id' => $solicitud->id,
                    'from_status' => $from,
                    'to_status' => $to,
                    'user_id' => $userId,
                    'reason' => $reason,
                ]);
            }
        });
    }
}
