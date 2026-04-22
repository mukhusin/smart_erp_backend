<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('employee_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('nature_of_contract_id')->nullable()->constrained('nature_of_contracts')->onDelete('set null');
            $table->foreignId('salary_scale_id')->nullable()->constrained('salary_scales')->onDelete('set null');
            $table->string('contract_no')->nullable();
            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->integer('period')->nullable()->comment('months');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('date_of_change')->nullable();
            $table->text('comment')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('status')->default('pending');
            $table->softDeletes();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('employee_contracts'); }
};
