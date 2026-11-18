<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnInternDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        if ($document === null) {
            return false;
        }

        $ability = $this->user()?->isAdmin() ? 'reviewAsAdmin' : 'reviewAsPeso';

        return $this->user()?->can($ability, $document) ?? false;
    }

    public function rules(): array
    {
        return [
            'remarks' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'remarks.required' => 'Explain what needs to be corrected before returning this submission.',
            'remarks.min' => 'Please provide a bit more detail (at least 10 characters).',
        ];
    }
}
