<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'username',
        'email',
        'password',
        'must_change_password',
        'role',
        'is_active',
        'last_active_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'last_active_at' => 'datetime',
        ];
    }

    /**
     * Get the user's full name.
     */
    public function getNameAttribute(): string
    {
        $name = "{$this->first_name}";

        if ($this->middle_name) {
            $name .= " {$this->middle_name}";
        }

        $name .= " {$this->last_name}";

        if ($this->suffix) {
            $name .= " {$this->suffix}";
        }

        return $name;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isPesoManager(): bool
    {
        return $this->role === 'peso_manager';
    }

    public function isIntern(): bool
    {
        return $this->role === 'intern';
    }

    /**
     * Check if user has any of the given roles.
     */
    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    public function intern()
    {
        return $this->hasOne(Intern::class);
    }
}
