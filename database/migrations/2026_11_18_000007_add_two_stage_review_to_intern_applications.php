<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intern_applications', function (Blueprint $table) {
            $table->foreignId('peso_reviewer_id')->nullable()->after('reviewer_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('peso_reviewed_at')->nullable()->after('peso_reviewer_id');
            $table->foreignId('admin_reviewer_id')->nullable()->after('peso_reviewed_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('admin_reviewed_at')->nullable()->after('admin_reviewer_id');
        });

        // Backfill the final approver for already-approved applications.
        DB::table('intern_applications')
            ->where('status', 'approved')
            ->whereNotNull('reviewer_id')
            ->update([
                'admin_reviewer_id' => DB::raw('reviewer_id'),
                'admin_reviewed_at' => DB::raw('reviewed_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('intern_applications', function (Blueprint $table) {
            $table->dropForeign(['peso_reviewer_id']);
            $table->dropForeign(['admin_reviewer_id']);
            $table->dropColumn(['peso_reviewer_id', 'peso_reviewed_at', 'admin_reviewer_id', 'admin_reviewed_at']);
        });
    }
};
