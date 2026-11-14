<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messagings', function (Blueprint $table) {
            $table->boolean('popup_active')->default(false)->after('status');
            $table->dateTime('popup_from')->nullable()->after('popup_active');
            $table->dateTime('popup_until')->nullable()->after('popup_from');
        });
    }

    public function down(): void
    {
        Schema::table('messagings', fn (Blueprint $table) => $table->dropColumn(['popup_active', 'popup_from', 'popup_until']));
    }
};
