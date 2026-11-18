<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternApplication extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'pending_admin', 'approved', 'rejected'];

    public const EMAIL_STATUSES = ['pending', 'sent', 'failed'];

    protected $fillable = [
        'reference',
        'registration_link_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'email',
        'contact_number',
        'school',
        'course',
        'partner_agency_id',
        'intern_type',
        'preferred_start_date',
        'notes',
        'status',
        'reviewer_id',
        'reviewed_at',
        'peso_reviewer_id',
        'peso_reviewed_at',
        'admin_reviewer_id',
        'admin_reviewed_at',
        'rejection_reason',
        'approved_user_id',
        'approved_intern_id',
        'credential_email_status',
        'credential_email_sent_at',
        'credential_email_error',
        'credential_email_attempts',
    ];

    protected function casts(): array
    {
        return [
            'preferred_start_date' => 'date',
            'reviewed_at' => 'datetime',
            'peso_reviewed_at' => 'datetime',
            'admin_reviewed_at' => 'datetime',
            'credential_email_sent_at' => 'datetime',
            'credential_email_attempts' => 'integer',
        ];
    }

    public function registrationLink(): BelongsTo
    {
        return $this->belongsTo(InternRegistrationLink::class, 'registration_link_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function pesoReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'peso_reviewer_id');
    }

    public function adminReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_reviewer_id');
    }

    public function approvedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_user_id');
    }

    public function approvedIntern(): BelongsTo
    {
        return $this->belongsTo(Intern::class, 'approved_intern_id');
    }

    public function partnerAgency(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'partner_agency_id');
    }

    public function getNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])));
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', 'rejected');
    }
}
