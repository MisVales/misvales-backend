<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isMysql = DB::getDriverName() === 'mysql';

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('CREATE SEQUENCE IF NOT EXISTS distributor_number_seq START WITH 1 INCREMENT BY 1');
        }

        Schema::create('distributors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->unique()->constrained('distributor_applications')->restrictOnDelete();
            $table->foreignUuid('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('distributor_number', 32)->unique();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('status', 24)->default('PENDING_ACTIVATION');
            $table->timestampTz('activated_at')->nullable();
            $table->foreignUuid('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->index(['branch_id', 'status']);
        });

        DB::statement("ALTER TABLE distributors ADD CONSTRAINT distributors_status_check CHECK (status IN ('PENDING_ACTIVATION', 'ACTIVE', 'DISABLED'))");
        DB::statement("ALTER TABLE distributors ADD CONSTRAINT distributors_activation_check CHECK (status <> 'ACTIVE' OR (activated_at IS NOT NULL AND activated_by IS NOT NULL))");
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE distributors ADD CONSTRAINT distributors_lock_version_check CHECK (lock_version >= 1)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            $operator = DB::getDriverName() === 'pgsql' ? '~' : 'REGEXP';
            DB::statement("ALTER TABLE distributors ADD CONSTRAINT distributors_number_check CHECK (distributor_number {$operator} '^DIS-[0-9]{4}-[0-9]{6,}$')");
        }

        Schema::table('coordinator_distributor_assignments', function (Blueprint $table): void {
            $table->foreign('distributor_id')->references('id')->on('distributors')->restrictOnDelete();
        });

        Schema::table('user_role_scopes', function (Blueprint $table) use ($isMysql): void {
            $table->foreignUuid('scope_id')->nullable()->after('scope_type')->constrained('distributors')->restrictOnDelete();
            if ($isMysql) {
                $table->unsignedTinyInteger('active_distributor_scope_unique')
                    ->nullable()
                    ->storedAs("IF(status = 'ACTIVE' AND revoked_at IS NULL AND scope_type = 'DISTRIBUTOR', 1, NULL)");
                $table->unique(['user_id', 'role_id', 'scope_id', 'active_distributor_scope_unique'], 'urs_active_distributor_unique');
            }
            $table->index(['scope_type', 'scope_id', 'status']);
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE user_role_scopes DROP CONSTRAINT IF EXISTS chk_scope_type');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE user_role_scopes DROP CONSTRAINT IF EXISTS chk_scope_branch_match');
        }
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_scope_type CHECK (scope_type IN ('GLOBAL', 'BRANCH', 'DISTRIBUTOR'))");
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_scope_branch_match CHECK (
            (scope_type = 'GLOBAL' AND branch_id IS NULL AND scope_id IS NULL)
            OR (scope_type = 'BRANCH' AND branch_id IS NOT NULL AND scope_id IS NULL)
            OR (scope_type = 'DISTRIBUTOR' AND branch_id IS NOT NULL AND scope_id IS NOT NULL)
        )");
        if (! $isMysql) {
            DB::statement("CREATE UNIQUE INDEX user_role_scopes_active_distributor_unique
                ON user_role_scopes (user_id, role_id, scope_id)
                WHERE status = 'ACTIVE' AND revoked_at IS NULL AND scope_type = 'DISTRIBUTOR'");
        }

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('trace_id')->nullable()->index()->after('request_id');
        });

        Schema::create('distributor_category_assignments', function (Blueprint $table) use ($isMysql): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
            $table->foreignUuid('category_version_id')->constrained('category_versions')->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            if ($isMysql) {
                $table->unsignedTinyInteger('current_distributor_unique')->nullable()->storedAs('IF(ends_at IS NULL, 1, NULL)');
                $table->unique(['distributor_id', 'current_distributor_unique'], 'dca_current_distributor_unique');
            }
            $table->foreignUuid('assigned_by')->constrained('users')->restrictOnDelete();
            $table->string('reason')->nullable();
            $table->timestampsTz();

            $table->index(['distributor_id', 'starts_at', 'ends_at'], 'dca_distributor_dates_index');
            $table->index(['category_version_id', 'starts_at'], 'dca_category_start_index');
        });

        if (! $isMysql) {
            DB::statement('CREATE UNIQUE INDEX distributor_category_current_unique ON distributor_category_assignments (distributor_id) WHERE ends_at IS NULL');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE distributor_category_assignments ADD CONSTRAINT distributor_category_dates_check CHECK (ends_at IS NULL OR ends_at > starts_at)');
        }

        Schema::create('credit_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('distributor_id')->unique()->constrained('distributors')->restrictOnDelete();
            $table->decimal('total_authorized', 19, 4);
            $table->decimal('used_balance', 19, 4)->default(0);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_lines ADD CONSTRAINT credit_lines_total_positive_check CHECK (total_authorized > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_lines ADD CONSTRAINT credit_lines_used_balance_check CHECK (used_balance >= 0 AND used_balance <= total_authorized)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_lines ADD CONSTRAINT credit_lines_lock_version_check CHECK (lock_version >= 1)');
        }

        Schema::create('credit_line_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('credit_line_id')->constrained('credit_lines')->restrictOnDelete();
            $table->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('type', 48);
            $table->decimal('amount', 19, 4);
            $table->decimal('total_authorized_before', 19, 4);
            $table->decimal('total_authorized_after', 19, 4);
            $table->decimal('used_balance_before', 19, 4);
            $table->decimal('used_balance_after', 19, 4);
            $table->string('source_type', 64);
            $table->uuid('source_id');
            $table->string('reason')->nullable();
            $table->foreignUuid('performed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['type', 'source_type', 'source_id']);
            $table->unique('idempotency_key', 'credit_line_movements_idempotency_unique');
            $table->unique(['credit_line_id', 'sequence']);
            $table->index(['credit_line_id', 'occurred_at']);
            $table->index(['distributor_id', 'occurred_at']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_line_movements ADD CONSTRAINT credit_line_movements_amount_check CHECK (amount > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_line_movements ADD CONSTRAINT credit_line_movements_balances_check CHECK (total_authorized_before > 0 AND total_authorized_after > 0 AND used_balance_before >= 0 AND used_balance_before <= total_authorized_before AND used_balance_after >= 0 AND used_balance_after <= total_authorized_after)');
        }
        Schema::create('credit_usage_restrictions', function (Blueprint $table) use ($isMysql): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('credit_line_id')->constrained('credit_lines')->restrictOnDelete();
            $table->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
            $table->string('type', 48);
            $table->string('status', 20)->default('ACTIVE');
            if ($isMysql) {
                $table->unsignedTinyInteger('current_credit_line_unique')
                    ->nullable()
                    ->storedAs("IF(status IN ('ACTIVE', 'RESERVED'), 1, NULL)");
                $table->unique(['credit_line_id', 'current_credit_line_unique'], 'cur_current_credit_line_unique');
            }
            $table->decimal('base_total', 19, 4);
            $table->decimal('tolerance_amount', 19, 4);
            $table->foreignUuid('configuration_version_id')->constrained('configuration_versions')->restrictOnDelete();
            $table->string('source_type', 64);
            $table->uuid('source_id');
            // La FK se agregarÃ¡ cuando exista la tabla canÃ³nica de vales.
            $table->uuid('reserved_voucher_id')->nullable();
            $table->timestampTz('activated_at');
            $table->timestampTz('reserved_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->index(['status', 'type']);
            $table->index('distributor_id');
            $table->index('configuration_version_id');
        });

        DB::statement("ALTER TABLE credit_usage_restrictions ADD CONSTRAINT credit_usage_restrictions_status_check CHECK (status IN ('ACTIVE', 'RESERVED', 'CONSUMED', 'CANCELLED'))");
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_usage_restrictions ADD CONSTRAINT credit_usage_restrictions_base_check CHECK (base_total > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_usage_restrictions ADD CONSTRAINT credit_usage_restrictions_tolerance_check CHECK (tolerance_amount >= 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_usage_restrictions ADD CONSTRAINT credit_usage_restrictions_lock_version_check CHECK (lock_version >= 1)');
        }
        DB::statement("ALTER TABLE credit_usage_restrictions ADD CONSTRAINT credit_usage_restrictions_lifecycle_check CHECK (
            (status = 'ACTIVE' AND reserved_voucher_id IS NULL AND reserved_at IS NULL AND consumed_at IS NULL AND cancelled_at IS NULL)
            OR (status = 'RESERVED' AND reserved_voucher_id IS NOT NULL AND reserved_at IS NOT NULL AND consumed_at IS NULL AND cancelled_at IS NULL)
            OR (status = 'CONSUMED' AND reserved_voucher_id IS NOT NULL AND reserved_at IS NOT NULL AND consumed_at IS NOT NULL AND cancelled_at IS NULL)
            OR (status = 'CANCELLED' AND cancelled_at IS NOT NULL AND consumed_at IS NULL AND ((reserved_voucher_id IS NULL AND reserved_at IS NULL) OR (reserved_voucher_id IS NOT NULL AND reserved_at IS NOT NULL)))
        )");
        if (! $isMysql) {
            DB::statement("CREATE UNIQUE INDEX credit_usage_restrictions_one_current ON credit_usage_restrictions (credit_line_id) WHERE status IN ('ACTIVE', 'RESERVED')");
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_distributor_deletion()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Las distribuidoras no se eliminan fÃ­sicamente.';
            END;
            $$ LANGUAGE plpgsql
        SQL);
            DB::statement('CREATE TRIGGER trg_prevent_distributor_deletion BEFORE DELETE ON distributors FOR EACH ROW EXECUTE FUNCTION prevent_distributor_deletion()');
        } elseif ($isMysql) {
            DB::statement("CREATE TRIGGER trg_prevent_distributor_deletion BEFORE DELETE ON distributors FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las distribuidoras no se eliminan fisicamente.'");
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_distributor_category_deletion()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'El historial de categorÃ­as no se elimina fÃ­sicamente.';
            END;
            $$ LANGUAGE plpgsql
        SQL);
            DB::statement('CREATE TRIGGER trg_prevent_distributor_category_deletion BEFORE DELETE ON distributor_category_assignments FOR EACH ROW EXECUTE FUNCTION prevent_distributor_category_deletion()');
        } elseif ($isMysql) {
            DB::statement("CREATE TRIGGER trg_prevent_distributor_category_deletion BEFORE DELETE ON distributor_category_assignments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El historial de categorias no se elimina fisicamente.'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_prevent_distributor_category_deletion');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_prevent_distributor_category_deletion ON distributor_category_assignments');
            DB::statement('DROP FUNCTION IF EXISTS prevent_distributor_category_deletion()');
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_prevent_distributor_deletion');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_prevent_distributor_deletion ON distributors');
            DB::statement('DROP FUNCTION IF EXISTS prevent_distributor_deletion()');
        }

        Schema::dropIfExists('credit_usage_restrictions');
        Schema::dropIfExists('credit_line_movements');
        Schema::dropIfExists('credit_lines');
        Schema::dropIfExists('distributor_category_assignments');

        if (DB::getDriverName() === 'mysql') {
            Schema::table('user_role_scopes', function (Blueprint $table): void {
                $table->dropUnique('urs_active_distributor_unique');
                $table->dropColumn('active_distributor_scope_unique');
            });
        } else {
            DB::statement('DROP INDEX IF EXISTS user_role_scopes_active_distributor_unique');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE user_role_scopes DROP CONSTRAINT IF EXISTS chk_scope_branch_match');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE user_role_scopes DROP CONSTRAINT IF EXISTS chk_scope_type');
        }
        Schema::table('user_role_scopes', function (Blueprint $table): void {
            $table->dropIndex(['scope_type', 'scope_id', 'status']);
            $table->dropConstrainedForeignId('scope_id');
        });
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_scope_type CHECK (scope_type IN ('GLOBAL', 'BRANCH'))");
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT chk_scope_branch_match CHECK (
            (scope_type = 'GLOBAL') OR (scope_type = 'BRANCH' AND branch_id IS NOT NULL)
        )");
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropColumn('trace_id');
        });

        Schema::table('coordinator_distributor_assignments', function (Blueprint $table): void {
            $table->dropForeign(['distributor_id']);
        });
        Schema::dropIfExists('distributors');
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('DROP SEQUENCE IF EXISTS distributor_number_seq');
        }
    }
};
