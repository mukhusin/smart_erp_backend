<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('head_of_departments', function (Blueprint $table) {
            $table->date('started_at')->nullable()->after('level');
            $table->date('ended_at')->nullable()->after('started_at');
            $table->foreignId('appointed_by')->nullable()->after('change_reason')->constrained('users')->nullOnDelete();
            $table->index(['department_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('head_of_departments', function (Blueprint $table) {
            $table->dropIndex(['department_id', 'is_active']);
            $table->dropConstrainedForeignId('appointed_by');
            $table->dropColumn(['started_at', 'ended_at']);
        });
    }
};
