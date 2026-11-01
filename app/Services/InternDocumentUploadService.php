<?php

namespace App\Services;

use App\Models\DocumentType;
use App\Models\DtrDay;
use App\Models\Intern;
use App\Models\InternDocument;
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
 * Intern documents are stored on the `local` disk under
 * `intern-docs/{intern_id}/`. They reuse the shared `documents` table via the
 * InternDocument model and are never indexed by Scout. When a Daily Time
 * Record (DTR) is uploaded with a rendered-hours value, the intern's
 * `rendered_hours` counter is incremented within the same transaction.
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
                    'review_status' => 'pending',
                ]);

                if ($documentType->code === 'DTR') {
                    $this->storeDtrDays($document, $intern, $validatedData);
                } elseif (! empty($validatedData['hours'])) {
                    // Non-DTR documents may still carry an hours value.
                    $intern->increment('rendered_hours', (int) $validatedData['hours']);
                }

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
     * Persist the working days claimed on a DTR and advance the intern's
     * rendered-hours counter by the claimed total.
     *
     * The uploaded scan is only evidence for the reviewer; these rows are the
     * authoritative record used by the intern work calendar.
     */
    private function storeDtrDays(InternDocument $document, Intern $intern, array $validatedData): void
    {
        $days = array_values(array_unique(array_filter((array) ($validatedData['days'] ?? []))));
        sort($days);
        $defaultHours = (float) ($validatedData['hours_per_day'] ?? 8);
        $dayHours = (array) ($validatedData['day_hours'] ?? []);

        $rows = array_map(function (string $day) use ($document, $intern, $dayHours, $defaultHours) {
            $hours = isset($dayHours[$day]) && is_numeric($dayHours[$day])
                ? (float) $dayHours[$day]
                : $defaultHours;

            return [
                'document_id' => $document->id,
                'intern_id' => $intern->id,
                'work_date' => $day,
                'hours' => round($hours, 1),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $days);

        if ($rows !== []) {
            DtrDay::insert($rows);
        }

        // Hours are credited only after the DTR is approved; recalculating here
        // keeps rendered_hours in step with the days just stored.
        app(InternHoursService::class)->recalculate($intern);

        $metadata = array_merge((array) $document->metadata, [
            'dtr_days_count' => count($rows),
            'default_hours_per_day' => $defaultHours,
            'dtr_total_hours' => round(array_sum(array_column($rows, 'hours')), 1),
        ]);

        if (empty($validatedData['document_period']) && $rows !== []) {
            $first = Carbon::createFromFormat('Y-m-d', $days[0]);
            $last = Carbon::createFromFormat('Y-m-d', $days[count($days) - 1]);
            $metadata['document_period'] = $first->isSameDay($last)
                ? $first->format('M d, Y')
                : $first->format('M d, Y').' – '.$last->format('M d, Y');
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
