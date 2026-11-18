<?php

namespace App\Notifications;

use App\Models\InternApplication;
use Illuminate\Notifications\Notification;

/** Admin gave final approval and credentials were emailed. */
class InternApplicationApproved extends Notification
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
            'kind' => 'intern_application_approved',
            'application_id' => (int) $this->application->id,
            'reference' => $this->application->reference,
            'applicant_name' => $this->application->name,
            'message' => sprintf('%s was finally approved by the Admin; credentials were emailed.', $this->application->name),
            'url' => '/interns/applications',
        ];
    }
}
