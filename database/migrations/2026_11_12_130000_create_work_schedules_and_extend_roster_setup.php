<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->json('days');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('work_schedule_id')->nullable()->after('parent_id')->constrained('work_schedules')->nullOnDelete();
        });

        Schema::table('roster_codes', function (Blueprint $table) {
            $table->unsignedSmallInteger('break_minutes')->default(0)->after('work_hour');
            $table->string('color', 20)->nullable()->after('break_minutes');
            $table->boolean('is_active')->default(true)->after('color');
        });

        Schema::table('public_holidays', function (Blueprint $table) {
            $table->string('day_number')->nullable()->change();
            $table->string('month_number')->nullable()->change();
            $table->date('holiday_date')->nullable()->after('month_number');
        });
    }

    public function down(): void
    {
        Schema::table('public_holidays', fn (Blueprint $table) => $table->dropColumn('holiday_date'));
        Schema::table('roster_codes', fn (Blueprint $table) => $table->dropColumn(['break_minutes', 'color', 'is_active']));
        Schema::table('departments', fn (Blueprint $table) => $table->dropConstrainedForeignId('work_schedule_id'));
        Schema::dropIfExists('work_schedules');
    }
};
