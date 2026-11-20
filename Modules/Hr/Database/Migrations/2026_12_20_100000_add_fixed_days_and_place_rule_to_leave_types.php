<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('fixed_days')->default(false)->after('max_days');
            $table->string('place_rule', 10)->nullable()->after('fixed_days');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', fn (Blueprint $t) => $t->dropColumn(['fixed_days', 'place_rule']));
    }
};
