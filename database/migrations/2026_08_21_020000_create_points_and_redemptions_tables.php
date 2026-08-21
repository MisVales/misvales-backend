<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('point_movements');
        Schema::dropIfExists('point_redemption_requests');
        Schema::dropIfExists('point_accounts');

        Schema::create('point_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('distributor_id')->unique()->constrained('distributors')->cascadeOnDelete();
            $table->unsignedBigInteger('balance')->default(0);
            $table->unsignedBigInteger('reserved')->default(0);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        Schema::create('point_redemption_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('point_account_id')->constrained('point_accounts')->cascadeOnDelete();
            $table->foreignUuid('distributor_id')->constrained('distributors')->cascadeOnDelete();
            $table->unsignedBigInteger('points');
            $table->decimal('point_value_snapshot', 19, 4);
            $table->decimal('total_amount', 19, 4);
            $table->string('status', 32)->default('REQUESTED'); // REQUESTED, AUTHORIZED, REJECTED, DELIVERED, CANCELLED
            $table->unsignedBigInteger('balance_before')->nullable();
            $table->unsignedBigInteger('balance_after')->nullable();
            $table->foreignUuid('requested_by')->constrained('users');
            $table->timestamp('requested_at')->useCurrent();
            $table->foreignUuid('authorized_by')->nullable()->constrained('users');
            $table->timestamp('authorized_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignUuid('delivered_by')->nullable()->constrained('users');
            $table->timestamp('delivered_at')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('point_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('point_account_id')->constrained('point_accounts')->cascadeOnDelete();
            $table->foreignUuid('distributor_id')->constrained('distributors')->cascadeOnDelete();
            $table->string('type', 32); // ACCREDIT, REDEMPTION, RESERVE, RELEASE
            $table->bigInteger('points');
            $table->decimal('point_value_snapshot', 19, 4)->nullable();
            $table->decimal('amount', 19, 4)->nullable();
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');
            $table->string('source_type', 64)->nullable();
            $table->uuid('source_id')->nullable();
            $table->foreignUuid('performed_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });

        // Insert permissions for points module
        $perms = [
            ['code' => 'points.view_own', 'description' => 'Ver puntos propios', 'module' => 'points', 'action' => 'view_own'],
            ['code' => 'points.view_branch', 'description' => 'Ver puntos de sucursal', 'module' => 'points', 'action' => 'view_branch'],
            ['code' => 'points.view_global', 'description' => 'Ver puntos globales', 'module' => 'points', 'action' => 'view_global'],
            ['code' => 'points.request_own', 'description' => 'Solicitar canje de puntos propio', 'module' => 'points', 'action' => 'request_own'],
            ['code' => 'points.authorize_branch', 'description' => 'Autorizar canje de puntos de sucursal', 'module' => 'points', 'action' => 'authorize_branch'],
            ['code' => 'points.authorize_global', 'description' => 'Autorizar canje de puntos global', 'module' => 'points', 'action' => 'authorize_global'],
            ['code' => 'points.deliver_branch', 'description' => 'Entregar efectivo de canje de puntos', 'module' => 'points', 'action' => 'deliver_branch'],
        ];

        foreach ($perms as $p) {
            if (! DB::table('permissions')->where('code', $p['code'])->exists()) {
                DB::table('permissions')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'code' => $p['code'],
                    'description' => $p['description'],
                    'module' => $p['module'],
                    'action' => $p['action'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('point_movements');
        Schema::dropIfExists('point_redemption_requests');
        Schema::dropIfExists('point_accounts');
    }
};
