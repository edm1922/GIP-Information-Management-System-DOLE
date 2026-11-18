<?php

namespace App\Mail;

use App\Models\InternApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Credential delivery after an intern application is approved.
 *
 * Sent synchronously (see InternApplicationApprovalService). The temporary
 * password exists only in memory and in this message body; the database keeps
 * a hash only. Delivery failures are recorded on the application row.
 */
class InternCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public InternApplication $application,
        public string $username,
        public string $temporaryPassword,
        public string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your GIMS intern account is ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.intern-credentials',
            with: [
                'applicantName' => $this->application->name,
                'brandName' => config('brand.name'),
            ],
        );
    }
}
