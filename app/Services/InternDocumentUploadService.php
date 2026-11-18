<?php

namespace App\Services;

use App\Models\DocumentType;
use App\Models\DtrDay;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\InternDocumentReviewEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Handles synchronous uploads of intern documents.
 *
 * Interns (and admins uploading on their behalf) select the working dates;
 * per-day hours are assigned later by an Admin during the final approval step.
 * DTR scans are only evidence for reviewers — the dtr_days rows are the record.
 */
class InternDocumentUploadService
{
    public function upload(Intern $intern, array $validatedData, User $user): InternDocument
    {
        /** @var UploadedFile $file */
        $file = $validatedData['file'];
        $documentType = DocumentType::findOrFail((int) $validatedData['document_type_id']);

        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $directory = "intern-docs/{$intern->id}";
        $baseFilename = $this->buildBaseFilename($intern->id, $documentType->code, $extension);
        $resolvedFilename = $this->resolveUniqueFilename($directory, $baseFilename, $extension);
        $relativePath = "{$directory}/{$resolvedFilename}";

        try {
            return DB::transaction(function () use (
                $file,
                $intern,
                $documentType,
                $user,
                $relativePath,
                $resolvedFilename,
                $validatedData
            ) {
                $stored = Storage::disk('local')->put($relativePath, file_get_contents($file->getRealPath()));
                if (! $stored) {
                    throw new RuntimeException('Unable to store uploaded file.');
                }

                $document = InternDocument::withoutGlobalScope('intern')->create([
                    'intern_id' => $intern->id,
                    'document_type_id' => $documentType->id,
                    'uploaded_by' => $user->id,
                    'file_path' => $relativePath,
                    'original_filename' => $file->getClientOriginalName(),
                    'system_filename' => $resolvedFilename,
                    'page_count' => 1,
                    'file_size_bytes' => (int) $file->getSize(),
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'status' => 'active',
                    'document_period' => $validatedData['document_period'] ?? null,
                    'review_status' => InternDocument::STATUS_PENDING_PESO,
                ]);

                if ($documentType->code === 'DTR') {
                    $this->storeDtrDays($document, $intern, $validatedData);
                } elseif (! empty($validatedData['hours'])) {
                    // Non-DTR documents uploaded by an admin may still carry an hours value.
                    $intern->increment('rendered_hours', (int) $validatedData['hours']);
                }

                InternDocumentReviewEvent::create([
                    'document_id' => $document->id,
                    'actor_id' => $user->id,
                    'actor_role' => $user->role,
                    'action' => 'uploaded',
                    'from_status' => null,
                    'to_status' => InternDocument::STATUS_PENDING_PESO,
                    'remarks' => null,
                    'metadata' => [
                        'document_type' => $documentType->code,
                        'days' => $documentType->code === 'DTR'
                            ? array_values(array_unique(array_filter((array) ($validatedData['days'] ?? []))))
                            : null,
                    ],
                ]);

                AuditService::log(
                    'uploaded',
                    "Intern document uploaded: {$document->system_filename}",
                    $intern,
                    ['document_id' => $document->id, 'document_type' => $documentType->code]
                );

                return $document;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($relativePath);

            throw $e;
        }
    }

    /**
     * Replace the evidence file on an existing document (resubmission).
     * The previous file is preserved on disk and linked in the review history.
     */
    public function replaceFile(InternDocument $document, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $directory = "intern-docs/{$document->intern_id}";
        $baseFilename = $this->buildBaseFilename($document->intern_id, $document->documentType?->code ?? 'DOC', $extension);
        $resolvedFilename = $this->resolveUniqueFilename($directory, $baseFilename, $extension);
        $relativePath = "{$directory}/{$resolvedFilename}";

        $stored = Storage::disk('local')->put($relativePath, file_get_contents($file->getRealPath()));
        if (! $stored) {
            throw new RuntimeException('Unable to store the replacement file.');
        }

        $document->forceFill([
            'file_path' => $relativePath,
            'original_filename' => $file->getClientOriginalName(),
            'system_filename' => $resolvedFilename,
            'file_size_bytes' => (int) $file->getSize(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
        ])->save();

        return $relativePath;
    }

    /**
     * Persist the working days claimed on a DTR. Hours stay null until an
     * Admin assigns them during final review.
     */
    private function storeDtrDays(InternDocument $document, Intern $intern, array $validatedData): void
    {
        $days = array_values(array_unique(array_filter((array) ($validatedData['days'] ?? []))));
        sort($days);

        $rows = array_map(fn (string $day) => [
            'document_id' => $document->id,
            'intern_id' => $intern->id,
            'work_date' => $day,
            'hours' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $days);

        if ($rows !== []) {
            DtrDay::insert($rows);
        }

        $metadata = array_merge((array) $document->metadata, [
            'dtr_days_count' => count($rows),
            'dtr_dates' => $days,
        ]);

        if (empty($validatedData['document_period']) && $rows !== []) {
            $first = Carbon::createFromFormat('Y-m-d', $days[0]);
            $last = Carbon::createFromFormat('Y-m-d', $days[count($days) - 1]);
            $metadata['document_period'] = $first->isSameDay($last)
                ? $first->format('M d, Y')
                : $first->format('M d, Y').' - '.$last->format('M d, Y');
            $document->forceFill([
                'document_period' => $metadata['document_period'],
                'metadata' => $metadata,
            ])->save();

            return;
        }

        $document->forceFill(['metadata' => $metadata])->save();
    }

    private function buildBaseFilename(int $internId, string $docTypeCode, string $extension): string
    {
        $timestamp = now()->format('YmdHis');
        $prefix = sprintf('INT-%d_%s_%s', $internId, strtoupper($docTypeCode), $timestamp);

        return Str::of($prefix)->append('.')->append($extension)->toString();
    }

    private function resolveUniqueFilename(string $directory, string $baseName, string $extension): string
    {
        if (! Storage::disk('local')->exists("{$directory}/{$baseName}")) {
            return $baseName;
        }

        $nameWithoutExtension = pathinfo($baseName, PATHINFO_FILENAME);
        $counter = 1;
        while (true) {
            $candidate = sprintf('%s_%02d.%s', $nameWithoutExtension, $counter, $extension);
            if (! Storage::disk('local')->exists("{$directory}/{$candidate}")) {
                return $candidate;
            }
            $counter++;
        }
    }
}
