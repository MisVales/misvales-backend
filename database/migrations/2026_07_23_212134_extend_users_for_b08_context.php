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
            $table->string('role_code')->nullable()->after('state');
            $table->uuid('branch_id')->nullable()->after('role_code');
            $table->unsignedBigInteger('coordinator_id')->nullable()->after('branch_id');
            $table->unsignedInteger('assignment_version')->default(1)->after('coordinator_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role_code', 'branch_id', 'coordinator_id', 'assignment_version']);
        });
    }
};
