<?php

namespace App\Notifications;

use App\Models\InternApplication;
use Illuminate\Notifications\Notification;

/** PESO forwarded an application; the Admin must do the final approval. */
class InternApplicationPesoApproved extends Notification
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
            'kind' => 'intern_application_pending_admin',
            'application_id' => (int) $this->application->id,
            'reference' => $this->application->reference,
            'applicant_name' => $this->application->name,
            'message' => sprintf('%s was endorsed by PESO and needs final Admin approval.', $this->application->name),
            'url' => '/interns/applications',
        ];
    }
}
