<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_sms_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('driver', 20)->default('log');
            $table->string('sender_id', 20)->nullable();
            $table->boolean('contract_reminders')->default(true);
            $table->timestamps();
        });

        Schema::table('messagings', function (Blueprint $table) {
            $table->string('campaign', 50)->nullable()->after('type');
            $table->string('campaign_key', 100)->nullable()->after('campaign');
            $table->index(['campaign', 'campaign_key']);
        });

        Schema::table('message_recipients', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->text('individual_message')->nullable()->after('recipient_address');
        });
    }

    public function down(): void
    {
        Schema::table('message_recipients', fn (Blueprint $table) => $table->dropColumn(['uuid', 'individual_message']));
        Schema::table('messagings', function (Blueprint $table) {
            $table->dropIndex(['campaign', 'campaign_key']);
            $table->dropColumn(['campaign', 'campaign_key']);
        });
        Schema::dropIfExists('company_sms_settings');
    }
};
