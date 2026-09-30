<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lesson attachments (PDF, slides, worksheets, supporting files).
 *
 * PRODUCTION DATA SAFETY: new table only. Files live on a private/app disk and
 * are streamed only through enrollment-checked endpoints.
 *
 * Rollback: dropping the table orphans files on disk but touches no existing
 * academic record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('file_mime', 128)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['lesson_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_attachments');
    }
};
