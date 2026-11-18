<?php

namespace App\Services;

use App\Http\Requests\StoreInternDocumentRequest;
use App\Models\DocumentType;
use App\Models\DtrDay;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\InternDocumentReviewEvent;
use App\Models\User;
use App\Notifications\InternDocumentApproved;
use App\Notifications\InternDocumentPesoApproved;
use App\Notifications\InternDocumentResubmitted;
use App\Notifications\InternDocumentReturnedToIntern;
use App\Notifications\InternDocumentReturnedToPeso;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sequential approval workflow for intern documents:
 *
 * Intern upload → PESO review → Admin final review (hours assignment).
 * Admin may return to PESO; PESO may send back to the intern, who resubmits.
 */
class InternDocumentWorkflowService
{
    public function __construct(
        private readonly InternNotificationService $notifier,
        private readonly InternHoursService $hours,
        private readonly InternDocumentUploadService $uploads,
    ) {}

    public function pesoApprove(InternDocument $document, User $actor, ?string $remarks = null): InternDocument
    {
        [$fresh, $before] = DB::transaction(function () use ($document, $actor, $remarks) {
            $locked = $this->lock($document);
            $this->assertStatus($locked, [InternDocument::STATUS_PENDING_PESO]);

            $before = $locked->review_status;
            $locked->forceFill([
                'review_status' => InternDocument::STATUS_PENDING_ADMIN,
                'peso_reviewed_by' => $actor->id,
                'peso_reviewed_at' => now(),
                'peso_remarks' => $remarks,
                // Keep the legacy columns meaningful for existing views.
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ])->save();

            $this->recordEvent($locked, $actor, $before, InternDocument::STATUS_PENDING_ADMIN, 'peso_approved', $remarks);

            return [$locked->fresh(), $before];
        });

        $this->notifier->notifyAdmins(new InternDocumentPesoApproved($fresh));

        return $fresh;
    }

    public function pesoReturn(InternDocument $document, User $actor, string $remarks): InternDocument
    {
        [$fresh] = DB::transaction(function () use ($document, $actor, $remarks) {
            $locked = $this->lock($document);
            $this->assertStatus($locked, [
                InternDocument::STATUS_PENDING_PESO,
                InternDocument::STATUS_RETURNED_TO_PESO,
            ]);

            $before = $locked->review_status;
            $locked->forceFill([
                'review_status' => InternDocument::STATUS_RETURNED_TO_INTERN,
                'peso_reviewed_by' => $actor->id,
                'peso_reviewed_at' => now(),
                'peso_remarks' => $remarks,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ])->save();

            $this->recordEvent($locked, $actor, $before, InternDocument::STATUS_RETURNED_TO_INTERN, 'peso_returned', $remarks);

            return [$locked->fresh()];
        });

        $this->notifier->notifyIntern($fresh->intern, new InternDocumentReturnedToIntern($fresh));

        return $fresh;
    }

    public function adminApprove(InternDocument $document, User $actor, ?string $remarks = null): InternDocument
    {
        [$fresh, $before] = DB::transaction(function () use ($document, $actor, $remarks) {
            $locked = $this->lock($document);
            $this->assertStatus($locked, [InternDocument::STATUS_PENDING_ADMIN]);

            if ($locked->documentType?->code === 'DTR') {
                $this->assertHoursComplete($locked);
            }

            $before = $locked->review_status;
            $locked->forceFill([
                'review_status' => InternDocument::STATUS_APPROVED,
                'admin_reviewed_by' => $actor->id,
                'admin_reviewed_at' => now(),
                'admin_remarks' => $remarks,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ])->save();

            $this->recordEvent($locked, $actor, $before, InternDocument::STATUS_APPROVED, 'admin_approved', $remarks);
            $this->hours->recalculate($locked->intern);

            return [$locked->fresh(), $before];
        });

        $notification = new InternDocumentApproved($fresh);
        $this->notifier->notifyIntern($fresh->intern, $notification);
        $this->notifier->notifyPesoManagers($notification);

        return $fresh;
    }

    public function adminReturn(InternDocument $document, User $actor, string $remarks): InternDocument
    {
        [$fresh] = DB::transaction(function () use ($document, $actor, $remarks) {
            $locked = $this->lock($document);
            $this->assertStatus($locked, [InternDocument::STATUS_PENDING_ADMIN]);

            $before = $locked->review_status;
            $locked->forceFill([
                'review_status' => InternDocument::STATUS_RETURNED_TO_PESO,
                'admin_reviewed_by' => $actor->id,
                'admin_reviewed_at' => now(),
                'admin_remarks' => $remarks,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ])->save();

            $this->recordEvent($locked, $actor, $before, InternDocument::STATUS_RETURNED_TO_PESO, 'admin_returned', $remarks);

            return [$locked->fresh()];
        });

        $this->notifier->notifyPesoManagers(new InternDocumentReturnedToPeso($fresh));

        return $fresh;
    }

    /**
     * Save draft per-day hours while the document waits for Admin approval.
     *
     * @param  array<string, mixed>  $dayHours  date => hours
     */
    public function saveHours(InternDocument $document, User $actor, array $dayHours): InternDocument
    {
        return DB::transaction(function () use ($document, $actor, $dayHours) {
            $locked = $this->lock($document);
            $this->assertStatus($locked, [InternDocument::STATUS_PENDING_ADMIN]);

            if ($locked->documentType?->code !== 'DTR') {
                throw ValidationException::withMessages([
                    'document' => 'Working hours can only be assigned to Daily Time Records.',
                ]);
            }

            $days = $locked->dtrDays()->get()->keyBy(fn (DtrDay $day) => $day->work_date->format('Y-m-d'));

            foreach ($dayHours as $date => $hours) {
                $day = $days->get($date);
                if (! $day) {
                    throw ValidationException::withMessages([
                        'day_hours' => "The selected date {$date} is not part of this DTR.",
                    ]);
                }

                $day->forceFill(['hours' => round((float) $hours, 1)])->save();
            }

            $locked->forceFill([
                'hours_assigned_by' => $actor->id,
                'hours_assigned_at' => now(),
            ])->save();

            $this->recordEvent(
                $locked,
                $actor,
                $locked->review_status,
                $locked->review_status,
                'hours_saved',
                null,
                ['hours' => $dayHours]
            );

            return $locked->fresh(['dtrDays']);
        });
    }

    /**
     * Intern resubmission after the PESO manager sends a document back.
     *
     * @param  array<string, mixed>  $data
     */
    public function resubmit(InternDocument $document, User $actor, array $data, ?UploadedFile $file = null): InternDocument
    {
        [$fresh] = DB::transaction(function () use ($document, $actor, $data, $file) {
            $locked = $this->lock($document);
            $this->assertStatus($locked, [InternDocument::STATUS_RETURNED_TO_INTERN]);

            if ((int) $locked->intern?->user_id !== (int) $actor->id) {
                throw ValidationException::withMessages(['document' => 'You can only resubmit your own documents.']);
            }

            $oldDays = $locked->dtrDays()->get()->map(fn (DtrDay $day) => [
                'date' => $day->work_date->format('Y-m-d'),
                'hours' => $day->hours !== null ? (float) $day->hours : null,
            ])->values()->all();

            $oldFile = $locked->file_path;

            if ($file) {
                $this->uploads->replaceFile($locked, $file);
            }

            if ($locked->documentType?->code === 'DTR' && isset($data['days'])) {
                $this->syncDays($locked, array_values(array_unique(array_filter((array) $data['days']))), $oldDays);
            }

            $before = $locked->review_status;
            $locked->forceFill([
                'review_status' => InternDocument::STATUS_PENDING_PESO,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_remarks' => null,
                'peso_reviewed_by' => null,
                'peso_reviewed_at' => null,
                'peso_remarks' => null,
                'admin_reviewed_by' => null,
                'admin_reviewed_at' => null,
                'admin_remarks' => null,
                'hours_assigned_by' => null,
                'hours_assigned_at' => null,
            ])->save();

            $this->recordEvent(
                $locked,
                $actor,
                $before,
                InternDocument::STATUS_PENDING_PESO,
                'resubmitted',
                $data['remarks'] ?? 'Resubmitted after corrections.',
                [
                    'previous_file' => $oldFile,
                    'previous_days' => $oldDays,
                    'days' => $data['days'] ?? null,
                ]
            );

            return [$locked->fresh(['dtrDays'])];
        });

        $this->notifier->notifyPesoManagers(new InternDocumentResubmitted($fresh));

        return $fresh;
    }

    /** @return array<int, array<string, mixed>> */
    public function history(InternDocument $document): array
    {
        return $document->reviewEvents()
            ->with('actor:id,first_name,middle_name,last_name,role')
            ->get()
            ->map(fn (InternDocumentReviewEvent $event) => [
                'id' => $event->id,
                'action' => $event->action,
                'actor' => $event->actor?->name,
                'actor_role' => $event->actor_role,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'remarks' => $event->remarks,
                'metadata' => $event->metadata,
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->all();
    }

    private function lock(InternDocument $document): InternDocument
    {
        return InternDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
    }

    /** @param  string[]  $allowed */
    private function assertStatus(InternDocument $document, array $allowed): void
    {
        if (! in_array($document->review_status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'This document is no longer in a state that allows that action. Refresh and try again.',
            ]);
        }
    }

    private function assertHoursComplete(InternDocument $document): void
    {
        $incomplete = $document->dtrDays()
            ->where(function ($query) {
                $query->whereNull('hours')->orWhere('hours', '<=', 0);
            })
            ->count();

        if ($document->dtrDays()->count() === 0 || $incomplete > 0) {
            throw ValidationException::withMessages([
                'hours' => 'Assign working hours to every selected day before approving this DTR.',
            ]);
        }
    }

    /** @param  array<int, array{date: string, hours: float|null}>  $oldDays */
    private function syncDays(InternDocument $document, array $days, array $oldDays): void
    {
        $oldHours = collect($oldDays)->keyBy('date')->map(fn ($row) => $row['hours']);
        $existing = $document->dtrDays()->get()->keyBy(fn (DtrDay $day) => $day->work_date->format('Y-m-d'));

        $keep = [];
        foreach ($days as $day) {
            $keep[$day] = $existing->get($day);
        }

        // Remove days no longer selected.
        foreach ($existing as $date => $row) {
            if (! isset($keep[$date])) {
                $row->delete();
            }
        }

        foreach ($keep as $date => $row) {
            if (! $row) {
                DtrDay::create([
                    'document_id' => $document->id,
                    'intern_id' => $document->intern_id,
                    'work_date' => $date,
                    'hours' => null,
                ]);
            }
        }

        if (empty($document->document_period) && $days !== []) {
            $sorted = collect($days)->sort()->values();
            $first = Carbon::createFromFormat('Y-m-d', $sorted->first());
            $last = Carbon::createFromFormat('Y-m-d', $sorted->last());
            $document->forceFill([
                'document_period' => $first->isSameDay($last)
                    ? $first->format('M d, Y')
                    : $first->format('M d, Y').' - '.$last->format('M d, Y'),
            ])->save();
        }

        unset($oldHours);
    }

    private function recordEvent(
        InternDocument $document,
        User $actor,
        ?string $from,
        ?string $to,
        string $action,
        ?string $remarks = null,
        array $metadata = []
    ): void {
        InternDocumentReviewEvent::create([
            'document_id' => $document->id,
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'remarks' => $remarks,
            'metadata' => $metadata ?: null,
        ]);

        AuditService::log(
            $action === 'resubmitted' ? 'updated' : 'reviewed',
            "Intern document {$action}: {$document->system_filename}",
            $document->intern,
            ['document_id' => $document->id, 'from' => $from, 'to' => $to]
        );
    }
}
