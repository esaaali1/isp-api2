<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * شات ذكاء اصطناعي بسيط لزوار الموقع التسويقي (cloudsaas1.com) — مسار
 * عام بلا مصادقة، غير مرتبط بحسابات الوكلاء. يمرّر رسالة الزائر إلى Groq
 * API (نموذج مفتوح سريع الاستدلال، مجاني الحصة) عبر Http facade في
 * Laravel (مبنية على Guzzle أصلاً). محمي بحد معدل طلبات (throttle) في
 * routes/api.php لتفادي استنزاف حصة API من إساءة استخدام عامة.
 */
class AgentChatController extends Controller
{
    private const SYSTEM_PROMPT = 'أنت مساعد تقني لنظام CloudSaaS، جاوب بإيجاز ووضوح.';

    public function reply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $apiKey = config('services.groq.key');
        if (! $apiKey) {
            Log::error('GROQ_API_KEY غير مضبوط في .env — شات الموقع التسويقي معطَّل.');

            return response()->json(['message' => 'خدمة المحادثة غير متاحة حالياً.'], 503);
        }

        $response = Http::withToken($apiKey)
            ->timeout(20)
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => config('services.groq.model'),
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => $validated['message']],
                ],
                'temperature' => 0.5,
                'max_tokens' => 500,
            ]);

        if (! $response->successful()) {
            Log::warning('فشل طلب Groq API', ['status' => $response->status(), 'body' => $response->body()]);

            return response()->json(['message' => 'تعذر الحصول على رد الآن، حاول مرة أخرى.'], 502);
        }

        $reply = $response->json('choices.0.message.content');

        if (! is_string($reply) || $reply === '') {
            return response()->json(['message' => 'تعذر الحصول على رد الآن، حاول مرة أخرى.'], 502);
        }

        return response()->json(['reply' => $reply]);
    }
}
