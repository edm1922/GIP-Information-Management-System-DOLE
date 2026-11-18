<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateInternRegistrationLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // enforced by the controller policy
    }

    public function rules(): array
    {
        return [
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }
}
