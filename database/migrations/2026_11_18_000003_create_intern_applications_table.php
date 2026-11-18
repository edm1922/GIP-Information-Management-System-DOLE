<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intern_applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 24)->unique();
            $table->foreignId('registration_link_id')->nullable()->constrained('intern_registration_links')->nullOnDelete();

            // Personal information
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('suffix', 20)->nullable();
            $table->string('email', 255)->index();
            $table->string('contact_number', 30)->nullable();

            // Profile (mirrors the existing intern fields)
            $table->string('school', 150)->nullable();
            $table->string('course', 150)->nullable();
            $table->foreignId('partner_agency_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('intern_type', 50)->nullable();
            $table->date('preferred_start_date')->nullable();
            $table->text('notes')->nullable();

            // Review state
            $table->string('status', 20)->default('pending')->index(); // pending | approved | rejected
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Outcome (unique so one application can never own two accounts)
            $table->foreignId('approved_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_intern_id')->nullable()->unique()->constrained('interns')->nullOnDelete();

            // Credential email delivery tracking (the password itself is never stored)
            $table->string('credential_email_status', 20)->nullable(); // pending | sent | failed
            $table->timestamp('credential_email_sent_at')->nullable();
            $table->text('credential_email_error')->nullable();
            $table->unsignedInteger('credential_email_attempts')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intern_applications');
    }
};
