<?php

namespace App\Notifications;

use App\Models\InternDocument;
use Illuminate\Notifications\Notification;

class InternDocumentSubmitted extends Notification
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
            'kind' => 'intern_document_submitted',
            'document_id' => (int) $this->document->id,
            'intern_id' => (int) $this->document->intern_id,
            'intern_name' => $this->document->intern?->name,
            'document_type' => $type,
            'message' => sprintf('%s submitted a %s for your review.', $this->document->intern?->name ?? 'An intern', $type),
            'url' => '/intern-documents?review_status=pending_peso',
        ];
    }
}
