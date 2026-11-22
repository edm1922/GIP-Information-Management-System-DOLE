<?php

namespace App\Http\Requests;

use App\Models\DtrDay;
use App\Models\InternDocument;
use App\Support\DocumentFileType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

class ResubmitInternDocumentRequest extends FormRequest
{
    private const MAX_DAYS = 31;

    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document !== null && ($this->user()?->can('resubmit', $document) ?? false);
    }

    public function rules(): array
    {
        return [
            'document_period' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'days' => ['nullable', 'array', 'max:'.self::MAX_DAYS],
            'days.*' => ['date_format:Y-m-d', 'distinct'],
            'file' => ['nullable', 'file', 'max:10240'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            /** @var InternDocument|null $document */
            $document = $this->route('document');
            if (! $document) {
                return;
            }

            $file = $this->file('file');
            // Invalid uploads are already reported by the `file` rule; reading
            // their MIME type would throw because the temporary file is gone.
            if ($file instanceof UploadedFile && $file->isValid()) {
                // SEC-01a: same per-extension content check as the store path.
                if (! DocumentFileType::hasAllowedExtension($file)
                    || DocumentFileType::canonicalMime($file) === null) {
                    $validator->errors()->add('file', DocumentFileType::ERROR_MESSAGE);
                }
            }

            if ($document->documentType?->code !== 'DTR') {
                if (! $file) {
                    $validator->errors()->add('file', 'Attach the corrected document before resubmitting.');
                }

                return;
            }

            $days = array_values(array_filter((array) $this->input('days', [])));
            if ($days === []) {
                $validator->errors()->add('days', 'Select at least one working day.');

                return;
            }

            $today = now()->startOfDay();
            foreach ($days as $index => $day) {
                try {
                    $date = Carbon::createFromFormat('Y-m-d', $day)->startOfDay();
                } catch (\Throwable) {
                    continue;
                }

                if ($date->greaterThan($today)) {
                    $validator->errors()->add("days.{$index}", 'Working days cannot be in the future.');
                }
            }

            $taken = DtrDay::query()
                ->counted()
                ->where('intern_id', $document->intern_id)
                ->where('document_id', '!=', $document->id)
                ->where(function ($query) use ($days) {
                    foreach ($days as $day) {
                        $query->orWhereDate('work_date', $day);
                    }
                })
                ->pluck('work_date')
                ->map(fn ($date) => Carbon::parse($date)->format('Y-m-d'))
                ->unique()
                ->values();

            if ($taken->isNotEmpty()) {
                $validator->errors()->add(
                    'days',
                    'These days are already covered by another DTR: '.$taken->take(8)->implode(', ')
                );
            }
        });
    }
}
