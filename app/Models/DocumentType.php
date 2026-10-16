<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    protected $fillable = [
        'name',
        'code',
        'has_expiry',
        'max_pages',
        'is_intern_document',
        'is_optional',
    ];

    protected function casts(): array
    {
        return [
            'has_expiry' => 'boolean',
            'max_pages' => 'integer',
            'is_intern_document' => 'boolean',
            'is_optional' => 'boolean',
        ];
    }

    public function internDocuments(): HasMany
    {
        return $this->hasMany(InternDocument::class);
    }
}
