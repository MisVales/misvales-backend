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
        Schema::table('account_invitations', function (Blueprint $table) {
            $table->foreignUuid('revoked_by')->nullable()->constrained('users');
            $table->text('revocation_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_invitations', function (Blueprint $table) {
            $table->dropForeign(['revoked_by']);
            $table->dropColumn(['revoked_by', 'revocation_reason']);
        });
    }
};
