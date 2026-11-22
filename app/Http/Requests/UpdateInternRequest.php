<?php

namespace App\Http\Requests;

use App\Models\Intern;
use App\Support\WorkingDays;
use DateTimeInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInternRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('required_hours')) {
            $calculated = $this->calculatedRequiredHours();

            if ($calculated !== null) {
                $this->merge(['required_hours' => $calculated]);
            }
        }

        if (! $this->filled('status')) {
            $this->merge(['status' => 'active']);
        }
    }

    /**
     * Derive required hours from contract dates supplied on this request.
     *
     * Only used when the client omits required_hours. Both dates must be part
     * of the request: the update endpoint clears contract dates that are not
     * submitted, so deriving from the stored dates would immediately go stale.
     */
    private function calculatedRequiredHours(): ?int
    {
        $start = $this->contractDateValue('contract_start_date');
        $end = $this->contractDateValue('contract_end_date');

        if ($start === null || $end === null) {
            return null;
        }

        /** @var Intern|null $intern */
        $intern = $this->route('intern');

        $hoursPerWorkday = $this->filled('hours_per_workday')
            ? (float) $this->input('hours_per_workday')
            : (float) ($intern?->hours_per_workday ?: config('internship.hours_per_workday', 8));

        $hours = WorkingDays::hoursBetween($start, $end, $hoursPerWorkday);

        return $hours === null ? null : (int) $hours;
    }

    private function contractDateValue(string $key): string|DateTimeInterface|null
    {
        $value = $this->input($key);

        return is_string($value) || $value instanceof DateTimeInterface ? $value : null;
    }

    public function rules(): array
    {
        /** @var Intern|null $intern */
        $intern = $this->route('intern');
        $userId = $intern?->user_id;

        return [
            // Linked account
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'email' => [
                'nullable', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'username' => [
                'required', 'string', 'max:50',
                Rule::unique('users', 'username')->ignore($userId),
            ],

            // Placement
            'partner_agency_id' => ['nullable', 'integer', 'exists:companies,id'],

            // Contract
            'contract_start_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date', 'after_or_equal:contract_start_date'],

            // Monitoring
            'required_hours' => ['required', 'integer', 'min:1', 'max:10000'],
            'hours_per_workday' => ['nullable', 'numeric', 'min:1', 'max:24'],
            'rendered_hours' => ['nullable', 'integer', 'min:0'],

            // Classification
            'status' => ['required', Rule::in(Intern::STATUSES)],
            'intern_type' => ['nullable', 'string', 'max:50'],
            'batch' => ['nullable', 'string', 'max:50'],
            'beneficiary' => ['nullable', 'string', 'max:255'],

            // Profile
            'school' => ['nullable', 'string', 'max:150'],
            'course' => ['nullable', 'string', 'max:150'],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'supervisor' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.unique' => 'This username is already taken.',
            'email.unique' => 'An account with this email address already exists.',
            'contract_end_date.after_or_equal' => 'The contract end date must be on or after the start date.',
        ];
    }
}
