<?php

namespace App\Http\Requests;

use App\Models\Intern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StorePublicInternApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }

        // Honeypot: real applicants never see or fill this field.
        if ($this->filled('website')) {
            throw ValidationException::withMessages([
                'email' => 'The application could not be submitted. Please try again.',
            ]);
        }

        // Time trap: reject submissions completed unrealistically fast.
        $startedAt = (int) $this->input('form_started_at', 0);
        if ($startedAt > 0 && (int) floor(microtime(true) * 1000) - $startedAt < 3000) {
            throw ValidationException::withMessages([
                'email' => 'Please take a moment to complete the form before submitting.',
            ]);
        }
    }

    public function rules(): array
    {
        return [
            // Personal information
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-.\s]{7,30}$/'],

            // Profile
            'school' => ['nullable', 'string', 'max:150'],
            'course' => ['nullable', 'string', 'max:150'],
            'partner_agency_id' => ['nullable', 'integer', 'exists:companies,id'],
            'intern_type' => ['nullable', 'string', Rule::in(Intern::INTERN_TYPES)],
            'preferred_start_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],

            // Consent + antispam
            'consent' => ['accepted'],
            'website' => ['nullable', 'string', 'max:255'],
            'form_started_at' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'consent.accepted' => 'Please agree to the data-privacy terms to submit your application.',
            'contact_number.regex' => 'Enter a valid contact number (digits, spaces, +, - or parentheses).',
        ];
    }
}
