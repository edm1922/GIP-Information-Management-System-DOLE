<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Structured working days claimed by a DTR upload.
     *
     * The uploaded scan is only evidence for the reviewer; these rows are the
     * authoritative record of which calendar days the intern claims to have
     * worked, and they drive the work calendar on the intern dashboard.
     */
    public function up(): void
    {
        Schema::create('dtr_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('intern_id')->constrained('interns')->cascadeOnDelete();
            $table->date('work_date');
            $table->decimal('hours', 4, 1)->default(8);
            $table->timestamps();

            // One row per day per document; cross-document overlap is checked
            // in the request layer so rejected documents can be re-submitted.
            $table->unique(['document_id', 'work_date']);
            $table->index(['intern_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtr_days');
    }
};
