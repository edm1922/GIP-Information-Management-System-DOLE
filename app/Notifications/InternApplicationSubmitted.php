<?php

namespace App\Notifications;

use App\Models\InternApplication;
use Illuminate\Notifications\Notification;

/**
 * Database notification for Admins / PESO Managers when a public application
 * is submitted (drives the topbar bell badge).
 */
class InternApplicationSubmitted extends Notification
{
    public function __construct(
        public readonly InternApplication $application,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'intern_application_submitted',
            'application_id' => (int) $this->application->id,
            'reference' => $this->application->reference,
            'applicant_name' => $this->application->name,
            'message' => sprintf('%s submitted an intern application.', $this->application->name),
            'url' => '/interns/applications',
        ];
    }
}
