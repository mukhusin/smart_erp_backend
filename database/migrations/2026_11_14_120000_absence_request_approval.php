<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absence_requests', function (Blueprint $table) {
            $table->foreignId('requested_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('requested_by');
            $table->text('decision_comment')->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('absence_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['decided_at', 'decision_comment']);
        });
    }
};
