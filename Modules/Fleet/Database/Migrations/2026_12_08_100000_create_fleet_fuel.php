<?php

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\DefaultRoles;
use Modules\Hr\Support\DefaultLeaveTypes;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_fuel_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('kind', 10)->default('own');
            $table->string('fuel_type', 15);
            $table->decimal('capacity', 12, 2)->nullable();
            $table->decimal('reorder_level', 12, 2)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('account_reference', 60)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fleet_fuel_recharges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('fleet_fuel_store_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('litres', 12, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 18, 2);
            $table->string('status', 12)->default('ordered');
            $table->decimal('received_litres', 12, 2)->nullable();
            $table->date('received_on')->nullable();
            $table->string('delivery_note', 60)->nullable();
            $table->foreignId('payment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('fleet_fuel_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('fleet_fuel_store_id')->constrained()->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('litres', 12, 2);
            $table->decimal('total', 18, 2);
            $table->foreignId('payment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('fleet_fuel_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('fleet_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('fleet_driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fleet_trip_id')->nullable()->constrained('fleet_trips')->nullOnDelete();
            $table->foreignId('fleet_fuel_store_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('litres', 10, 2);
            $table->decimal('reading', 12, 1);
            $table->string('purpose', 255)->nullable();
            $table->json('warnings')->nullable();
            $table->string('status', 12)->default('pending');
            $table->decimal('issued_litres', 10, 2)->nullable();
            $table->decimal('issue_reading', 12, 1)->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('receipt_reference', 60)->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('fleet_fuel_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->text('decision_comment')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['fleet_unit_id', 'status']);
        });

        Schema::create('fleet_fuel_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_fuel_store_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('type', 10);
            $table->decimal('litres', 12, 2);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->foreignId('fleet_fuel_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fleet_fuel_recharge_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['fleet_fuel_store_id', 'date']);
        });

        if (! Company::query()->exists()) {
            return;
        }
        DefaultRoles::syncPermissions();
        foreach (Company::pluck('id') as $companyId) {
            app(CompanyContext::class)->run($companyId, fn () => DefaultLeaveTypes::fleet());
        }
    }

    public function down(): void
    {
        foreach (['fleet_fuel_movements', 'fleet_fuel_requests', 'fleet_fuel_batches', 'fleet_fuel_recharges', 'fleet_fuel_stores'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
