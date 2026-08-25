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
        Schema::table('delinquency_removal_requests', function (Blueprint $table): void {
            $table->foreignUuid('regularized_relation_id')->nullable()->after('block_id')->constrained('distributor_relations')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delinquency_removal_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('regularized_relation_id');
        });
    }
};
