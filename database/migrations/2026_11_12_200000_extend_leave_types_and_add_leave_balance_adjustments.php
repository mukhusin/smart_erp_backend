<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->string('kind', 30)->default('other')->after('code');
            $table->string('day_count', 30)->default('working')->after('max_days');
            $table->string('limit_per', 10)->default('year')->after('day_count');
            $table->unsignedSmallInteger('carry_over_days')->nullable()->after('limit_per');
            $table->unsignedSmallInteger('notice_days')->default(0)->after('carry_over_days');
            $table->boolean('requires_document')->default(false)->after('notice_days');
            $table->json('employee_types')->nullable()->after('requires_document');
            $table->string('color', 20)->nullable()->after('employee_types');
        });

        Schema::create('leave_balance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->unsignedSmallInteger('leave_year');
            $table->decimal('days', 6, 1);
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'leave_type_id', 'leave_year'], 'leave_adjustments_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balance_adjustments');
        Schema::table('leave_types', fn (Blueprint $table) => $table->dropColumn(['kind', 'day_count', 'limit_per', 'carry_over_days', 'notice_days', 'requires_document', 'employee_types', 'color']));
    }
};
