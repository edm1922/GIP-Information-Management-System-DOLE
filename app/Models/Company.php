<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'name',
        'code',
        'is_active',
        'is_partner_agency',
    ];

    protected $attributes = [
        'is_partner_agency' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_partner_agency' => 'boolean',
        ];
    }

    /**
     * Get interns assigned to this company as their partner agency.
     */
    public function interns(): HasMany
    {
        return $this->hasMany(Intern::class, 'partner_agency_id');
    }
}
