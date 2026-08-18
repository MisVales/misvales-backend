<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

            $table->foreign('application_id')->references('id')->on('distributor_applications')->restrictOnDelete();
            $table->foreign('authorized_by')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE application_authorizations ADD CONSTRAINT application_authorizations_decision_check CHECK (decision IN ('APPROVED', 'REJECTED'))");
        DB::statement("ALTER TABLE application_authorizations ADD CONSTRAINT application_authorizations_amount_check CHECK ((decision = 'APPROVED' AND initial_credit_line_amount > 0) OR (decision = 'REJECTED' AND initial_credit_line_amount IS NULL))");
    }
    public function down(): void {
        Schema::dropIfExists('application_authorizations');
    }
};

