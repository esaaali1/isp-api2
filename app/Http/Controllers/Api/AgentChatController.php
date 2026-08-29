<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MikrotikConnectionException;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\RadAcct;
use App\Services\MikrotikService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * شات ذكاء اصطناعي لزوار/وكلاء الموقع التسويقي (cloudsaas1.com) — مسار
 * عام بلا مصادقة Sanctum (الجلسة تُعرَّف بـ session_id من الواجهة، لا
 * بحساب مسجَّل دخوله). أول خطوة بكل محادثة: التحقق من اسم مستخدم الوكيل
 * مقابل جدول agents فعلياً قبل السماح بأي سؤال (انظر handleUsernameStep).
 * بعدها يستخدم Groq API مع Tool Use لأداتين للقراءة فقط فقط، كلتاهما
 * مُقيَّدتان صراحة ببيانات الوكيل المتحقق منه بهذه الجلسة تحديداً — اسم
 * المستخدم يُحقَن من الجلسة عند التنفيذ ولا يُقرأ أبداً من معطيات
 * النموذج، فلا يمكن بأي شكل (ولا حتى لو حاول النموذج) الوصول لبيانات
 * وكيل أو عميل آخر.
 */
class AgentChatController extends Controller
{
    private const SYSTEM_PROMPT = 'أنت مساعد تقني لنظام CloudSaaS، جاوب بإيجاز ووضوح.';

    private const SESSION_TTL_MINUTES = 30;

    /** أقصى عدد رسائل نُبقيها بذاكرة الجلسة، لتفادي نمو غير محدود لحجم كل طلب. */
    private const MAX_HISTORY_MESSAGES = 20;

    /** أقصى عدد جولات "أداة ثم رد" ضمن رد واحد، تحسباً من حلقة استدعاء أدوات لا تنتهي. */
    private const MAX_TOOL_ROUNDS = 3;

    public function __construct(private readonly MikrotikService $mikrotik)
    {
    }

    public function reply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'session_id' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9-]+$/'],
        ]);

        $cacheKey = "agent_chat_session:{$validated['session_id']}";
        $session = Cache::get($cacheKey, ['username' => null, 'messages' => []]);

        if ($session['username'] === null) {
            return $this->handleUsernameStep($cacheKey, $session, trim($validated['message']));
        }

        return $this->handleChatStep($cacheKey, $session, $validated['message']);
    }

    /** يتحقق من اسم المستخدم مقابل جدول agents، ولا يبدأ أي محادثة فعلية إلا بعد نجاحه. */
    private function handleUsernameStep(string $cacheKey, array $session, string $attemptedUsername): JsonResponse
    {
        $agent = Agent::where('username', $attemptedUsername)->where('is_admin', false)->first();

        if (! $agent) {
            return response()->json([
                'reply' => 'اسم المستخدم غير صحيح، يرجى إدخال اسم المستخدم الخاص بلوحة تحكمك مرة أخرى.',
            ]);
        }

        $session['username'] = $agent->username;
        Cache::put($cacheKey, $session, now()->addMinutes(self::SESSION_TTL_MINUTES));

        return response()->json([
            'reply' => "مرحباً {$agent->name}! كيف أقدر أساعدك اليوم؟",
        ]);
    }

    private function handleChatStep(string $cacheKey, array $session, string $message): JsonResponse
    {
        $apiKey = config('services.groq.key');
        if (! $apiKey) {
            Log::error('GROQ_API_KEY غير مضبوط في .env — شات الموقع التسويقي معطَّل.');

            return response()->json(['message' => 'خدمة المحادثة غير متاحة حالياً.'], 503);
        }

        $messages = $session['messages'];
        $messages[] = ['role' => 'user', 'content' => $message];

        $reply = $this->completeWithTools($apiKey, $session['username'], $messages);

        if ($reply === null) {
            return response()->json(['message' => 'تعذر الحصول على رد الآن، حاول مرة أخرى.'], 502);
        }

        $session['messages'] = array_slice($messages, -self::MAX_HISTORY_MESSAGES);
        Cache::put($cacheKey, $session, now()->addMinutes(self::SESSION_TTL_MINUTES));

        return response()->json(['reply' => $reply]);
    }

    /**
     * يرسل المحادثة لـ Groq مع تعريف الأداتين. إن طلب النموذج استدعاء
     * أداة (أو أكثر)، يُنفَّذها محلياً — مقيَّدة بـ $username الحقيقي
     * دائماً بغض النظر عمّا يرسله النموذج — ثم يعيد نتيجتها للنموذج
     * ليصوغ رداً نهائياً. $messages مُمرَّرة بالمرجع لتحديثها بكل ما
     * يُضاف للمحادثة (رسائل الأداة تُحفظ بذاكرة الجلسة أيضاً).
     */
    private function completeWithTools(string $apiKey, string $username, array &$messages): ?string
    {
        $payloadMessages = array_merge(
            [['role' => 'system', 'content' => self::SYSTEM_PROMPT]],
            $messages,
        );

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $response = Http::withToken($apiKey)
                ->timeout(20)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => config('services.groq.model'),
                    'messages' => $payloadMessages,
                    'tools' => $this->toolDefinitions(),
                    'tool_choice' => 'auto',
                    'temperature' => 0.4,
                    'max_tokens' => 500,
                ]);

            if (! $response->successful()) {
                Log::warning('فشل طلب Groq API', ['status' => $response->status(), 'body' => $response->body()]);

                return null;
            }

            $choice = $response->json('choices.0.message');
            if (! $choice) {
                return null;
            }

            $toolCalls = $choice['tool_calls'] ?? null;
            if (! $toolCalls) {
                $content = $choice['content'] ?? null;
                if (! is_string($content) || $content === '') {
                    return null;
                }
                $messages[] = ['role' => 'assistant', 'content' => $content];

                return $content;
            }

            // نعيد بناء رسالة المساعد بالحقول الضرورية فقط بدل تخزين رد
            // Groq الخام كما هو — أي حقول إضافية قد يعيدها المزوّد
            // (reasoning، إلخ) لا حاجة لها هنا وقد تُربك القالب عند
            // إعادة إرسالها بجولة لاحقة.
            $assistantMessage = [
                'role' => 'assistant',
                'content' => $choice['content'] ?? null,
                'tool_calls' => array_map(fn (array $tc) => [
                    'id' => $tc['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $tc['function']['name'] ?? '',
                        'arguments' => $tc['function']['arguments'] ?? '{}',
                    ],
                ], $toolCalls),
            ];
            $payloadMessages[] = $assistantMessage;
            $messages[] = $assistantMessage;

            foreach ($toolCalls as $toolCall) {
                $result = $this->executeTool($toolCall, $username);

                // "name" مطلوب صراحة هنا لهذا النموذج (قالب harmony)، رغم
                // أن tool_call_id وحده يكفي حسب توصيف OpenAI الرسمي —
                // بدونه يرفض الطلب بخطأ "Tools should have a name!" عند
                // إعادة إرسال هذه الرسالة ضمن الجولة التالية.
                $toolMessage = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'name' => $toolCall['function']['name'] ?? '',
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
                $payloadMessages[] = $toolMessage;
                $messages[] = $toolMessage;
            }
        }

        return null;
    }

    /**
     * تعريف الأداتين بصيغة Groq/OpenAI. عمداً: لا "username" ضمن أي منهما
     * — لا نمنح النموذج فرصة تمرير اسم مستخدم مختلف أصلاً، الأداة تُنفَّذ
     * دائماً باسم مستخدم الوكيل المتحقق منه بالجلسة فقط.
     */
    private function toolDefinitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_mikrotik_status',
                    'description' => 'استخدم هذي الأداة عندما يسأل الوكيل عن مشاكل تقنية بجهازه (بطء، انقطاع، ضعف انترنت، أو أي استفسار عن حالة جهازه).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'check_client_status',
                    'description' => 'استخدم هذي الأداة عندما يسأل الوكيل عن حالة أحد عملائه (اتصال/انقطاع/مشكلة باشتراك عميل معين).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'client_code' => [
                                'type' => 'string',
                                'description' => 'اسم المستخدم (username) الخاص بالعميل الذي يسأل عنه الوكيل، كما ذكره في رسالته (مثال: A1).',
                            ],
                        ],
                        'required' => ['client_code'],
                    ],
                ],
            ],
        ];
    }

    /**
     * ينفّذ أداة واحدة. $username هو دائماً اسم مستخدم الوكيل المتحقق
     * منه بهذه الجلسة تحديداً — أساس عزل بيانات كل وكيل عن غيره.
     */
    private function executeTool(array $toolCall, string $username): array
    {
        $name = $toolCall['function']['name'] ?? '';

        return match ($name) {
            'get_mikrotik_status' => $this->getMikrotikStatus($username),
            'check_client_status' => $this->checkClientStatus($username, $toolCall),
            default => ['error' => 'أداة غير معروفة.'],
        };
    }

    private function getMikrotikStatus(string $username): array
    {
        $agent = Agent::where('username', $username)->where('is_admin', false)->first();
        if (! $agent) {
            return ['error' => 'تعذر التعرف على حساب الوكيل.'];
        }

        $stats = $this->mikrotik->routerStatistics($agent);

        if (! $stats['online']) {
            return [
                'connected' => false,
                'message' => 'تعذر الاتصال بجهاز المايكروتك الخاص بك الآن.',
            ];
        }

        return [
            'connected' => true,
            'model' => $stats['router']['model'] ?? null,
            'cpu_load_percent' => $stats['router']['cpu_load'] ?? null,
            'uptime' => $stats['router']['uptime'] ?? null,
        ];
    }

    /** $toolCall['function']['arguments'] هو نص JSON من النموذج — client_code فقط، لا username إطلاقاً. */
    private function checkClientStatus(string $username, array $toolCall): array
    {
        $agent = Agent::where('username', $username)->where('is_admin', false)->first();
        if (! $agent) {
            return ['error' => 'تعذر التعرف على حساب الوكيل.'];
        }

        $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [];
        $clientCode = trim((string) ($arguments['client_code'] ?? ''));

        if ($clientCode === '') {
            return ['error' => 'لم يُحدَّد اسم مستخدم العميل.'];
        }

        // مقيَّد بعلاقة هذا الوكيل تحديداً — عميل تابع لوكيل آخر لن يظهر هنا إطلاقاً.
        $client = $agent->clients()->where('username', $clientCode)->first();

        if (! $client) {
            return ['error' => 'هذا العميل غير مسجل ضمن حسابك.'];
        }

        try {
            $connectedNow = $this->mikrotik->connectionStatus($client)['connected'];
        } catch (MikrotikConnectionException) {
            $connectedNow = null;
        }

        $lastSession = RadAcct::where('username', $client->username)
            ->orderByDesc('acctstarttime')
            ->first();

        return [
            'client_name' => $client->fullname,
            'connected_now' => $connectedNow,
            'last_connected_at' => $lastSession?->acctstarttime?->toIso8601String(),
        ];
    }
}
