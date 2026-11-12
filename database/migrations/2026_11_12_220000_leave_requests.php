<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emp_leaves', function (Blueprint $table) {
            $table->date('start_date')->change();
            $table->date('end_date')->change();
            $table->date('requested_start_date')->change();
            $table->date('requested_end_date')->change();
            $table->date('report_date')->nullable()->change();
            $table->decimal('days', 6, 1)->change();
            $table->decimal('days_requested', 6, 1)->change();
            $table->string('source', 20)->default('employee')->after('status');
            $table->json('details')->nullable()->after('description');
            $table->foreignId('requested_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('requested_by');
            $table->string('decision_comment', 500)->nullable()->after('decided_at');
            $table->timestamp('agreed_at')->nullable()->after('decision_comment');
            $table->index(['employee_id', 'status']);
        });

        Schema::create('emp_leave_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emp_leave_id')->constrained('emp_leaves')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();
        });

        Schema::create('emp_leave_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('emp_leave_id')->constrained('emp_leaves')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('step_name')->nullable();
            $table->string('kind', 20)->default('dates');
            $table->json('before');
            $table->json('after');
            $table->string('comment', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emp_leave_changes');
        Schema::dropIfExists('emp_leave_files');
        Schema::table('emp_leaves', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'status']);
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['source', 'details', 'decided_at', 'decision_comment', 'agreed_at']);
        });
    }
};
