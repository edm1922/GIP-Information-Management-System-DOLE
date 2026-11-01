<?php

namespace App\Http\Requests;

use App\Models\DocumentType;
use App\Models\DtrDay;
use App\Models\Intern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreInternDocumentRequest extends FormRequest
{
    /** Maximum number of days a single DTR may claim. */
    private const MAX_DAYS_PER_DTR = 31;

    public function authorize(): bool
    {
        // Fine-grained authorization (own intern vs. admin-on-behalf) is
        // enforced in the controller against the resolved Intern model.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'intern_id' => ['nullable', 'integer', 'exists:interns,id'],
            'document_type_id' => [
                'required', 'integer',
                Rule::exists('document_types', 'id')->where('is_intern_document', true),
            ],
            'document_period' => ['nullable', 'string', 'max:100'],
            'hours' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'hours_per_day' => ['nullable', 'numeric', 'min:0.5', 'max:24'],
            'days' => ['nullable', 'array', 'max:'.self::MAX_DAYS_PER_DTR],
            'days.*' => ['date_format:Y-m-d', 'distinct'],
            'day_hours' => ['nullable', 'array'],
            'day_hours.*' => ['numeric', 'min:0.1', 'max:24'],
            'file' => ['required', 'file', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'days.required' => 'Select the working days covered by this DTR.',
            'days.max' => 'A single DTR can cover at most '.self::MAX_DAYS_PER_DTR.' days.',
            'days.*.date_format' => 'Working days must use the YYYY-MM-DD format.',
            'hours_per_day.min' => 'Hours per day must be at least 0.5.',
            'hours_per_day.max' => 'Hours per day cannot exceed 24.',
            'day_hours.*.numeric' => 'Each day needs a numeric hour value.',
            'day_hours.*.min' => 'Daily hours must be at least 0.1.',
            'day_hours.*.max' => 'A single day cannot exceed 24 hours.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $typeId = $this->integer('document_type_id');
            if (! $typeId) {
                return;
            }

            $type = DocumentType::find($typeId);
            if (! $type) {
                return;
            }

            $this->validateFileType($validator);

            if ($type->code !== 'DTR') {
                return;
            }

            $this->validateDtrDays($validator);
        });
    }

    private function validateFileType($validator): void
    {
        $file = $this->file('file');
        if (! $file instanceof UploadedFile) {
            return;
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'csv'];

        if (! in_array($extension, $allowed, true)) {
            $validator->errors()->add('file', 'Unsupported file type. Allowed: PDF, JPG, JPEG, PNG, DOC, DOCX, XLS, XLSX, CSV.');
        }
    }

    private function validateDtrDays($validator): void
    {
        $days = array_values(array_filter((array) $this->input('days', [])));

        if ($days === []) {
            $validator->errors()->add('days', 'Select the working days covered by this DTR.');

            return;
        }

        $intern = $this->resolveIntern();
        if (! $intern) {
            return;
        }

        $today = now()->startOfDay();
        $conflicts = [];

        foreach ($days as $index => $day) {
            try {
                $date = Carbon::createFromFormat('Y-m-d', $day)->startOfDay();
            } catch (\Throwable) {
                continue; // format error already reported by the rules
            }

            if ($date->greaterThan($today)) {
                $validator->errors()->add("days.$index", 'Working days cannot be in the future.');
            }

            // Days outside the contract window are deliberately allowed: interns
            // routinely back-fill earlier DTRs and contract dates can be entered
            // late. Such days are flagged for the reviewer rather than blocked
            // (see InternDocument::outside_contract_days_count).
            $conflicts[] = $day;
        }

        // Per-day hours may only be supplied for days that are actually selected.
        foreach (array_keys((array) $this->input('day_hours', [])) as $key) {
            if (! in_array($key, $days, true)) {
                $validator->errors()->add('day_hours', 'Hours were supplied for a day that is not selected: '.$key);

                break;
            }
        }

        // A day may only be claimed once across all non-rejected DTRs, so hours
        // cannot be double-counted when a document is re-submitted.
        // whereDate() keeps the comparison driver-agnostic (SQLite stores the
        // date cast with a time component, MySQL DATE columns do not).
        $taken = DtrDay::query()
            ->counted()
            ->where('intern_id', $intern->id)
            ->where(function ($query) use ($conflicts): void {
                foreach ($conflicts as $day) {
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
                'These days are already covered by another DTR: '.$taken->take(8)->implode(', ').
                ($taken->count() > 8 ? '…' : '').' Rejected DTRs do not block re-submission.'
            );
        }
    }

    private function resolveIntern(): ?Intern
    {
        if ($this->user()?->isIntern()) {
            return $this->user()->intern;
        }

        return Intern::find($this->integer('intern_id'));
    }
}
