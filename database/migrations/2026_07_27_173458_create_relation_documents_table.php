<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relation_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('relation_id')->index();
            $table->unsignedInteger('document_version');
            $table->string('status');
            $table->string('storage_key')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->string('sha256')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();

            $table->unique(['relation_id', 'document_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relation_documents');
    }
};
