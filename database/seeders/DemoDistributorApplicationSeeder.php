<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DatosPersonalesSolicitud;
use App\Models\DomicilioSolicitud;
use App\Models\FamiliarSolicitud;
use App\Models\DistributorApplication;
use App\Models\User;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use App\Services\SolicitudDistribuidora\ProtectorDatosSolicitud;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class DemoDistributorApplicationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        DB::transaction(function (): void {
            $branch = BranchRecord::query()
                ->where('code', 'MATAMOROS')
                ->where('status', 'ACTIVE')
                ->firstOrFail();

            $coordinator = User::query()
                ->where('normalized_email', 'coordinador@gmail.com')
                ->firstOrFail();

            $existing = DistributorApplication::query()
                ->where('application_number', 'SOL-2026-900001')
                ->first();

            if ($existing !== null) {
                return;
            }

            $protector = app(ProtectorDatosSolicitud::class);

            $solicitud = DistributorApplication::forceCreate([
                'application_number' => 'SOL-2026-900001',
                'branch_id' => $branch->id,
                'coordinator_id' => $coordinator->id,
                'status' => 'COORDINATOR_REVIEW',
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
                'submitted_at' => now(),
                'lock_version' => 27,
            ]);

            $curp = 'TESA930515HRCLA04';
            $rfc = 'TESA930515XXX';
            $ine = '1234567890123';

            DatosPersonalesSolicitud::forceCreate([
                'application_id' => $solicitud->id,
                'first_name' => 'Alberto',
                'first_last_name' => 'Trejo',
                'second_last_name' => 'Saucedo',
                'birth_date' => '1993-05-15',
                'birth_place' => 'Matamoros, Coahuila, Mexico',
                'birth_state' => 'Coahuila',
                'birth_city' => 'Matamoros',
                'email' => 'alberto.trejo.demo@gmail.com',
                'phone_number' => '8688001234',
                'official_id_type' => 'INE',
                'curp_ciphertext' => $protector->cifrarCurp($curp),
                'curp_hmac' => $protector->generarHmacCurp($curp),
                'rfc_ciphertext' => $protector->cifrarRfc($rfc),
                'rfc_hmac' => $protector->generarHmacRfc($rfc),
                'official_id_number_ciphertext' => $protector->cifrarIdentificacion($ine),
                'official_id_number_hmac' => $protector->generarHmacIdentificacion($ine),
            ]);

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

            DB::table('application_state_transitions')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'application_id' => $solicitud->id,
                'from_status' => null,
                'to_status' => 'DRAFT',
                'user_id' => $coordinator->id,
                'reason' => 'Solicitud demo creada por seeder',
                'created_at' => now()->subMinutes(10),
                'updated_at' => now()->subMinutes(10),
            ]);

            DB::table('application_state_transitions')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'application_id' => $solicitud->id,
                'from_status' => 'DRAFT',
                'to_status' => 'COORDINATOR_REVIEW',
                'user_id' => $coordinator->id,
                'reason' => 'Solicitud demo enviada por seeder',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
