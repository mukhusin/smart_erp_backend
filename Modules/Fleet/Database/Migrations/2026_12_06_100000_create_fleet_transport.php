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
        Schema::create('fleet_transport_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 255);
            $table->string('from_location', 150)->nullable();
            $table->string('destination', 255);
            $table->dateTime('departs_at');
            $table->dateTime('returns_at');
            $table->unsignedSmallInteger('passengers')->default(1);
            $table->text('passenger_names')->nullable();
            $table->string('cargo', 255)->nullable();
            $table->decimal('cargo_weight', 10, 2)->nullable();
            $table->foreignId('vehicle_type_id')->nullable()->constrained('fleet_vehicle_types')->nullOnDelete();
            $table->string('status', 15)->default('pending');
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('fleet_agency_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('reference', 60)->nullable();
            $table->string('mode', 10)->default('trip');
            $table->decimal('rate', 18, 2);
            $table->string('currency', 3)->default('TZS');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fleet_agency_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('fleet_agency_contract_id')->constrained()->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('total', 18, 2);
            $table->foreignId('payment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('fleet_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('transport_request_id')->nullable()->constrained('fleet_transport_requests')->nullOnDelete();
            $table->foreignId('fleet_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('fleet_driver_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('fleet_agency_contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 255);
            $table->string('from_location', 150)->nullable();
            $table->string('destination', 255);
            $table->dateTime('departs_at');
            $table->dateTime('returns_at');
            $table->unsignedSmallInteger('passengers')->default(0);
            $table->string('cargo', 255)->nullable();
            $table->decimal('cargo_weight', 10, 2)->nullable();
            $table->string('status', 15)->default('scheduled');
            $table->string('override_reason', 255)->nullable();
            $table->decimal('start_reading', 12, 1)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->decimal('end_reading', 12, 1)->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('logbook_notes', 500)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->decimal('agency_quantity', 10, 2)->nullable();
            $table->decimal('agency_amount', 18, 2)->nullable();
            $table->foreignId('fleet_agency_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['fleet_unit_id', 'departs_at']);
            $table->index(['fleet_driver_id', 'departs_at']);
        });

        foreach (Company::pluck('id') as $companyId) {
            app(CompanyContext::class)->run($companyId, fn () => DefaultLeaveTypes::fleet());
        }
    }

    public function down(): void
    {
        foreach (['fleet_trips', 'fleet_agency_batches', 'fleet_agency_contracts', 'fleet_transport_requests'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
