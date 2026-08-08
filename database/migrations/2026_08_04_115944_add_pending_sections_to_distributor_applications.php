<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('distributor_applications_m5', function (Blueprint $table) {
            $table->jsonb('pending_sections')->nullable()->after('applicant_data');
        });
    }

    public function down(): void {
        Schema::table('distributor_applications_m5', function (Blueprint $table) {
            $table->dropColumn('pending_sections');
        });
    }
};
