<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('application_authorizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id')->unique();
            $table->string('decision', 50);
            $table->decimal('initial_credit_line_amount', 19, 4)->nullable();
            $table->text('reason');
            $table->uuid('authorized_by');
            $table->timestampTz('authorized_at');
            $table->timestampsTz();

            $table->foreign('application_id')->references('id')->on('distributor_applications_m5')->restrictOnDelete();
            $table->foreign('authorized_by')->references('id')->on('users')->restrictOnDelete();
        });
    }
    public function down(): void {
        Schema::dropIfExists('application_authorizations');
    }
};
