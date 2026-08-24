<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\ClientLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogController extends Controller
{
    /** سجل النظام: كل تغييرات مشتركي هذا الوكيل (إضافة، تفعيل اشتراك، تغيير باقة/كلمة سر، إلخ) — الأحدث أولاً. */
    public function index(Request $request): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->user();

        $logs = ClientLog::with('client:id,fullname,username')
            ->whereIn('client_id', $agent->clients()->pluck('id'))
            ->latest('created_at')
            ->get(['id', 'client_id', 'action', 'old_value', 'new_value', 'created_at']);

        return response()->json($logs);
    }
}
