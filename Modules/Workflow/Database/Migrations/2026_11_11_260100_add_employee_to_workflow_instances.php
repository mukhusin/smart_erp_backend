<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_instances', function (Blueprint $table) {
            /** The employee the request is about; used to resolve supervisor approvers. */
            $table->foreignId('employee_id')->nullable()->after('department_id')->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_instances', fn (Blueprint $table) => $table->dropConstrainedForeignId('employee_id'));
    }
};
