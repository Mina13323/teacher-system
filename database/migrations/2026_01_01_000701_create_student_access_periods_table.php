<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_access_periods')) {
            Schema::create('student_access_periods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->string('status', 32)->default('active')->index();
                $table->timestamp('starts_at')->index();
                $table->timestamp('expires_at')->index();
                $table->decimal('amount', 10, 2)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->index(['student_id', 'status']);
                $table->index(['student_id', 'expires_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_access_periods');
    }
};
