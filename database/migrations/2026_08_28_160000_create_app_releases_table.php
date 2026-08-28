<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_releases', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 24)->unique();
            $table->string('version', 40);
            $table->string('original_name');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type', 120);
            $table->string('checksum_sha256', 64);
            $table->text('release_notes')->nullable();
            $table->unsignedBigInteger('download_count')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};
