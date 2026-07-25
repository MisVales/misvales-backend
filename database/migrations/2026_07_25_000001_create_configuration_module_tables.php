<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── configuration_definitions ──
        Schema::create('configuration_definitions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('key', 80)->unique();
            $table->string('type', 40);
            $table->boolean('is_administrable')->default(true);
            $table->timestampsTz();
        });

        // ── configuration_versions ──
        Schema::create('configuration_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('definition_id')->constrained('configuration_definitions')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->text('value');
            $table->string('status', 20);
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_to')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->unique(['definition_id', 'version_number'], 'config_version_number_unique');
            $table->index(['definition_id', 'status', 'effective_from'], 'config_version_resolution');
            $table->index(['definition_id', 'status'], 'config_version_status');
        });

        // ── categories ──
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });

        // ── category_versions ──
        Schema::create('category_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('name', 255);
            $table->text('description');
            $table->decimal('distributor_profit_rate', 7, 4);
            $table->string('status', 20);
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_to')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->unique(['category_id', 'version_number'], 'category_version_number_unique');
            $table->index(['category_id', 'status', 'effective_from'], 'category_version_resolution');
            $table->index(['category_id', 'status'], 'category_version_status');
        });

        // ── products ──
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });

        // ── product_versions ──
        Schema::create('product_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->decimal('amount', 18, 4);
            $table->decimal('loan_commission_rate', 7, 4);
            $table->decimal('interest_rate_per_fortnight', 7, 4);
            $table->decimal('insurance_amount', 18, 4);
            $table->unsignedInteger('fortnight_count');
            $table->string('status', 20);
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_to')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->unique(['product_id', 'version_number'], 'product_version_number_unique');
            $table->index(['product_id', 'status', 'effective_from'], 'product_version_resolution');
            $table->index(['product_id', 'status'], 'product_version_status');
        });

        // ── redemption_periods ──
        Schema::create('redemption_periods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 20);
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->index(['status', 'starts_at', 'ends_at'], 'redemption_period_resolution');
        });

        // ── configuration_audit_events ──
        Schema::create('configuration_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('event_type', 128)->index();
            $table->string('result', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('executor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('role_code', 64)->nullable();
            $table->string('resource_type', 80)->nullable();
            $table->string('resource_id', 128)->nullable();
            $table->string('configuration_key', 80)->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('status_before', 20)->nullable();
            $table->string('status_after', 20)->nullable();
            $table->string('version_before', 20)->nullable();
            $table->string('version_after', 20)->nullable();
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_to')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('correlation_id');
            $table->string('session_id', 128)->nullable();
            $table->string('device_id', 128)->nullable();
            $table->uuid('request_id');
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });

        // ── PostgreSQL constraints ──
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                -- Status checks
                ALTER TABLE configuration_versions ADD CONSTRAINT config_version_status_check
                    CHECK (status IN ('DRAFT', 'PUBLISHED', 'INACTIVE'));

                ALTER TABLE category_versions ADD CONSTRAINT category_version_status_check
                    CHECK (status IN ('DRAFT', 'PUBLISHED', 'INACTIVE'));

                ALTER TABLE product_versions ADD CONSTRAINT product_version_status_check
                    CHECK (status IN ('DRAFT', 'PUBLISHED', 'INACTIVE'));

                ALTER TABLE redemption_periods ADD CONSTRAINT redemption_period_status_check
                    CHECK (status IN ('DRAFT', 'PUBLISHED', 'INACTIVE'));

                ALTER TABLE categories ADD CONSTRAINT category_status_check
                    CHECK (status IN ('DRAFT', 'PUBLISHED', 'INACTIVE'));

                ALTER TABLE products ADD CONSTRAINT product_status_check
                    CHECK (status IN ('DRAFT', 'PUBLISHED', 'INACTIVE'));

                -- Effective date ordering
                ALTER TABLE configuration_versions ADD CONSTRAINT config_version_effective_order
                    CHECK (effective_to IS NULL OR effective_from < effective_to);

                ALTER TABLE category_versions ADD CONSTRAINT category_version_effective_order
                    CHECK (effective_to IS NULL OR effective_from < effective_to);

                ALTER TABLE product_versions ADD CONSTRAINT product_version_effective_order
                    CHECK (effective_to IS NULL OR effective_from < effective_to);

                ALTER TABLE redemption_periods ADD CONSTRAINT redemption_period_order
                    CHECK (starts_at < ends_at);

                -- Published version constraints
                ALTER TABLE configuration_versions ADD CONSTRAINT config_version_published_requires_fields
                    CHECK (status != 'PUBLISHED' OR (effective_from IS NOT NULL AND published_by IS NOT NULL AND published_at IS NOT NULL AND reason IS NOT NULL));

                ALTER TABLE category_versions ADD CONSTRAINT category_version_published_requires_fields
                    CHECK (status != 'PUBLISHED' OR (effective_from IS NOT NULL AND published_by IS NOT NULL AND published_at IS NOT NULL AND reason IS NOT NULL));

                ALTER TABLE product_versions ADD CONSTRAINT product_version_published_requires_fields
                    CHECK (status != 'PUBLISHED' OR (effective_from IS NOT NULL AND published_by IS NOT NULL AND published_at IS NOT NULL AND reason IS NOT NULL));

                ALTER TABLE redemption_periods ADD CONSTRAINT redemption_period_published_requires_fields
                    CHECK (status != 'PUBLISHED' OR (published_by IS NOT NULL AND published_at IS NOT NULL AND reason IS NOT NULL));

                -- Product amount must be multiple of 100
                ALTER TABLE product_versions ADD CONSTRAINT product_version_amount_multiple_100
                    CHECK (amount > 0 AND amount % 100 = 0);

                -- Product financial parameters non-negative
                ALTER TABLE product_versions ADD CONSTRAINT product_version_financial_params
                    CHECK (loan_commission_rate >= 0 AND interest_rate_per_fortnight >= 0 AND insurance_amount >= 0 AND fortnight_count > 0);

                -- Category percentage non-negative
                ALTER TABLE category_versions ADD CONSTRAINT category_version_profit_rate
                    CHECK (distributor_profit_rate >= 0);

                -- Non-overlapping published versions for configurations (using exclusion constraint)
                CREATE EXTENSION IF NOT EXISTS btree_gist;

                ALTER TABLE configuration_versions ADD CONSTRAINT config_version_no_overlap
                    EXCLUDE USING gist (
                        definition_id WITH =,
                        tstzrange(effective_from, COALESCE(effective_to, 'infinity'::timestamptz)) WITH &&
                    ) WHERE (status = 'PUBLISHED');

                ALTER TABLE category_versions ADD CONSTRAINT category_version_no_overlap
                    EXCLUDE USING gist (
                        category_id WITH =,
                        tstzrange(effective_from, COALESCE(effective_to, 'infinity'::timestamptz)) WITH &&
                    ) WHERE (status = 'PUBLISHED');

                ALTER TABLE product_versions ADD CONSTRAINT product_version_no_overlap
                    EXCLUDE USING gist (
                        product_id WITH =,
                        tstzrange(effective_from, COALESCE(effective_to, 'infinity'::timestamptz)) WITH &&
                    ) WHERE (status = 'PUBLISHED');

                -- Immutability triggers for published versions and audit
                CREATE OR REPLACE FUNCTION protect_configuration_published_version() RETURNS trigger AS $$
                BEGIN
                    IF OLD.status = 'PUBLISHED' AND NEW.status = 'PUBLISHED' THEN
                        IF (OLD.value, OLD.effective_from, OLD.published_by, OLD.published_at, OLD.reason)
                            IS DISTINCT FROM
                           (NEW.value, NEW.effective_from, NEW.published_by, NEW.published_at, NEW.reason) THEN
                            RAISE EXCEPTION 'Published configuration versions are immutable';
                        END IF;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER config_version_immutable BEFORE UPDATE ON configuration_versions
                    FOR EACH ROW EXECUTE FUNCTION protect_configuration_published_version();

                CREATE OR REPLACE FUNCTION protect_category_published_version() RETURNS trigger AS $$
                BEGIN
                    IF OLD.status = 'PUBLISHED' AND NEW.status = 'PUBLISHED' THEN
                        IF (OLD.name, OLD.description, OLD.distributor_profit_rate, OLD.effective_from, OLD.published_by, OLD.published_at, OLD.reason)
                            IS DISTINCT FROM
                           (NEW.name, NEW.description, NEW.distributor_profit_rate, NEW.effective_from, NEW.published_by, NEW.published_at, NEW.reason) THEN
                            RAISE EXCEPTION 'Published category versions are immutable';
                        END IF;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER category_version_immutable BEFORE UPDATE ON category_versions
                    FOR EACH ROW EXECUTE FUNCTION protect_category_published_version();

                CREATE OR REPLACE FUNCTION protect_product_published_version() RETURNS trigger AS $$
                BEGIN
                    IF OLD.status = 'PUBLISHED' AND NEW.status = 'PUBLISHED' THEN
                        IF (OLD.amount, OLD.loan_commission_rate, OLD.interest_rate_per_fortnight, OLD.insurance_amount, OLD.fortnight_count, OLD.effective_from, OLD.published_by, OLD.published_at, OLD.reason)
                            IS DISTINCT FROM
                           (NEW.amount, NEW.loan_commission_rate, NEW.interest_rate_per_fortnight, NEW.insurance_amount, NEW.fortnight_count, NEW.effective_from, NEW.published_by, NEW.published_at, NEW.reason) THEN
                            RAISE EXCEPTION 'Published product versions are immutable';
                        END IF;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER product_version_immutable BEFORE UPDATE ON product_versions
                    FOR EACH ROW EXECUTE FUNCTION protect_product_published_version();

                -- No physical deletion triggers
                CREATE OR REPLACE FUNCTION protect_configuration_entity() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Physical deletion is not allowed for configuration entities';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER config_definitions_no_delete BEFORE DELETE ON configuration_definitions
                    FOR EACH ROW EXECUTE FUNCTION protect_configuration_entity();
                CREATE TRIGGER config_versions_no_delete BEFORE DELETE ON configuration_versions
                    FOR EACH ROW EXECUTE FUNCTION protect_configuration_entity();
                CREATE TRIGGER categories_no_delete BEFORE DELETE ON categories
                    FOR EACH ROW EXECUTE FUNCTION protect_configuration_entity();
                CREATE TRIGGER category_versions_no_delete BEFORE DELETE ON category_versions
                    FOR EACH ROW EXECUTE FUNCTION protect_configuration_entity();
                CREATE TRIGGER products_no_delete BEFORE DELETE ON products
                    FOR EACH ROW EXECUTE FUNCTION protect_configuration_entity();
                CREATE TRIGGER product_versions_no_delete BEFORE DELETE ON product_versions
                    FOR EACH ROW EXECUTE FUNCTION protect_configuration_entity();
                CREATE TRIGGER redemption_periods_no_delete BEFORE DELETE ON redemption_periods
                    FOR EACH ROW EXECUTE FUNCTION protect_configuration_entity();
                CREATE TRIGGER config_audit_events_immutable BEFORE UPDATE OR DELETE ON configuration_audit_events
                    FOR EACH ROW EXECUTE FUNCTION protect_configuration_entity();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_configuration_published_version() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS protect_category_published_version() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS protect_product_published_version() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS protect_configuration_entity() CASCADE');
        }

        Schema::dropIfExists('configuration_audit_events');
        Schema::dropIfExists('redemption_periods');
        Schema::dropIfExists('product_versions');
        Schema::dropIfExists('products');
        Schema::dropIfExists('category_versions');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('configuration_versions');
        Schema::dropIfExists('configuration_definitions');
    }
};
