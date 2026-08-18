<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('file_type', 50);
            $table->string('disk');
            $table->text('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->uuid('uploaded_by');
            $table->timestampsTz();

            $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
