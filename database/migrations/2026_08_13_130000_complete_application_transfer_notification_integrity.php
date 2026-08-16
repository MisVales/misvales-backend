<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('La corrección de integridad requiere PostgreSQL.');
        }

        $this->assertNoRows(
            "SELECT id FROM application_authorizations WHERE decision = 'APPROVED' AND (initial_credit_line_amount IS NULL OR initial_credit_line_amount <= 0) OR decision = 'REJECTED' AND initial_credit_line_amount IS NOT NULL LIMIT 20",
            'Existen autorizaciones con decisión e importe incompatibles',
        );

        foreach ([
            'application_authorizations',
            'application_corrections',
            'application_evaluations',
            'application_state_transitions',
            'verification_visits',
            'distributors',
        ] as $table) {
            $this->recreateApplicationForeignKey($table);
        }

        DB::statement('ALTER TABLE application_authorizations DROP CONSTRAINT IF EXISTS application_authorizations_amount_check');
        DB::statement("ALTER TABLE application_authorizations ADD CONSTRAINT application_authorizations_amount_check CHECK ((decision = 'APPROVED' AND initial_credit_line_amount > 0) OR (decision = 'REJECTED' AND initial_credit_line_amount IS NULL))");

        Schema::table('client_transfer_requests', function (Blueprint $table): void {
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
        });
        DB::statement('ALTER TABLE client_transfer_requests DROP CONSTRAINT IF EXISTS client_transfer_status_check');
        DB::statement("ALTER TABLE client_transfer_requests ADD CONSTRAINT client_transfer_status_check CHECK (status IN ('REQUESTED','PREACCEPTED','ORIGIN_AUTHORIZED','COMPLETED','REJECTED_BY_RECEIVER','ORIGIN_REJECTED','CANCELLED'))");
        DB::statement("ALTER TABLE client_transfer_requests ADD CONSTRAINT client_transfer_cancellation_check CHECK ((status = 'CANCELLED' AND cancelled_by IS NOT NULL AND cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL) OR (status <> 'CANCELLED' AND cancelled_by IS NULL AND cancelled_at IS NULL AND cancellation_reason IS NULL))");

        DB::statement('DROP TRIGGER IF EXISTS trg_prevent_notification_deliveries_update_delete ON notification_deliveries');
        DB::statement('DROP FUNCTION IF EXISTS prevent_notification_deliveries_mutation()');
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->string('recipient_address')->nullable();
            $table->string('status', 16)->nullable();
            $table->text('result')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->timestampTz('delivered_at')->nullable()->change();
        });
        DB::table('notification_deliveries')->update([
            'status' => 'SENT',
            'result' => 'DELIVERED',
            'last_attempt_at' => DB::raw('delivered_at'),
        ]);
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->string('status', 16)->nullable(false)->change();
        });
        $this->assertNoRows(
            'SELECT d.id FROM notification_deliveries d LEFT JOIN notifications n ON n.id = d.notification_id WHERE n.id IS NULL LIMIT 20',
            'Existen entregas sin notificación',
        );
        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT IF EXISTS notification_deliveries_notification_id_foreign');
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->foreign('notification_id', 'notification_deliveries_notification_id_foreign')
                ->references('id')
                ->on('notifications')
                ->restrictOnDelete()
                ->deferrable()
                ->initiallyImmediate(false);
        });
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_status_check CHECK (status IN ('PENDING','SENT','FAILED'))");
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_result_check CHECK ((status = 'PENDING' AND delivered_at IS NULL AND failed_at IS NULL AND error IS NULL) OR (status = 'SENT' AND delivered_at IS NOT NULL AND failed_at IS NULL AND error IS NULL) OR (status = 'FAILED' AND delivered_at IS NULL AND failed_at IS NOT NULL AND error IS NOT NULL))");
    }

    public function down(): void
    {
        throw new RuntimeException('Las correcciones de integridad son forward-only.');
    }

    private function recreateApplicationForeignKey(string $table): void
    {
        $this->assertNoRows(
            "SELECT child.application_id AS id FROM {$table} child LEFT JOIN distributor_applications parent ON parent.id = child.application_id WHERE parent.id IS NULL LIMIT 20",
            "{$table}.application_id contiene referencias huérfanas",
        );

        $name = "{$table}_application_id_foreign";
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->foreign('application_id', $name)
                ->references('id')
                ->on('distributor_applications')
                ->restrictOnDelete();
        });
    }

    private function assertNoRows(string $sql, string $message): void
    {
        $ids = collect(DB::select($sql))->pluck('id');
        if ($ids->isNotEmpty()) {
            throw new RuntimeException($message.'. IDs: '.$ids->implode(', '));
        }
    }
};
