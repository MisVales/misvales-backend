<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('credit_usage_restrictions', 'voucher_id')) {
            $legacyIds = DB::table('credit_usage_restrictions')->limit(20)->pluck('id');
            if ($legacyIds->isNotEmpty()) {
                throw new RuntimeException('No se pueden inventar configuraciÃ³n, procedencia y tolerancia para restricciones legacy. IDs: '.$legacyIds->implode(', '));
            }

            if (DB::getDriverName() !== 'sqlite') {
                DB::statement('ALTER TABLE credit_usage_restrictions DROP CONSTRAINT IF EXISTS credit_usage_restrictions_consumption_check');
            }
            if (DB::getDriverName() !== 'sqlite') {
                DB::statement('ALTER TABLE credit_usage_restrictions DROP CONSTRAINT IF EXISTS credit_usage_restrictions_status_check');
            }
            Schema::table('credit_usage_restrictions', function (Blueprint $table): void {
                $table->dropUnique('credit_usage_restrictions_credit_line_id_type_unique');
                $table->dropColumn('voucher_id');
                $table->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
                $table->decimal('tolerance_amount', 19, 4);
                $table->foreignUuid('configuration_version_id')->constrained('configuration_versions')->restrictOnDelete();
                $table->string('source_type', 64);
                $table->uuid('source_id');
                $table->uuid('reserved_voucher_id')->nullable();
                $table->timestampTz('activated_at');
                $table->timestampTz('reserved_at')->nullable();
                $table->timestampTz('cancelled_at')->nullable();
                $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->unsignedInteger('lock_version')->default(1);
                $table->index('distributor_id');
                $table->index('configuration_version_id');
            });
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_usage_restrictions DROP CONSTRAINT IF EXISTS credit_usage_restrictions_status_check');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_usage_restrictions DROP CONSTRAINT IF EXISTS credit_usage_restrictions_consumption_check');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_usage_restrictions DROP CONSTRAINT IF EXISTS credit_usage_restrictions_lifecycle_check');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE credit_usage_restrictions ADD CONSTRAINT credit_usage_restrictions_status_check CHECK (status IN ('ACTIVE', 'RESERVED', 'CONSUMED', 'CANCELLED'))");
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE credit_usage_restrictions ADD CONSTRAINT credit_usage_restrictions_lifecycle_check CHECK (
                (status = 'ACTIVE' AND reserved_voucher_id IS NULL AND reserved_at IS NULL AND consumed_at IS NULL AND cancelled_at IS NULL)
                OR (status = 'RESERVED' AND reserved_voucher_id IS NOT NULL AND reserved_at IS NOT NULL AND consumed_at IS NULL AND cancelled_at IS NULL)
                OR (status = 'CONSUMED' AND reserved_voucher_id IS NOT NULL AND reserved_at IS NOT NULL AND consumed_at IS NOT NULL AND cancelled_at IS NULL)
                OR (status = 'CANCELLED' AND cancelled_at IS NOT NULL AND consumed_at IS NULL AND ((reserved_voucher_id IS NULL AND reserved_at IS NULL) OR (reserved_voucher_id IS NOT NULL AND reserved_at IS NOT NULL)))
            )");
        }
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS credit_usage_restrictions_one_current ON credit_usage_restrictions (credit_line_id) WHERE status IN ('ACTIVE', 'RESERVED')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS credit_usage_restrictions_one_current');

        Schema::table('credit_usage_restrictions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn([
                'distributor_id',
                'tolerance_amount',
                'configuration_version_id',
                'source_type',
                'source_id',
                'reserved_voucher_id',
                'activated_at',
                'reserved_at',
                'cancelled_at',
                'lock_version',
            ]);

            $table->uuid('voucher_id')->nullable();

            $table->unique(['credit_line_id', 'type']);
        });
    }
};
