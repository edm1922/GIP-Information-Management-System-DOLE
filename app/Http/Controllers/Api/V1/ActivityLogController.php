<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AuditLogResource;
use App\Http\Resources\V1\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::with(['user', 'model'])->orderBy('created_at', 'desc');

        if ($request->has('action') && $request->action !== 'all') {
            $query->where('action', $request->action);
        }

        if ($request->has('user_id') && $request->user_id !== 'all') {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->has('model_id')) {
            $query->where('model_id', $request->model_id);
        }

        if ($request->has('model_type')) {
            $query->where('model_type', $request->model_type);
        }

        $logs = $query->paginate(20)->withQueryString();
        $users = User::orderBy('last_name')->get();

        return response()->json([
            'logs' => AuditLogResource::collection($logs)->response()->getData(true),
            'users' => UserResource::collection($users),
        ]);
    }
}
