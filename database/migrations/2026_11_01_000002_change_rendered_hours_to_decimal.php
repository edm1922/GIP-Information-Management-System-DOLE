<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-day DTR hours can be fractional (8.2, 7.8, …), so the credited total
     * must hold one decimal place instead of rounding to whole hours.
     */
    public function up(): void
    {
        Schema::table('interns', function (Blueprint $table) {
            $table->decimal('rendered_hours', 7, 1)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('interns', function (Blueprint $table) {
            $table->unsignedInteger('rendered_hours')->default(0)->change();
        });
    }
};
