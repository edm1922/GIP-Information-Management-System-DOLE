<?php

namespace App\Notifications;

use App\Models\InternDocument;
use Illuminate\Notifications\Notification;

class InternDocumentReturnedToPeso extends Notification
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
            'kind' => 'intern_document_returned_to_peso',
            'document_id' => (int) $this->document->id,
            'intern_id' => (int) $this->document->intern_id,
            'intern_name' => $this->document->intern?->name,
            'document_type' => $type,
            'remarks' => $this->document->admin_remarks,
            'message' => sprintf(
                'Admin returned the %s for %s. Review the remarks and send it back to the intern if corrections are needed.',
                $type,
                $this->document->intern?->name ?? 'the intern'
            ),
            'url' => '/intern-documents?review_status=returned_to_peso',
        ];
    }
}
