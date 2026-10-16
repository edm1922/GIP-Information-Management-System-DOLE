<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('intern_id')->nullable()->after('department_id')
                ->constrained('interns')->nullOnDelete();
            $table->string('document_period', 100)->nullable()->after('status');
            $table->string('review_status', 20)->default('pending')->after('document_period');
            $table->foreignId('reviewed_by')->nullable()->after('review_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_remarks')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['intern_id']);
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn([
                'intern_id',
                'document_period',
                'review_status',
                'reviewed_by',
                'reviewed_at',
                'review_remarks',
            ]);
        });
    }
};
