<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messagings', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('type');                          // SMS | Email
            $table->string('from_address')->nullable();
            $table->string('subject')->nullable();           // Email only
            $table->text('message');
            $table->text('extra')->nullable();               // Additional body (email)
            $table->string('response')->nullable();
            $table->datetime('scheduled_at');
            $table->string('recipient_type');                // individual|department|section|active_contracts
            $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->foreignId('section_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->string('status')->default('Pending');    // Pending|Sent|Delivered|Failed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messagings');
    }
};
