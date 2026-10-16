<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewInternDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document !== null
            && ($this->user()?->can('review', $document) ?? false);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'review_remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('decision') === 'rejected' && ! $this->filled('review_remarks')) {
                $validator->errors()->add('review_remarks', 'Please provide a reason when rejecting a document.');
            }
        });
    }
}
