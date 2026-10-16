<?php

namespace App\Notifications;

use App\Models\InternDocument;
use Illuminate\Notifications\Notification;

class InternDocumentUploaded extends Notification
{
    public function __construct(
        public readonly InternDocument $document,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $intern = $this->document->intern;
        $type = $this->document->documentType?->name ?? 'Document';

        return [
            'kind' => 'intern_document_uploaded',
            'document_id' => (int) $this->document->id,
            'intern_id' => (int) $this->document->intern_id,
            'intern_name' => $intern?->name,
            'document_type' => $type,
            'document_name' => $this->document->original_filename,
            'message' => sprintf('%s uploaded a %s for review.', $intern?->name ?? 'An intern', $type),
            'url_route' => 'intern-documents.index',
            'url_params' => [],
        ];
    }
}
