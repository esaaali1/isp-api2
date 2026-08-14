<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'بيانات الدخول غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $agent = Agent::where('username', $request->string('username'))->first();

        // TODO: switch back to Hash::check() once migration to isp-api is
        // complete and passwords are re-hashed. The `agents` table is still
        // shared with the legacy isp-panel, which stores passwords in plain
        // text, so a bcrypt comparison would reject every valid login until
        // that system is retired and every row has been re-hashed.
        if (! $agent || ! hash_equals($agent->password, $request->string('password')->value())) {
            throw ValidationException::withMessages([
                'username' => ['اسم المستخدم أو كلمة المرور غير صحيحة.'],
            ]);
        }

        if (! $agent->isSubscriptionActive()) {
            return response()->json([
                'message' => 'اشتراك الوكيل منتهي أو غير مفعّل بعد.',
            ], 403);
        }

        $token = $agent->createToken(
            $request->userAgent() ? substr($request->userAgent(), 0, 100) : 'api-token'
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'username' => $agent->username,
                'end_date' => $agent->end_date?->toDateString(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->user();

        return response()->json([
            'id' => $agent->id,
            'name' => $agent->name,
            'username' => $agent->username,
            'start_date' => $agent->start_date?->toDateString(),
            'end_date' => $agent->end_date?->toDateString(),
        ]);
    }
}
