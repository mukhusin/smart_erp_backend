<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('expense_class', 20)->nullable()->after('description');
        });
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->boolean('expense_classes')->default(false);
            $table->decimal('programme_target', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('departments', fn (Blueprint $table) => $table->dropColumn('expense_class'));
        Schema::table('accounting_settings', fn (Blueprint $table) => $table->dropColumn(['expense_classes', 'programme_target']));
    }
};
