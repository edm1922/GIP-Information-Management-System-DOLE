<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('partner_agency_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('host_department_id')->nullable()->constrained('departments')->nullOnDelete();

            // Contract
            $table->date('contract_start_date')->nullable();
            $table->date('contract_end_date')->nullable();

            // Hour monitoring
            $table->unsignedInteger('required_hours')->default(486);
            $table->unsignedInteger('rendered_hours')->default(0);

            // Classification
            $table->string('status', 20)->default('active'); // active | inactive | completed
            $table->string('intern_type', 50)->nullable();   // GIP | OJT | SPES | Work Immersion
            $table->string('batch', 50)->nullable();

            // Profile
            $table->string('school')->nullable();
            $table->string('course')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('supervisor')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('contract_end_date');
            $table->index('partner_agency_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interns');
    }
};
