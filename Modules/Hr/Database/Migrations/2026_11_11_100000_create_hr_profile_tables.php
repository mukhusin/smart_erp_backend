<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_licences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('provider_name')->nullable();
            $table->string('short_name', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('employee_licences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('professional_licence_id')->nullable()->constrained('professional_licences')->nullOnDelete();
            $table->string('licence_no', 100)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_contract_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_contract_id')->constrained('employee_contracts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            $table->string('status', 50);
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('has_annual_leave')->default(true)->after('contract_status');
        });

        Schema::table('employee_qualifications', function (Blueprint $table) {
            $table->date('date_of_start')->nullable()->change();
            $table->date('date_of_end')->nullable()->change();
            $table->string('certificate_name')->nullable()->after('certificate');
        });

        Schema::table('employee_experiences', function (Blueprint $table) {
            $table->date('start_date')->change();
            $table->date('end_date')->nullable()->change();
        });

        Schema::table('employee_dependants', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->change();
            $table->string('birth_certificate_name')->nullable()->after('birth_certificate');
        });

        Schema::table('employee_documents', function (Blueprint $table) {
            $table->unsignedInteger('size')->nullable()->after('file_type');
            $table->string('mime_type', 100)->nullable()->after('size');
            $table->foreignId('uploaded_by')->nullable()->after('description')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uploaded_by');
            $table->dropColumn(['size', 'mime_type']);
        });
        Schema::table('employee_dependants', fn (Blueprint $table) => $table->dropColumn('birth_certificate_name'));
        Schema::table('employee_qualifications', fn (Blueprint $table) => $table->dropColumn('certificate_name'));
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('has_annual_leave'));
        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
        });
        Schema::dropIfExists('employee_contract_events');
        Schema::dropIfExists('employee_licences');
        Schema::dropIfExists('professional_licences');
    }
};
