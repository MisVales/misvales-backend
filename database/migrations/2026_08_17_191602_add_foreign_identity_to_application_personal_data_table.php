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

        Schema::table('application_personal_data', function (Blueprint $table) use ($isMysql) {
            $table->string('nationality', 32)->default('MEXICAN');
            $table->string('birth_country', 2)->default('MX');
            $table->string('identification_country', 2)->default('MX');

            $table->text('curp_ciphertext')->nullable()->change();
            $table->char('curp_hmac', 64)->nullable()->change();
            $table->string('birth_place', 150)->nullable()->change();
            if ($isMysql) {
                $table->unsignedTinyInteger('foreign_identity_unique')
                    ->nullable()
                    ->storedAs("IF(curp_hmac IS NULL AND nationality = 'FOREIGN', 1, NULL)");
                $table->unique('curp_hmac', 'app_pers_data_curp_hmac_unique');
                $table->unique(
                    ['identification_country', 'official_id_type', 'official_id_number_hmac', 'foreign_identity_unique'],
                    'app_pers_data_foreign_id_unique'
                );
            }
        });

        if (! $isMysql) {
            DB::statement('CREATE UNIQUE INDEX app_pers_data_curp_hmac_unique ON application_personal_data (curp_hmac) WHERE curp_hmac IS NOT NULL');
            DB::statement("CREATE UNIQUE INDEX app_pers_data_foreign_id_unique ON application_personal_data (identification_country, official_id_type, official_id_number_hmac) WHERE curp_hmac IS NULL AND nationality = 'FOREIGN'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('application_personal_data', function (Blueprint $table): void {
                $table->dropUnique('app_pers_data_foreign_id_unique');
                $table->dropUnique('app_pers_data_curp_hmac_unique');
                $table->dropColumn('foreign_identity_unique');
            });
        } else {
            DB::statement('DROP INDEX IF EXISTS app_pers_data_foreign_id_unique');
            DB::statement('DROP INDEX IF EXISTS app_pers_data_curp_hmac_unique');
        }

        Schema::table('application_personal_data', function (Blueprint $table) {
            $table->dropColumn(['nationality', 'birth_country', 'identification_country']);
            $table->text('curp_ciphertext')->nullable(false)->change();
            $table->char('curp_hmac', 64)->nullable(false)->change();
            $table->string('birth_place', 150)->nullable(false)->change();
        });
    }
};
