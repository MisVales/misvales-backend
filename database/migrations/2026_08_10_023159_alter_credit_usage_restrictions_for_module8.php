<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_usage_restrictions', function (Blueprint $table) {
            // Drop old unique constraint
            $table->dropUnique('credit_usage_restrictions_credit_line_id_type_unique');
            
            // Drop old column
            $table->dropColumn('voucher_id');
            
            // Add new columns
            $table->foreignUuid('distributor_id')->after('credit_line_id')->constrained('distributors')->restrictOnDelete();
            
            $table->decimal('tolerance_amount', 19, 4)->after('base_total');
            $table->foreignUuid('configuration_version_id')->after('tolerance_amount')->constrained('configuration_versions')->restrictOnDelete();
            
            $table->string('source_type')->after('configuration_version_id');
            $table->uuid('source_id')->after('source_type');
            
            $table->uuid('reserved_voucher_id')->nullable()->after('source_id');
            
            $table->timestampTz('activated_at')->after('reserved_voucher_id');
            $table->timestampTz('reserved_at')->nullable()->after('activated_at');
            $table->timestampTz('cancelled_at')->nullable()->after('consumed_at');
            
            $table->foreignUuid('created_by')->nullable()->after('cancelled_at')->constrained('users')->restrictOnDelete();
            
            $table->integer('lock_version')->default(1)->after('created_by');
        });

        // Add partial unique index
        DB::statement("CREATE UNIQUE INDEX credit_usage_restrictions_one_current ON credit_usage_restrictions (credit_line_id) WHERE status IN ('ACTIVE', 'RESERVED')");
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
