<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Free-text beneficiary reference (name / code as it appears on the program
     * documents) recorded when an intern is created.
     */
    public function up(): void
    {
        Schema::table('interns', function (Blueprint $table) {
            $table->string('beneficiary', 255)->nullable()->after('batch');
        });
    }

    public function down(): void
    {
        Schema::table('interns', function (Blueprint $table) {
            $table->dropColumn('beneficiary');
        });
    }
};
