<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('CREATE SEQUENCE IF NOT EXISTS distributor_application_number_seq START WITH 1 INCREMENT BY 1');
        }

        Schema::create('distributor_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('application_number', 32)->unique();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('coordinator_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 40)->default('DRAFT');
            if (DB::getDriverName() === 'pgsql') {
                $table->jsonb('section_declarations')->default(DB::raw("'{}'::jsonb"));
            } else {
                $table->jsonb('section_declarations')->default('{}');
            }
            $table->jsonb('pending_sections')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->index(['branch_id', 'status']);
            $table->index(['coordinator_id', 'status']);
            $table->index('created_at');
            $table->index('submitted_at');
        });

        DB::statement("ALTER TABLE distributor_applications ADD CONSTRAINT distributor_applications_status_check CHECK (status IN ('DRAFT', 'COORDINATOR_REVIEW', 'VERIFIER_ASSIGNED', 'PHYSICAL_VERIFICATION', 'COORDINATOR_CORRECTION', 'COORDINATOR_EVALUATION', 'MANAGER_AUTHORIZATION', 'TERMINATED_UNFAVORABLE', 'REJECTED', 'AUTHORIZED_PENDING_ACTIVATION', 'ACTIVE'))");
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE distributor_applications ADD CONSTRAINT distributor_applications_lock_version_check CHECK (lock_version >= 1)');
        }

        if (DB::getDriverName() !== 'sqlite') {
            $operator = DB::getDriverName() === 'pgsql' ? '~' : 'REGEXP';
            DB::statement("ALTER TABLE distributor_applications ADD CONSTRAINT distributor_applications_number_check CHECK (application_number {$operator} '^SOL-[0-9]{4}-[0-9]{6,}$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_applications');
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('DROP SEQUENCE IF EXISTS distributor_application_number_seq');
        }
    }
};
