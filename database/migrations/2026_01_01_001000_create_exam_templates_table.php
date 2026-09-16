<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable exam structures.
 *
 * A template describes the *shape* of an exam — how many questions of each
 * type and what each is worth — without any content. Applying one appends that
 * many blank questions to an exam, so a teacher writes only the question text
 * and marks the answers instead of configuring points 50 times.
 *
 * Templates ship in two flavours:
 *  - system templates, seeded with the app, read-only and translatable;
 *  - teacher templates, created from an existing exam and owned by its creator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            // Null means the template was seeded with the system rather than
            // created by a teacher, so it has no owner to edit or delete it.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_system')->default(false);

            // Stable key for seeded templates so the UI can render the label in
            // the active locale. Teacher-created templates leave it null and
            // fall back to the free-text `name`.
            $table->string('preset_key')->nullable()->unique();

            $table->timestamps();
        });

        Schema::create('exam_template_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_template_id')->constrained()->cascadeOnDelete();

            // Mirrors App\Enums\QuestionType. Stored as a string so a new
            // question type never needs a schema change.
            $table->string('question_type');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('points');
            $table->unsignedInteger('position')->default(1);

            $table->timestamps();

            $table->index(['exam_template_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_template_sections');
        Schema::dropIfExists('exam_templates');
    }
};
