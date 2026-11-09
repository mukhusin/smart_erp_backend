<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('nature_of_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('contract_name')->default('Full Time Contract');
            $table->string('code')->default('FTC');
            $table->string('employment_type')->default('FULL TIME');
            $table->boolean('has_annual_leave')->default(true);
            $table->boolean('has_payroll')->default(true);
            $table->boolean('has_overtime')->default(false);
            $table->boolean('has_allowance')->default(false);
            $table->boolean('has_emergency_fund')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('contract_description')->nullable();
            $table->longText('template_content')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('nature_of_contracts'); }
};
