<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only staff audit trail for privileged actions (logins, password
 * resets, grade publications, terminations, disqualifications, imports,
 * exports, archives...).
 *
 * PRODUCTION DATA SAFETY: new table only. Metadata is JSON and must never hold
 * secrets (passwords, tokens, full answer keys) — writers are responsible for
 * logging identifiers and outcomes only.
 *
 * Rollback: drops the audit trail (the only loss is the record of who did what).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 32)->nullable();
            // Verb, e.g. login, password.reset, exam.publish, grade.publish,
            // attempt.terminate, student.import, results.export ...
            $table->string('action', 96);
            // Polymorphic-ish target without FK churn: type + id.
            $table->string('target_type', 96)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
