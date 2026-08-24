<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('credit_line_movements', 'balance_before')) {
            $legacyIds = DB::table('credit_line_movements')->limit(20)->pluck('id');
            if ($legacyIds->isNotEmpty()) {
                throw new RuntimeException('No se pueden convertir movimientos legacy sin inventar snapshots histÃ³ricos. IDs: '.$legacyIds->implode(', '));
            }

            if (DB::getDriverName() !== 'sqlite') {
                DB::statement('ALTER TABLE credit_line_movements DROP CONSTRAINT IF EXISTS credit_line_movements_balances_check');
            }
            Schema::table('credit_line_movements', function (Blueprint $table): void {
                $table->dropColumn(['balance_before', 'balance_after', 'updated_at']);
                $table->dropConstrainedForeignId('created_by');
                $table->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
                $table->unsignedBigInteger('sequence');
                $table->decimal('total_authorized_before', 19, 4);
                $table->decimal('total_authorized_after', 19, 4);
                $table->decimal('used_balance_before', 19, 4);
                $table->decimal('used_balance_after', 19, 4);
                $table->string('reason')->nullable();
                $table->foreignUuid('performed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignUuid('authorized_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('idempotency_key')->nullable();
                $table->timestampTz('occurred_at');
                $table->unique(['credit_line_id', 'sequence']);
                $table->index(['distributor_id', 'occurred_at']);
                $table->index(['credit_line_id', 'occurred_at']);
            });
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_line_movements DROP CONSTRAINT IF EXISTS credit_line_movements_balances_check');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_line_movements ADD CONSTRAINT credit_line_movements_balances_check CHECK (total_authorized_before > 0 AND total_authorized_after > 0 AND used_balance_before >= 0 AND used_balance_before <= total_authorized_before AND used_balance_after >= 0 AND used_balance_after <= total_authorized_after)');
        }
        if (DB::getDriverName() === 'mysql') {
            if (! Schema::hasIndex('credit_line_movements', 'credit_line_movements_idempotency_unique')) {
                Schema::table('credit_line_movements', function (Blueprint $table): void {
                    $table->unique('idempotency_key', 'credit_line_movements_idempotency_unique');
                });
            }
        } else {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS credit_line_movements_idempotency_unique ON credit_line_movements (idempotency_key) WHERE idempotency_key IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            if (Schema::hasIndex('credit_line_movements', 'credit_line_movements_idempotency_unique')) {
                Schema::table('credit_line_movements', fn (Blueprint $table) => $table->dropUnique('credit_line_movements_idempotency_unique'));
            }
        } else {
            DB::statement('DROP INDEX IF EXISTS credit_line_movements_idempotency_unique');
        }

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
                'occurred_at',
            ]);

            $table->decimal('balance_before', 19, 4);
            $table->decimal('balance_after', 19, 4);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('updated_at')->nullable();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_line_movements ADD CONSTRAINT credit_line_movements_balances_check CHECK (balance_before >= 0 AND balance_after >= 0)');
        }
    }
};
