<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 2 §25/§24 — Background export registry (additive, data-safe).
 *
 * Large exports are queued (GenerateResultExportJob) and recorded here so the
 * requester can download the finished file from the private disk and so
 * operations can see failures. Rows are append-only status records of export
 * runs; no existing data is touched.
 *
 * Rollback: drop the table. Deployment: additive, any time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 40);           // results, integrity, transcript, ...
            $table->string('format', 10);         // csv | xlsx | pdf
            $table->nullableMorphs('subject');    // e.g. the Exam
            $table->string('status', 20)->default('queued'); // queued|running|done|failed
            $table->string('file_path')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
