<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2 — Web Push subscriptions (additive, data-safe).
 *
 * Stores browser push endpoints (RFC 8030) with their encryption keys
 * (RFC 8291). Purely additive: a new table, no existing rows touched.
 * Rollback: drop the table; no other data references it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('endpoint', 500)->unique();
            $table->json('keys'); // { p256dh, auth } — RFC 8291 key material
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
