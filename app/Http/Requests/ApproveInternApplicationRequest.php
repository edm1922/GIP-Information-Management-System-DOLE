<?php

namespace App\Http\Requests;

use App\Models\Intern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveInternApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // enforced by the controller policy
    }

    public function rules(): array
    {
        return [
            // Placement
            'partner_agency_id' => ['nullable', 'integer', 'exists:companies,id'],
            'supervisor' => ['nullable', 'string', 'max:150'],

            // Contract
            'contract_start_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date', 'after_or_equal:contract_start_date'],

            // Monitoring / classification
            'required_hours' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'hours_per_workday' => ['nullable', 'numeric', 'min:1', 'max:24'],
            'status' => ['nullable', Rule::in(Intern::STATUSES)],
            'intern_type' => ['nullable', 'string', Rule::in(Intern::INTERN_TYPES)],
            'batch' => ['nullable', 'string', 'max:50'],
            'beneficiary' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'contract_end_date.after_or_equal' => 'The contract end date must be on or after the start date.',
        ];
    }
}
