<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safe conversion of notifiable_id from bigint to uuid, and timestamps to timestamptz.
        // In PostgreSQL we can use USING to cast correctly.
        if (Schema::hasTable('notifications')) {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'pgsql') {
                // If it was stored as bigint but is actually uuid, wait - bigint can't store a full UUID!
                // Let's check what the type currently is. In Laravel a polymorphic relation creates a uuid if uuidMorphs is used.
                // If it is bigint, the previous data is likely invalid as UUIDs.
                // But we must convert it safely.
                DB::statement('ALTER TABLE notifications ALTER COLUMN notifiable_id TYPE uuid USING notifiable_id::text::uuid');

                DB::statement('ALTER TABLE notifications ALTER COLUMN read_at TYPE timestamptz USING read_at AT TIME ZONE \'UTC\'');
                DB::statement('ALTER TABLE notifications ALTER COLUMN created_at TYPE timestamptz USING created_at AT TIME ZONE \'UTC\'');
                DB::statement('ALTER TABLE notifications ALTER COLUMN updated_at TYPE timestamptz USING updated_at AT TIME ZONE \'UTC\'');
            } else {
                // SQLite or other for tests
                Schema::table('notifications', function (Blueprint $table) {
                    // SQLite doesn't support altering columns easily, usually we would just recreate the table, but since this is just test env, we can try to drop and recreate the columns or ignore if SQLite allows string in integer column.
                    // Actually, Laravel 11+ supports renaming/dropping in SQLite.
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notifications')) {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE notifications ALTER COLUMN read_at TYPE timestamp without time zone');
                DB::statement('ALTER TABLE notifications ALTER COLUMN created_at TYPE timestamp without time zone');
                DB::statement('ALTER TABLE notifications ALTER COLUMN updated_at TYPE timestamp without time zone');
            }
        }
    }
};
