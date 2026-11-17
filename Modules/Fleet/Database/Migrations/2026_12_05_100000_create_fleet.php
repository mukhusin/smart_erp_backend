<?php

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\DefaultRoles;
use Modules\Hr\Support\DefaultLeaveTypes;

return new class extends Migration
{
    private const LEGACY = ['vehicle_maintenance_logs', 'fuel_records', 'vehicles'];

    public function up(): void
    {
        foreach (self::LEGACY as $legacy) {
            if (Schema::hasTable($legacy)) {
                if (DB::table($legacy)->exists()) {
                    throw new RuntimeException("Legacy table {$legacy} has data; move it before installing Fleet.");
                }
                Schema::disableForeignKeyConstraints();
                Schema::drop($legacy);
                Schema::enableForeignKeyConstraints();
            }
        }

        Schema::create('fleet_vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('kind', 10)->default('vehicle');
            $table->json('licence_classes')->nullable();
            $table->decimal('tank_capacity', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('fleet_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10)->default('vehicle');
            $table->foreignId('vehicle_type_id')->nullable()->constrained('fleet_vehicle_types')->nullOnDelete();
            $table->string('registration', 30)->nullable();
            $table->string('name', 150);
            $table->string('make', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('chassis_number', 60)->nullable();
            $table->string('engine_number', 60)->nullable();
            $table->string('colour', 30)->nullable();
            $table->unsignedSmallInteger('seats')->nullable();
            $table->string('fuel_type', 15)->nullable();
            $table->decimal('tank_capacity', 10, 2)->nullable();
            $table->string('ownership', 12)->default('owned');
            $table->string('owner_name', 150)->nullable();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location', 150)->nullable();
            $table->string('status', 15)->default('operational');
            $table->string('status_note', 255)->nullable();
            $table->decimal('reading', 12, 1)->default(0);
            $table->date('reading_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'registration']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('fleet_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_unit_id')->constrained()->cascadeOnDelete();
            $table->decimal('reading', 12, 1);
            $table->decimal('previous', 12, 1)->nullable();
            $table->date('date');
            $table->string('source', 15)->default('manual');
            $table->nullableMorphs('document');
            $table->string('status', 12)->default('approved');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamps();
            $table->index(['fleet_unit_id', 'date']);
        });

        Schema::create('fleet_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_unit_id')->constrained()->cascadeOnDelete();
            $table->string('from', 15)->nullable();
            $table->string('to', 15);
            $table->string('note', 255)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fleet_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'employee_id']);
        });

        Schema::create('fleet_driver_licences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_driver_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->json('classes');
            $table->date('issued_on')->nullable();
            $table->date('expires_on');
            $table->boolean('is_current')->default(true);
            $table->string('document_path')->nullable();
            $table->string('document_name', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fleet_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fleet_driver_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['fleet_unit_id', 'ended_on']);
        });

        Schema::create('fleet_compliance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_unit_id')->constrained()->cascadeOnDelete();
            $table->string('type', 15);
            $table->string('reference', 60)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 150)->nullable();
            $table->string('cover', 60)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('expires_on');
            $table->decimal('amount', 18, 2)->nullable();
            $table->boolean('is_current')->default(true);
            $table->string('document_path')->nullable();
            $table->string('document_name', 200)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['fleet_unit_id', 'type', 'is_current']);
        });

        Schema::create('fleet_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_unit_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('category', 15);
            $table->decimal('amount', 18, 2);
            $table->string('description', 255)->nullable();
            $table->nullableMorphs('document');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['fleet_unit_id', 'date']);
        });

        DB::table('erp_modules')->where('slug', 'fleet')->update(['name' => 'Fleet',
            'description' => 'Vehicles and generators, drivers and licences, readings, compliance and running costs.']);

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
        foreach (['fleet_costs', 'fleet_compliance', 'fleet_assignments', 'fleet_driver_licences', 'fleet_drivers', 'fleet_status_changes', 'fleet_readings', 'fleet_units', 'fleet_vehicle_types'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
