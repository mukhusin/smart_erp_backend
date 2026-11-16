<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->decimal('min_amount', 18, 2)->nullable()->after('allow_send_back');
        });
        Schema::table('workflow_instance_steps', function (Blueprint $table) {
            $table->decimal('min_amount', 18, 2)->nullable()->after('allow_send_back');
        });
        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->decimal('amount', 18, 2)->nullable()->after('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_steps', fn (Blueprint $table) => $table->dropColumn('min_amount'));
        Schema::table('workflow_instance_steps', fn (Blueprint $table) => $table->dropColumn('min_amount'));
        Schema::table('workflow_instances', fn (Blueprint $table) => $table->dropColumn('amount'));
    }
};
