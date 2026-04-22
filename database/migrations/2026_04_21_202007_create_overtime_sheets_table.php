<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('payroll_sheet_detail_id')->constrained('payroll_sheet_details')->onDelete('cascade');
            $table->foreignId('overtime_id')->nullable()->constrained('overtimes')->onDelete('set null');
            $table->string('date_of_task_done');
            $table->double('amount');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_sheets');
    }
};
