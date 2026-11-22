<?php

namespace App\Http\Requests;

use App\Models\Intern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInternRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('required_hours')) {
            $this->merge([
                'required_hours' => (int) config('internship.default_required_hours', 486),
            ]);
        }

        if (! $this->filled('hours_per_workday')) {
            $this->merge([
                'hours_per_workday' => (float) config('internship.hours_per_workday', 8),
            ]);
        }

        if (! $this->filled('status')) {
            $this->merge(['status' => 'active']);
        }
    }

    public function rules(): array
    {
        return [
            // Linked account
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'max:50', 'unique:users,username'],

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
            'email.required' => 'An email address is required so the intern can receive their login credentials.',
            'contract_end_date.after_or_equal' => 'The contract end date must be on or after the start date.',
            'required_hours.required' => 'Required hours is required.',
        ];
    }
}
