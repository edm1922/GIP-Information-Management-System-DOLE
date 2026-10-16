<?php

namespace App\Services;

use App\Models\DocumentType;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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

                // DTR uploads advance the rendered-hours counter.
                if ($documentType->code === 'DTR' && ! empty($validatedData['hours'])) {
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
