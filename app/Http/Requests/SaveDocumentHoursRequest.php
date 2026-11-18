<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveDocumentHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document !== null && ($this->user()?->can('assignHours', $document) ?? false);
    }

    public function rules(): array
    {
        return [
            'day_hours' => ['required', 'array', 'min:1'],
            'day_hours.*' => ['nullable', 'numeric', 'min:0.5', 'max:24'],
        ];
    }

    public function messages(): array
    {
        return [
            'day_hours.required' => 'Enter the hours rendered for at least one selected day.',
            'day_hours.*.min' => 'Daily hours must be at least 0.5.',
            'day_hours.*.max' => 'A single day cannot exceed 24 hours.',
        ];
    }
}
