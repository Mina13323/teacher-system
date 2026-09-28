<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_registration_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('token');
            $table->string('token_hash', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_registration_links');
    }
};
