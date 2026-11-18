<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_hires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('reference', 60)->nullable();
            $table->foreignId('vehicle_type_id')->nullable()->constrained('fleet_vehicle_types')->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('rate_mode', 6)->default('day');
            $table->decimal('rate', 18, 2);
            $table->string('currency', 3)->default('TZS');
            $table->string('notes', 500)->nullable();
            $table->string('status', 10)->default('active');
            $table->date('ended_on')->nullable();
            $table->string('end_note', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('fleet_hire_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_hire_id')->constrained()->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('quantity', 10, 2);
            $table->decimal('amount', 18, 2);
            $table->foreignId('payment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('fleet_units', function (Blueprint $table) {
            $table->foreignId('fleet_hire_id')->nullable()->after('asset_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fleet_units', fn (Blueprint $table) => $table->dropConstrainedForeignId('fleet_hire_id'));
        Schema::dropIfExists('fleet_hire_payments');
        Schema::dropIfExists('fleet_hires');
    }
};
