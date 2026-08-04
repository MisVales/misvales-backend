<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status');
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'name']);
        });

        DB::statement("ALTER TABLE categories ADD CONSTRAINT chk_cat_lock_version CHECK (lock_version >= 0);");
        DB::statement("ALTER TABLE categories ADD CONSTRAINT chk_cat_status CHECK (status IN ('ACTIVE', 'INACTIVE'));");
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
