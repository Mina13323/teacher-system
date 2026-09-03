<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            // Server-controlled integrity signals. Never writable from client
            // input; they are updated only through trusted Actions/Services.
            $table->string('integrity_status')->default('normal')->after('status');
            $table->unsignedInteger('risk_score')->default(0)->after('integrity_status');

            $table->index(['exam_id', 'integrity_status']);
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropIndex(['exam_id', 'integrity_status']);
            $table->dropColumn(['integrity_status', 'risk_score']);
        });
    }
};
