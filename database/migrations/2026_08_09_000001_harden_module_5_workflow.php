<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distributor_applications_m5')) {
            Schema::table('distributor_applications_m5', function (Blueprint $table): void {
                $table->jsonb('original_applicant_data')->nullable();
                $table->uuid('submitted_by')->nullable();
                $table->foreign('submitted_by')->references('id')->on('users')->restrictOnDelete();
            });

            DB::statement('UPDATE distributor_applications_m5 SET original_applicant_data = applicant_data WHERE original_applicant_data IS NULL');
            if (DB::getDriverName() === 'mysql') {
                Schema::table('distributor_applications_m5', function (Blueprint $table): void {
                    $table->json('original_applicant_data')->nullable(false)->change();
                });
            } else {
                DB::statement('ALTER TABLE distributor_applications_m5 ALTER COLUMN original_applicant_data SET NOT NULL');
            }
        }

        DB::statement('ALTER TABLE verification_visits DROP CONSTRAINT IF EXISTS verification_visits_status_check');
        DB::statement('ALTER TABLE verification_visits DROP CONSTRAINT IF EXISTS verification_visits_result_check');
        DB::statement('ALTER TABLE application_evaluations DROP CONSTRAINT IF EXISTS application_evaluations_result_check');
        DB::statement('ALTER TABLE application_authorizations DROP CONSTRAINT IF EXISTS application_authorizations_decision_check');
        DB::statement("ALTER TABLE verification_visits ADD CONSTRAINT verification_visits_status_check CHECK (status IN ('ASSIGNED', 'IN_PROGRESS', 'COMPLETED'))");
        DB::statement("ALTER TABLE verification_visits ADD CONSTRAINT verification_visits_result_check CHECK (result IS NULL OR result IN ('FAVORABLE', 'UNFAVORABLE'))");
        DB::statement("ALTER TABLE application_evaluations ADD CONSTRAINT application_evaluations_result_check CHECK (result IN ('COMPLIES', 'DOES_NOT_COMPLY'))");
        DB::statement("ALTER TABLE application_authorizations ADD CONSTRAINT application_authorizations_decision_check CHECK (decision IN ('APPROVED', 'REJECTED'))");
        if (DB::getDriverName() === 'mysql') {
            Schema::table('verification_visits', function (Blueprint $table): void {
                $table->unsignedTinyInteger('open_application_unique')
                    ->nullable()
                    ->storedAs("IF(status IN ('ASSIGNED', 'IN_PROGRESS'), 1, NULL)");
                $table->unique(['application_id', 'open_application_unique'], 'verification_visits_one_open_per_application');
            });
        } else {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS verification_visits_one_open_per_application ON verification_visits (application_id) WHERE status IN ('ASSIGNED', 'IN_PROGRESS')");
        }
        if (Schema::hasTable('distributor_applications_m5')) {
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared(<<<'SQL'
                    CREATE TRIGGER distributor_applications_m5_preserve_original
                    BEFORE UPDATE ON distributor_applications_m5
                    FOR EACH ROW
                    BEGIN
                        IF NOT (NEW.original_applicant_data <=> OLD.original_applicant_data) THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'original_applicant_data is immutable';
                        END IF;
                    END
                SQL);
            } else {
                DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION preserve_m5_original_application_data() RETURNS trigger AS $$
            BEGIN
                IF NEW.original_applicant_data IS DISTINCT FROM OLD.original_applicant_data THEN
                    RAISE EXCEPTION 'original_applicant_data is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS distributor_applications_m5_preserve_original ON distributor_applications_m5;
            CREATE TRIGGER distributor_applications_m5_preserve_original
            BEFORE UPDATE ON distributor_applications_m5
            FOR EACH ROW EXECUTE FUNCTION preserve_m5_original_application_data();
            SQL);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('DROP TRIGGER IF EXISTS distributor_applications_m5_preserve_original');
            Schema::table('verification_visits', function (Blueprint $table): void {
                $table->dropUnique('verification_visits_one_open_per_application');
                $table->dropColumn('open_application_unique');
            });
        } else {
            DB::statement('DROP TRIGGER IF EXISTS distributor_applications_m5_preserve_original ON distributor_applications_m5');
            DB::statement('DROP FUNCTION IF EXISTS preserve_m5_original_application_data()');
            DB::statement('DROP INDEX IF EXISTS verification_visits_one_open_per_application');
        }
        DB::statement('ALTER TABLE application_authorizations DROP CONSTRAINT IF EXISTS application_authorizations_decision_check');
        DB::statement('ALTER TABLE application_evaluations DROP CONSTRAINT IF EXISTS application_evaluations_result_check');
        DB::statement('ALTER TABLE verification_visits DROP CONSTRAINT IF EXISTS verification_visits_result_check');
        DB::statement('ALTER TABLE verification_visits DROP CONSTRAINT IF EXISTS verification_visits_status_check');

        if (Schema::hasTable('distributor_applications_m5')) {
            Schema::table('distributor_applications_m5', function (Blueprint $table): void {
                $table->dropForeign(['submitted_by']);
                $table->dropColumn(['submitted_by', 'original_applicant_data']);
            });
        }
    }
};
