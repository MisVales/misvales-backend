<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // First, let's remove the constraints that depend on the old columns
        DB::statement('ALTER TABLE credit_line_movements DROP CONSTRAINT IF EXISTS credit_line_movements_balances_check');

        Schema::table('credit_line_movements', function (Blueprint $table) {
            // Drop old columns
            $table->dropColumn(['balance_before', 'balance_after', 'updated_at']);
            
            // Note: The previous schema had 'created_by'. We will drop it and replace it with 'performed_by'
            $table->dropConstrainedForeignId('created_by');

            // Add new columns
            $table->foreignUuid('distributor_id')->after('credit_line_id')->constrained('distributors')->restrictOnDelete();
            $table->bigInteger('sequence')->after('distributor_id');
            
            $table->decimal('total_authorized_before', 19, 4)->after('amount');
            $table->decimal('total_authorized_after', 19, 4)->after('total_authorized_before');
            
            $table->decimal('used_balance_before', 19, 4)->after('total_authorized_after');
            $table->decimal('used_balance_after', 19, 4)->after('used_balance_before');
            
            $table->string('reason')->nullable()->after('source_id');
            $table->foreignUuid('performed_by')->nullable()->after('reason')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('authorized_by')->nullable()->after('performed_by')->constrained('users')->restrictOnDelete();
            
            $table->string('idempotency_key')->nullable()->after('authorized_by');
            
            $table->timestampTz('occurred_at')->after('idempotency_key');
            
            // Add constraints and indices
            $table->unique(['credit_line_id', 'sequence']);
            $table->index(['distributor_id', 'occurred_at']);
            
            // Note: credit_line_id, created_at index already exists from Module 6
            $table->index(['credit_line_id', 'occurred_at']);
        });

        // Partial unique index for idempotency_key
        DB::statement('CREATE UNIQUE INDEX credit_line_movements_idempotency_unique ON credit_line_movements (idempotency_key) WHERE idempotency_key IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS credit_line_movements_idempotency_unique');

        Schema::table('credit_line_movements', function (Blueprint $table) {
            $table->dropUnique(['credit_line_id', 'sequence']);
            $table->dropIndex(['distributor_id', 'occurred_at']);
            $table->dropIndex(['credit_line_id', 'occurred_at']);
            
            $table->dropConstrainedForeignId('authorized_by');
            $table->dropConstrainedForeignId('performed_by');
            $table->dropConstrainedForeignId('distributor_id');

            $table->dropColumn([
                'sequence',
                'total_authorized_before',
                'total_authorized_after',
                'used_balance_before',
                'used_balance_after',
                'reason',
                'idempotency_key',
                'occurred_at'
            ]);

            $table->decimal('balance_before', 19, 4);
            $table->decimal('balance_after', 19, 4);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('updated_at')->nullable();
        });

        DB::statement('ALTER TABLE credit_line_movements ADD CONSTRAINT credit_line_movements_balances_check CHECK (balance_before >= 0 AND balance_after >= 0)');
    }
};
