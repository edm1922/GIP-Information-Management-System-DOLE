<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-level approval metadata (PESO → Admin) and admin-only per-day
     * hours. Existing DTR day hours are preserved; new intern submissions no
     * longer provide them.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('peso_reviewed_by')->nullable()->after('reviewed_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('peso_reviewed_at')->nullable()->after('peso_reviewed_by');
            $table->text('peso_remarks')->nullable()->after('peso_reviewed_at');

            $table->foreignId('admin_reviewed_by')->nullable()->after('peso_remarks')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('admin_reviewed_at')->nullable()->after('admin_reviewed_by');
            $table->text('admin_remarks')->nullable()->after('admin_reviewed_at');

            $table->foreignId('hours_assigned_by')->nullable()->after('admin_remarks')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('hours_assigned_at')->nullable()->after('hours_assigned_by');
        });

        // Interns now only select dates; hours are assigned by an Admin.
        Schema::table('dtr_days', function (Blueprint $table) {
            $table->decimal('hours', 4, 1)->nullable()->default(null)->change();
        });

        // Map in-flight/returned legacy states to the new workflow statuses.
        DB::table('documents')->where('review_status', 'pending')->update(['review_status' => 'pending_peso']);
        DB::table('documents')->where('review_status', 'rejected')->update(['review_status' => 'returned_to_intern']);

        Schema::table('documents', function (Blueprint $table) {
            $table->string('review_status', 20)->default('pending_peso')->change();
            $table->index('review_status', 'documents_review_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('documents_review_status_index');
        });

        DB::table('documents')->where('review_status', 'pending_peso')->update(['review_status' => 'pending']);
        DB::table('documents')->where('review_status', 'returned_to_intern')->update(['review_status' => 'rejected']);
        DB::table('documents')
            ->whereIn('review_status', ['pending_admin', 'returned_to_peso'])
            ->update(['review_status' => 'pending']);

        Schema::table('documents', function (Blueprint $table) {
            $table->string('review_status', 20)->default('pending')->change();
            $table->dropForeign(['peso_reviewed_by']);
            $table->dropForeign(['admin_reviewed_by']);
            $table->dropForeign(['hours_assigned_by']);
            $table->dropColumn([
                'peso_reviewed_by', 'peso_reviewed_at', 'peso_remarks',
                'admin_reviewed_by', 'admin_reviewed_at', 'admin_remarks',
                'hours_assigned_by', 'hours_assigned_at',
            ]);
        });

        Schema::table('dtr_days', function (Blueprint $table) {
            $table->decimal('hours', 4, 1)->default(8)->nullable(false)->change();
        });
    }
};
