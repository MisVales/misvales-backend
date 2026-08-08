<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('application_state_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50);
            $table->uuid('user_id')->nullable()->comment('Actor que realiza el cambio');
            $table->text('reason')->nullable();
            $table->timestampsTz();

            $table->foreign('application_id')->references('id')->on('distributor_applications_m5')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }
    public function down(): void {
        Schema::dropIfExists('application_state_transitions');
    }
};
