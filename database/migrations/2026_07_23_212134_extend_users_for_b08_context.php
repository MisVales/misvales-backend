<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('coordinator_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->unsignedInteger('assignment_version')->default(1)->after('coordinator_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coordinator_id');
            $table->dropColumn('assignment_version');
        });
    }
};
