<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['status', 'name']);
            $table->dropColumn('name');
            $table->dropColumn('description');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE category_versions DROP CONSTRAINT chk_catv_profit_rate');
        }

        Schema::table('category_versions', function (Blueprint $table) {
            $table->string('name')->after('version');
            $table->text('description')->nullable()->after('name');
            $table->renameColumn('profit_rate', 'profit_percentage');
            $table->integer('lock_version')->default(0)->after('status');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE category_versions ADD CONSTRAINT chk_catv_profit_percentage CHECK (profit_percentage >= 0 AND profit_percentage <= 1)");
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('name')->default('')->after('code');
            $table->text('description')->nullable()->after('name');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE category_versions DROP CONSTRAINT chk_catv_profit_percentage');
        }

        Schema::table('category_versions', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->dropColumn('description');
            $table->dropColumn('lock_version');
            $table->renameColumn('profit_percentage', 'profit_rate');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE category_versions ADD CONSTRAINT chk_catv_profit_rate CHECK (profit_rate >= 0 AND profit_rate <= 1)");
        }
    }
};

