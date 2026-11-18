<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_settings', function (Blueprint $table) {
            $table->boolean('profile_required')->default(false);
            $table->unsignedTinyInteger('profile_min_percent')->default(75);
            $table->json('profile_checks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hr_settings', fn (Blueprint $t) => $t->dropColumn(['profile_required', 'profile_min_percent', 'profile_checks']));
    }
};
