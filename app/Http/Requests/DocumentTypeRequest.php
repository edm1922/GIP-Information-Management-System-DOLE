<?php

namespace App\Http\Requests;

use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        // Both the web resource route and the API route declare the parameter
        // as `{document_type}`, so resolve that name first. The camelCase name
        // is kept for routes/actions that bind it explicitly.
        $documentType = $this->route('documentType') ?? $this->route('document_type');

        $documentTypeId = $documentType instanceof DocumentType
            ? $documentType->getKey()
            : (is_numeric($documentType) ? (int) $documentType : null);

        // Legacy web forms still post the record id; keep it as a last resort.
        if (! $documentTypeId && $this->filled('id')) {
            $documentTypeId = (int) $this->input('id');
        }

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('document_types', 'name')->ignore($documentTypeId),
            ],
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('document_types', 'code')->ignore($documentTypeId),
            ],
            'has_expiry' => ['boolean'],
            'is_required' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Document type name is required.',
            'name.unique' => 'A document type with this name already exists.',
            'name.max' => 'Name must not exceed 255 characters.',
            'code.required' => 'A short code is required.',
            'code.unique' => 'This code is already taken by another document type.',
            'code.max' => 'Code must not exceed 20 characters.',
            'code.alpha_dash' => 'Code may only contain letters, numbers, dashes, and underscores.',
        ];
    }

    /**
     * Prepare the data for validation — unchecked checkboxes aren't sent.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'has_expiry' => $this->boolean('has_expiry'),
            'is_required' => $this->boolean('is_required'),
        ]);
    }
}
