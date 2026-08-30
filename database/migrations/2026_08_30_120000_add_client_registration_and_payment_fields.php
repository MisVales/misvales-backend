<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('phone_number', 32)->nullable()->after('second_last_name');
        });

        Schema::create('client_registration_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->json('payload');
            $table->string('status', 16)->default('OPEN');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['distributor_id', 'status', 'created_at']);
            $table->index(['branch_id', 'status', 'created_at']);
        });

        Schema::table('voucher_cash_transactions', function (Blueprint $table): void {
            $table->string('payment_method', 16)->default('TRANSFER')->after('voucher_id');
            $table->foreignUuid('client_bank_account_id')->nullable()->after('payment_method')->constrained('client_bank_accounts')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE client_bank_accounts MODIFY bank_name VARCHAR(255) NULL');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE client_bank_accounts ALTER COLUMN bank_name DROP NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('voucher_cash_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_bank_account_id');
            $table->dropColumn('payment_method');
        });
        Schema::dropIfExists('client_registration_drafts');
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('phone_number');
        });
    }
};
