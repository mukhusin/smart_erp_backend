<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emp_supervisors', function (Blueprint $table) {
            $table->dropColumn('to_date');
        });
        Schema::table('emp_supervisors', function (Blueprint $table) {
            $table->date('to_date')->nullable()->after('from_date');
            $table->string('reason')->nullable()->after('status');
            $table->foreignId('assigned_by')->nullable()->after('reason')->constrained('users')->nullOnDelete();
            $table->index(['employee_id', 'status']);
            $table->index(['supervisor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('emp_supervisors', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'status']);
            $table->dropIndex(['supervisor_id', 'status']);
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropColumn(['reason', 'to_date']);
        });
        Schema::table('emp_supervisors', fn (Blueprint $table) => $table->string('to_date')->default('current'));
    }
};
