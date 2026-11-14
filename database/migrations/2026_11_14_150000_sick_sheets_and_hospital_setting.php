<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_settings', fn (Blueprint $table) => $table->boolean('hospital_patients')->default(false)->after('joiners_from'));
        Schema::table('excuse_of_duties', function (Blueprint $table) {
            $table->string('source', 20)->default('hr')->after('status');
            $table->foreignId('requested_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('requested_by');
            $table->text('decision_comment')->nullable()->after('decided_at');
            $table->string('file_path')->nullable()->after('decision_comment');
            $table->string('file_name')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('hr_settings', fn (Blueprint $table) => $table->dropColumn('hospital_patients'));
        Schema::table('excuse_of_duties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['source', 'decided_at', 'decision_comment', 'file_path', 'file_name']);
        });
    }
};
