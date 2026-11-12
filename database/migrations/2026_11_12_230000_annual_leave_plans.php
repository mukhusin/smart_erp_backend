<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annual_leave_rosters', function (Blueprint $table) {
            $table->date('start_date')->change();
            $table->date('end_date')->change();
            $table->string('status')->default('planned')->change();
            $table->foreignId('emp_leave_id')->nullable()->after('employee_id')->constrained('emp_leaves')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->after('emp_leave_id')->constrained('users')->nullOnDelete();
            $table->timestamp('reminded_at')->nullable()->after('created_by');
            $table->index(['employee_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::table('annual_leave_rosters', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'start_date']);
            $table->dropConstrainedForeignId('emp_leave_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('reminded_at');
        });
    }
};
