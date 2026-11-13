<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messagings', function (Blueprint $table) {
            $table->json('filters')->nullable()->after('recipient_type');
            $table->unsignedInteger('skipped_count')->default(0)->after('filters');
            $table->timestamp('sent_at')->nullable()->after('status');
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messagings', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropColumn(['filters', 'skipped_count', 'sent_at']);
        });
    }
};
