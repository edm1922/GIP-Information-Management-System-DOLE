<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectInternApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // enforced by the controller policy
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Please give a short reason so the applicant can be informed.',
            'reason.min' => 'Please provide a bit more detail (at least 10 characters).',
        ];
    }
}
