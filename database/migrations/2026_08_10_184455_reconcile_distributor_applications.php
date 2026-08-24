<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $dependientes = [
        'verification_visits',
        'application_corrections',
        'application_evaluations',
        'application_authorizations',
        'application_state_transitions',
        'distributors',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('distributor_applications', 'pending_sections')) {
            Schema::table('distributor_applications', function (Blueprint $table): void {
                $table->jsonb('pending_sections')->nullable();
            });
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE distributor_applications DROP CONSTRAINT IF EXISTS distributor_applications_status_check');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE distributor_applications ADD CONSTRAINT distributor_applications_status_check CHECK (status IN ('DRAFT', 'COORDINATOR_REVIEW', 'VERIFIER_ASSIGNED', 'PHYSICAL_VERIFICATION', 'COORDINATOR_CORRECTION', 'COORDINATOR_EVALUATION', 'MANAGER_AUTHORIZATION', 'TERMINATED_UNFAVORABLE', 'REJECTED', 'AUTHORIZED_PENDING_ACTIVATION', 'ACTIVE'))");
        }

        if (Schema::hasTable('distributor_applications_m5')) {
            $sinCanonica = DB::table('distributor_applications_m5 as legacy')
                ->leftJoin('distributor_applications as canonical', 'canonical.id', '=', 'legacy.id')
                ->whereNull('canonical.id')->limit(20)->pluck('legacy.id');
            $this->abortarSiHay($sinCanonica, 'Solicitudes legacy sin raÃ­z canÃ³nica');

            $payloads = DB::table('distributor_applications_m5')
                ->when(DB::getDriverName() === 'pgsql', function ($q) {
                    $q->whereRaw("applicant_data::jsonb <> '{}'::jsonb");
                }, function ($q) {
                    $q->whereRaw('JSON_LENGTH(applicant_data) > 0');
                })
                ->limit(20)->pluck('id');
            $this->abortarSiHay($payloads, 'Solicitudes legacy con applicant_data que requiere migraciÃ³n explÃ­cita a tablas estructuradas');

            $conflictos = DB::table('distributor_applications_m5 as legacy')
                ->join('distributor_applications as canonical', 'canonical.id', '=', 'legacy.id')
                ->where(function ($query): void {
                    $query->whereColumn('legacy.branch_id', '<>', 'canonical.branch_id')
                        ->orWhere(function ($sub): void {
                            $sub->whereNotNull('legacy.coordinator_id')
                                ->whereColumn('legacy.coordinator_id', '<>', 'canonical.coordinator_id');
                        });
                })->limit(20)->pluck('legacy.id');
            $this->abortarSiHay($conflictos, 'Solicitudes legacy con propiedad organizacional ambigua');
        }

        foreach ($this->dependientes as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'application_id')) {
                continue;
            }

            $huerfanos = DB::table($tabla.' as child')
                ->leftJoin('distributor_applications as canonical', 'canonical.id', '=', 'child.application_id')
                ->whereNull('canonical.id')->limit(20)->pluck('child.application_id');
            $this->abortarSiHay($huerfanos, "{$tabla} contiene application_id sin raÃ­z canÃ³nica");

            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE {$tabla} DROP FOREIGN KEY {$tabla}_application_id_foreign");
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT IF EXISTS {$tabla}_application_id_foreign");
            }
            if (DB::getDriverName() !== 'sqlite') {
                DB::statement("ALTER TABLE {$tabla} ADD CONSTRAINT {$tabla}_application_id_foreign FOREIGN KEY (application_id) REFERENCES distributor_applications(id) ON DELETE RESTRICT");
            }
        }

        if (DB::getDriverName() === 'mysql') {
            if (Schema::hasIndex('application_evaluations', 'application_evaluations_application_id_unique')) {
                DB::statement('DROP INDEX application_evaluations_application_id_unique ON application_evaluations');
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE application_evaluations DROP CONSTRAINT IF EXISTS application_evaluations_application_id_unique');
            DB::statement('DROP INDEX IF EXISTS application_evaluations_application_id_unique');
        }
        DB::statement('CREATE INDEX IF NOT EXISTS application_evaluations_application_id_evaluated_at_index ON application_evaluations (application_id, evaluated_at)');
    }

    public function down(): void
    {
        throw new RuntimeException('La consolidaciÃ³n de identidad de solicitudes es forward-only.');
    }

    private function abortarSiHay($ids, string $mensaje): void
    {
        if ($ids->isNotEmpty()) {
            throw new RuntimeException($mensaje.'. IDs: '.$ids->unique()->implode(', '));
        }
    }
};
