<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE distributor_relations DROP CONSTRAINT distributor_relations_financial_status_check');
        Schema::table('distributor_relations', function (Blueprint $table): void {
            $table->foreignUuid('previous_relation_id')->nullable()->after('branch_id')->constrained('distributor_relations')->restrictOnDelete();
            $table->foreignUuid('rolled_forward_to_id')->nullable()->after('previous_relation_id')->constrained('distributor_relations')->restrictOnDelete();
            $table->timestampTz('rolled_forward_at')->nullable()->after('rolled_forward_to_id');
            $table->decimal('rolled_forward_amount', 19, 4)->default(0)->after('rolled_forward_at');
            $table->decimal('carried_balance', 19, 4)->default(0)->after('surcharge_total');
            $table->decimal('carried_surcharge', 19, 4)->default(0)->after('carried_balance');
            $table->decimal('carried_interest', 19, 4)->default(0)->after('carried_surcharge');
            $table->decimal('carried_insurance', 19, 4)->default(0)->after('carried_interest');
            $table->decimal('carried_commission', 19, 4)->default(0)->after('carried_insurance');
            $table->decimal('carried_capital', 19, 4)->default(0)->after('carried_commission');
        });
        DB::statement("ALTER TABLE distributor_relations ADD CONSTRAINT distributor_relations_financial_status_check CHECK (financial_status IN ('PENDING','PARTIALLY_PAID','SETTLED','OVERDUE','ROLLED_FORWARD'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE distributor_relations DROP CONSTRAINT distributor_relations_financial_status_check');
        DB::statement("ALTER TABLE distributor_relations ADD CONSTRAINT distributor_relations_financial_status_check CHECK (financial_status IN ('PENDING','PARTIALLY_PAID','SETTLED','OVERDUE'))");
        Schema::table('distributor_relations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rolled_forward_to_id');
            $table->dropConstrainedForeignId('previous_relation_id');
            $table->dropColumn([
                'rolled_forward_at',
                'rolled_forward_amount',
                'carried_balance',
                'carried_surcharge',
                'carried_interest',
                'carried_insurance',
                'carried_commission',
                'carried_capital',
            ]);
        });
    }
};
