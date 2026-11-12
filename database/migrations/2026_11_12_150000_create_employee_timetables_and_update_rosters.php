<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rosters', function (Blueprint $table) {
            $table->date('from_date')->change();
            $table->date('to_date')->change();
            $table->string('status')->default('draft')->change();
            $table->foreignId('created_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('created_by');
            $table->timestamp('published_at')->nullable()->after('submitted_at');
            $table->string('decision_comment')->nullable()->after('published_at');
            $table->index(['department_id', 'section_id', 'from_date']);
        });

        Schema::create('employee_timetables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('roster_id')->nullable()->constrained('rosters')->cascadeOnDelete();
            $table->foreignId('roster_code_id')->nullable()->constrained('roster_codes')->nullOnDelete();
            $table->date('day_date');
            $table->string('code', 10);
            $table->string('duty');
            $table->boolean('can_punch')->default(true);
            $table->boolean('pass_day')->default(false);
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->double('work_hour')->default(0);
            $table->string('color', 20)->nullable();
            $table->string('source')->default('roster');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->boolean('overtime')->default(false);
            $table->double('max_overtime_hours')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'day_date', 'source'], 'employee_timetables_day_source_unique');
            $table->index(['roster_id', 'day_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_timetables');
        Schema::table('rosters', function (Blueprint $table) {
            $table->dropIndex(['department_id', 'section_id', 'from_date']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['submitted_at', 'published_at', 'decision_comment']);
        });
    }
};
