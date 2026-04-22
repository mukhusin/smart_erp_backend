<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_timetables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('department_id')->constrained('departments')->onDelete('cascade');
            $table->foreignId('section_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->string('title');
            $table->string('work_hour')->default(0);
            $table->string('check_in_start');
            $table->string('check_in_end');
            $table->string('check_out_start');
            $table->string('check_out_end');
            $table->integer('cross_day')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('prepared_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->string('status')->default('active');
            $table->boolean('use_attendance')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_timetables');
    }
};
