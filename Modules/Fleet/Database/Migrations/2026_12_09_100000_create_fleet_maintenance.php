<?php

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Hr\Support\DefaultLeaveTypes;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_service_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->foreignId('vehicle_type_id')->nullable()->constrained('fleet_vehicle_types')->cascadeOnDelete();
            $table->foreignId('fleet_unit_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('every_reading', 12, 1)->nullable();
            $table->unsignedSmallInteger('every_months')->nullable();
            $table->decimal('warn_reading', 12, 1)->default(500);
            $table->unsignedSmallInteger('warn_days')->default(14);
            $table->text('tasks')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fleet_accidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('fleet_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('fleet_driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fleet_trip_id')->nullable()->constrained('fleet_trips')->nullOnDelete();
            $table->dateTime('happened_at');
            $table->string('location', 255);
            $table->text('description');
            $table->boolean('injuries')->default(false);
            $table->text('third_party')->nullable();
            $table->string('police_report', 60)->nullable();
            $table->json('photos')->nullable();
            $table->string('status', 15)->default('pending');
            $table->string('outcome', 15)->nullable();
            $table->text('assessment')->nullable();
            $table->string('insurance_claim', 60)->nullable();
            $table->text('decision_comment')->nullable();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('fleet_maintenance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('fleet_unit_id')->constrained()->restrictOnDelete();
            $table->string('kind', 12)->default('corrective');
            $table->foreignId('fleet_service_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fleet_accident_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description');
            $table->decimal('reading', 12, 1)->nullable();
            $table->string('status', 15)->default('pending');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('workshop', 150)->nullable();
            $table->text('findings')->nullable();
            $table->decimal('estimate', 18, 2)->default(0);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('budget_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_requisition_id')->nullable()->constrained()->nullOnDelete();
            $table->date('started_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->decimal('completed_reading', 12, 1)->nullable();
            $table->decimal('actual_cost', 18, 2)->nullable();
            $table->string('invoice_reference', 60)->nullable();
            $table->text('completion_notes')->nullable();
            $table->text('decision_comment')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['fleet_unit_id', 'status']);
        });

        Schema::create('fleet_maintenance_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_maintenance_id')->constrained('fleet_maintenance')->cascadeOnDelete();
            $table->string('kind', 10)->default('parts');
            $table->string('description', 255);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_cost', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->timestamps();
        });

        foreach (Company::pluck('id') as $companyId) {
            app(CompanyContext::class)->run($companyId, fn () => DefaultLeaveTypes::fleet());
        }
    }

    public function down(): void
    {
        foreach (['fleet_maintenance_lines', 'fleet_maintenance', 'fleet_accidents', 'fleet_service_plans'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
