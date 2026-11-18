<?php

namespace App\Notifications;

use App\Models\InternDocument;
use Illuminate\Notifications\Notification;

class InternDocumentApproved extends Notification
{
    public function __construct(public readonly InternDocument $document) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $type = $this->document->documentType?->name ?? 'document';
        $internName = $this->document->intern?->name ?? 'An intern';
        $isIntern = ($notifiable->role ?? null) === 'intern';

        return [
            'kind' => 'intern_document_approved',
            'document_id' => (int) $this->document->id,
            'intern_id' => (int) $this->document->intern_id,
            'intern_name' => $internName,
            'document_type' => $type,
            'message' => $isIntern
                ? sprintf('Your %s was approved by the Admin.', $type)
                : sprintf('%s - %s was finally approved by the Admin.', $internName, $type),
            'url' => $isIntern
                ? ($this->document->documentType?->code === 'DTR' ? '/my-internship' : '/my-internship/201-files')
                : '/intern-documents?review_status=approved',
        ];
    }
}
