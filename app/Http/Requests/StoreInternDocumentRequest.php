<?php

namespace App\Http\Requests;

use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class StoreInternDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Fine-grained authorization (own intern vs. admin-on-behalf) is
        // enforced in the controller against the resolved Intern model.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'intern_id' => ['nullable', 'integer', 'exists:interns,id'],
            'document_type_id' => [
                'required', 'integer',
                Rule::exists('document_types', 'id')->where('is_intern_document', true),
            ],
            'document_period' => ['nullable', 'string', 'max:100'],
            'hours' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'file' => ['required', 'file', 'max:10240'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $typeId = $this->integer('document_type_id');
            if (! $typeId) {
                return;
            }

            $type = DocumentType::find($typeId);
            if (! $type) {
                return;
            }

            if ($type->code === 'DTR' && ! $this->filled('hours')) {
                $validator->errors()->add('hours', 'Number of rendered hours is required for a Daily Time Record.');
            }

            $file = $this->file('file');
            if ($file instanceof UploadedFile) {
                $extension = strtolower($file->getClientOriginalExtension() ?: '');
                $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'csv'];

                if (! in_array($extension, $allowed, true)) {
                    $validator->errors()->add('file', 'Unsupported file type. Allowed: PDF, JPG, JPEG, PNG, DOC, DOCX, XLS, XLSX, CSV.');
                }
            }
        });
    }
}
