<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_number_formats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('separator', 1)->default('');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('employee_number_format_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('format_id')->constrained('employee_number_formats')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('type', 20);
            $table->string('value', 50)->nullable();
            $table->unsignedTinyInteger('length')->nullable();
            $table->unsignedInteger('start_at')->nullable();
            $table->timestamps();
        });

        Schema::table('nature_of_contracts', function (Blueprint $table) {
            $table->foreignId('employee_number_format_id')->nullable()->after('employment_type')->constrained('employee_number_formats')->nullOnDelete();
        });

        Schema::create('hr_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->boolean('renumber_on_contract_change')->default(false);
            $table->timestamps();
        });

        Schema::table('employee_contract_events', function (Blueprint $table) {
            $table->json('changes')->nullable()->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('employee_contract_events', fn (Blueprint $table) => $table->dropColumn('changes'));
        Schema::dropIfExists('hr_settings');
        Schema::table('nature_of_contracts', fn (Blueprint $table) => $table->dropConstrainedForeignId('employee_number_format_id'));
        Schema::dropIfExists('employee_number_format_segments');
        Schema::dropIfExists('employee_number_formats');
    }
};
