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
        Schema::table('configuration_versions', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(0)->after('status');
        });

        DB::statement('ALTER TABLE configuration_versions ADD CONSTRAINT chk_cv_lock_version CHECK (lock_version >= 0);');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE configuration_versions DROP CONSTRAINT chk_cv_lock_version;');
        Schema::table('configuration_versions', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });
    }
};
