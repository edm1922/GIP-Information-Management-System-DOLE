<?php

namespace App\Notifications;

use App\Models\InternDocument;
use Illuminate\Notifications\Notification;

class InternDocumentReviewed extends Notification
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
        $approved = $this->document->review_status === 'approved';
        $type = $this->document->documentType?->name ?? 'Document';

        $urlRoute = $this->document->documentType?->code === 'DTR'
            ? 'my-internship.index'
            : 'my-internship.files';

        return [
            'kind' => 'intern_document_reviewed',
            'document_id' => (int) $this->document->id,
            'intern_id' => (int) $this->document->intern_id,
            'document_type' => $type,
            'review_status' => $this->document->review_status,
            'review_remarks' => $this->document->review_remarks,
            'document_name' => $this->document->original_filename,
            'message' => sprintf(
                'Your %s was %s.%s',
                $type,
                $approved ? 'approved' : 'rejected',
                $this->document->review_remarks ? ' Remarks: '.$this->document->review_remarks : ''
            ),
            // Deep-link to the tab the document belongs to.
            'url_route' => $urlRoute,
            'url_params' => [],
        ];
    }
}
