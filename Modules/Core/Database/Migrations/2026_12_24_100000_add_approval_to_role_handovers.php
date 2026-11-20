<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_handovers', function (Blueprint $table) {
            $table->string('status', 12)->default('approved')->after('reason');
            $table->string('source', 10)->default('admin')->after('status');
            $table->boolean('suspend_giver')->default(false)->after('source');
            $table->foreignId('emp_leave_id')->nullable()->after('suspend_giver')->constrained('emp_leaves')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('emp_leave_id');
            $table->string('decision_comment', 500)->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('role_handovers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('emp_leave_id');
            $table->dropColumn(['status', 'source', 'suspend_giver', 'decided_at', 'decision_comment']);
        });
    }
};
