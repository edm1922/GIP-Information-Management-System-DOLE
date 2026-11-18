<?php

namespace App\Notifications;

use App\Models\InternDocument;
use Illuminate\Notifications\Notification;

class InternDocumentReturnedToIntern extends Notification
{
    public function __construct(public readonly InternDocument $document) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $type = $this->document->documentType?->name ?? 'document';

        return [
            'kind' => 'intern_document_returned_to_intern',
            'document_id' => (int) $this->document->id,
            'intern_id' => (int) $this->document->intern_id,
            'document_type' => $type,
            'remarks' => $this->document->peso_remarks ?? $this->document->admin_remarks,
            'message' => sprintf(
                'Your %s was returned for correction.%s',
                $type,
                ($this->document->peso_remarks ?? $this->document->admin_remarks)
                    ? ' Remarks: '.($this->document->peso_remarks ?? $this->document->admin_remarks)
                    : ''
            ),
            'url' => $this->document->documentType?->code === 'DTR' ? '/my-internship' : '/my-internship/201-files',
        ];
    }
}
