<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditService
{
    /**
     * Log an action to the audit trail.
     */
    public static function log(
        string $action,
        ?string $description = null,
        $model = null,
        ?array $changes = null
    ): AuditLog {
        $targetName = null;
        if ($model) {
            $targetName = match (get_class($model)) {
                'App\Models\Intern' => $model->user?->name ?? 'Intern #'.$model->id,
                'App\Models\User' => $model->username ?? $model->name,
                'App\Models\DocumentType' => $model->name,
                'App\Models\Company' => $model->name,
                'App\Models\InternApplication' => $model->name.' ('.$model->reference.')',
                default => 'ID: '.$model->id
            };
        }

        return AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'model_type' => $model ? get_class($model) : null,
            'model_id' => $model ? $model->id : null,
            'target_name' => $targetName,
            'description' => $description,
            'changes' => $changes,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }

    /**
     * Log user login.
     */
    public static function logLogin(): AuditLog
    {
        return self::log('login', 'User logged in', Auth::user());
    }

    /**
     * Log user logout.
     */
    public static function logLogout(): AuditLog
    {
        return self::log('logout', 'User logged out', Auth::user());
    }
}
