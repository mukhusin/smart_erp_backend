<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $table->foreignId('job_position_id')->nullable()->constrained('job_positions')->cascadeOnDelete();
            $table->string('unit', 10)->default('hour');
            $table->decimal('amount', 14, 2);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('overtimes', function (Blueprint $table) {
            $table->date('date_of_task_done')->change();
            $table->dateTime('from_datetime')->change();
            $table->dateTime('to_datetime')->change();
            $table->decimal('amount', 14, 2)->default(0)->change();
            $table->string('type')->default('claim')->change();
            $table->text('description')->nullable()->change();
            $table->foreignId('overtime_rate_id')->nullable()->after('section_id')->constrained('overtime_rates')->nullOnDelete();
            $table->unsignedInteger('overtime_minutes')->default(0)->after('minutes');
            $table->string('rate_unit', 10)->nullable()->after('overtime_minutes');
            $table->decimal('rate_amount', 14, 2)->nullable()->after('rate_unit');
            $table->json('planned')->nullable()->after('description');
            $table->string('source', 20)->default('employee')->after('status');
            $table->foreignId('requested_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('requested_by');
            $table->string('decision_comment', 500)->nullable()->after('decided_at');
            $table->index(['employee_id', 'date_of_task_done']);
        });
    }

    public function down(): void
    {
        Schema::table('overtimes', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'date_of_task_done']);
            $table->dropConstrainedForeignId('overtime_rate_id');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['overtime_minutes', 'rate_unit', 'rate_amount', 'planned', 'source', 'decided_at', 'decision_comment']);
        });
        Schema::dropIfExists('overtime_rates');
    }
};
