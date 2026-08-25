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
        Schema::table('bank_file_imports', function (Blueprint $table): void {
            $table->foreignUuid('process_run_id')->nullable()->after('branch_id')->constrained('relation_process_runs')->restrictOnDelete();
        });

        Schema::table('bank_movements', function (Blueprint $table): void {
            $table->foreignUuid('process_run_id')->nullable()->after('import_id')->constrained('relation_process_runs')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('process_run_id');
        });

        Schema::table('bank_file_imports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('process_run_id');
        });
    }
};
