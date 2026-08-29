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
 * عام بلا مصادقة Sanctum (الجلسة تُعرَّف بـ session_id من الواجهة).
 *
 * تسلسل الحالة إلزامي بثلاث مراحل، ولا يمكن تجاوزه: pending_username
 * (بانتظار اسم المستخدم) → بانتظار كلمة المرور المطابقة له (مُخزَّنة
 * كـ pending_username بالجلسة) → verified_username (بعد تحقق ناجح من
 * الاثنين معاً مقابل agents، بنفس منطق AuthController::login بالضبط).
 * verified_username بمجرد ضبطه لا يُقرأ أو يُكتَب أبداً من أي مكان آخر
 * غير خطوة التحقق نفسها — reply() تتحقق من هذا الشرط أولاً قبل أي شيء،
 * فرسائل المحادثة العادية لا تستطيع بنيوياً تعديل الهوية الموثَّقة مهما
 * كان محتواها.
 *
 * الأدوات كلها للقراءة فقط، وتُنفَّذ دائماً باسم مستخدم الجلسة الموثَّق
 * — لا يُقرأ أبداً من معطيات النموذج (arguments)، فلا يمكن لأي وكيل رؤية
 * بيانات وكيل آخر مهما حاول النموذج أو المستخدم.
 */
class AgentChatController extends Controller
{
    private const SYSTEM_PROMPT = 'أنت مساعد تقني لنظام CloudSaaS، جاوب بإيجاز ووضوح.';

    private const SESSION_TTL_MINUTES = 30;

    private const MAX_HISTORY_MESSAGES = 20;

    private const MAX_TOOL_ROUNDS = 3;

    private const MAX_IP_LIST_RESULTS = 100;

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
        $session = Cache::get($cacheKey, $this->freshSession());

        // بمجرد التحقق، أي رسالة لاحقة تذهب لمسار المحادثة فقط — لا مسار
        // آخر بهذا الكونترولر يكتب على verified_username إطلاقاً.
        if ($session['verified_username'] !== null) {
            return $this->handleChatStep($cacheKey, $session, $validated['message']);
        }

        if ($session['pending_username'] === null) {
            return $this->handleUsernameStep($cacheKey, $session, trim($validated['message']));
        }

        return $this->handlePasswordStep($cacheKey, $session, $validated['message']);
    }

    private function freshSession(): array
    {
        return ['pending_username' => null, 'verified_username' => null, 'messages' => []];
    }

    private function handleUsernameStep(string $cacheKey, array $session, string $usernameAttempt): JsonResponse
    {
        if ($usernameAttempt === '') {
            return response()->json([
                'reply' => 'يرجى إدخال اسم المستخدم الخاص بلوحة تحكمك.',
                'stage' => 'username',
            ]);
        }

        // لا نتحقق من وجود الاسم هنا عمداً — التحقق الملزم الوحيد يحدث
        // بخطوة كلمة المرور، معاً، لتفادي كشف أي اسم مستخدم صحيح بردٍّ
        // مختلف عن اسم خاطئ (username enumeration).
        $session['pending_username'] = $usernameAttempt;
        Cache::put($cacheKey, $session, now()->addMinutes(self::SESSION_TTL_MINUTES));

        return response()->json([
            'reply' => 'الرجاء إدخال كلمة المرور الخاصة بلوحة تحكمك للتحقق من هويتك.',
            'stage' => 'password',
        ]);
    }

    /** نفس منطق AuthController::login بالضبط: كلمة مرور مطابقة (نص صريح حالياً)، ليس حساب إدارة، واشتراك فعّال. */
    private function handlePasswordStep(string $cacheKey, array $session, string $password): JsonResponse
    {
        $username = $session['pending_username'];
        $agent = Agent::where('username', $username)->first();

        $valid = $agent
            && ! $agent->is_admin
            && hash_equals($agent->password, $password)
            && $agent->isSubscriptionActive();

        if (! $valid) {
            $session['pending_username'] = null;
            Cache::put($cacheKey, $session, now()->addMinutes(self::SESSION_TTL_MINUTES));

            return response()->json([
                'reply' => 'اسم المستخدم أو كلمة المرور غير صحيحة. يرجى إدخال اسم المستخدم مرة أخرى.',
                'stage' => 'username',
            ]);
        }

        $session['pending_username'] = null;
        $session['verified_username'] = $agent->username;
        Cache::put($cacheKey, $session, now()->addMinutes(self::SESSION_TTL_MINUTES));

        return response()->json([
            'reply' => "مرحباً {$agent->name}! تم التحقق من هويتك بنجاح، كيف أقدر أساعدك اليوم؟",
            'stage' => 'chat',
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

        $reply = $this->completeWithTools($apiKey, $session['verified_username'], $messages);

        if ($reply === null) {
            return response()->json(['message' => 'تعذر الحصول على رد الآن، حاول مرة أخرى.'], 502);
        }

        $session['messages'] = array_slice($messages, -self::MAX_HISTORY_MESSAGES);
        Cache::put($cacheKey, $session, now()->addMinutes(self::SESSION_TTL_MINUTES));

        return response()->json(['reply' => $reply, 'stage' => 'chat']);
    }

    /**
     * يرسل المحادثة لـ Groq مع تعريف الأدوات. إن طلب النموذج استدعاء
     * أداة (أو أكثر)، يُنفَّذها محلياً — مقيَّدة بـ $username الحقيقي
     * دائماً بغض النظر عمّا يرسله النموذج — ثم يعيد نتيجتها للنموذج
     * ليصوغ رداً نهائياً.
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
     * تعريف الأدوات بصيغة Groq/OpenAI. عمداً: لا "username" ضمن أي منها
     * — لا نمنح النموذج فرصة تمرير اسم مستخدم مختلف أصلاً، كل أداة
     * تُنفَّذ دائماً باسم مستخدم الوكيل الموثَّق بالجلسة فقط.
     */
    private function toolDefinitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_mikrotik_status',
                    'description' => 'استخدم هذي الأداة عندما يسأل الوكيل عن مشاكل تقنية بجهازه (بطء، انقطاع، ضعف انترنت، أو أي استفسار عن حالة جهازه).',
                    'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
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
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_agent_subscribers_count',
                    'description' => 'استخدم هذي الأداة عندما يسأل الوكيل عن العدد الكلي لمشتركيه.',
                    'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_agent_subscribers_ips',
                    'description' => 'استخدم هذي الأداة عندما يسأل الوكيل عن عناوين IP الحالية لمشتركيه المتصلين الآن.',
                    'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                ],
            ],
        ];
    }

    /** $username دائماً هو اسم مستخدم الوكيل الموثَّق بهذه الجلسة تحديداً — أساس عزل بيانات كل وكيل عن غيره. */
    private function executeTool(array $toolCall, string $username): array
    {
        $name = $toolCall['function']['name'] ?? '';

        return match ($name) {
            'get_mikrotik_status' => $this->getMikrotikStatus($username),
            'check_client_status' => $this->checkClientStatus($username, $toolCall),
            'get_agent_subscribers_count' => $this->getAgentSubscribersCount($username),
            'list_agent_subscribers_ips' => $this->listAgentSubscribersIps($username),
            default => ['error' => 'أداة غير معروفة.'],
        };
    }

    /** يعيد الوكيل الموثَّق بالجلسة من قاعدة البيانات فعلياً في كل استدعاء — لا يُمرَّر ككائن محفوظ مسبقاً. */
    private function resolveAgent(string $username): ?Agent
    {
        return Agent::where('username', $username)->where('is_admin', false)->first();
    }

    private function getMikrotikStatus(string $username): array
    {
        $agent = $this->resolveAgent($username);
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

    private function checkClientStatus(string $username, array $toolCall): array
    {
        $agent = $this->resolveAgent($username);
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

    private function getAgentSubscribersCount(string $username): array
    {
        $agent = $this->resolveAgent($username);
        if (! $agent) {
            return ['error' => 'تعذر التعرف على حساب الوكيل.'];
        }

        return ['subscribers_count' => $agent->clients()->count()];
    }

    /** عناوين IP الحالية من جلسات radacct المفتوحة فقط (لا يوجد IP ثابت مخزَّن بجدول clients) — مقيَّد بعملاء هذا الوكيل حصراً. */
    private function listAgentSubscribersIps(string $username): array
    {
        $agent = $this->resolveAgent($username);
        if (! $agent) {
            return ['error' => 'تعذر التعرف على حساب الوكيل.'];
        }

        $clientUsernames = $agent->clients()->pluck('username');

        if ($clientUsernames->isEmpty()) {
            return ['subscribers' => [], 'message' => 'لا يوجد مشتركون بحسابك بعد.'];
        }

        $sessions = RadAcct::whereIn('username', $clientUsernames)
            ->whereNull('acctstoptime')
            ->orderByDesc('acctstarttime')
            ->limit(self::MAX_IP_LIST_RESULTS)
            ->get(['username', 'framedipaddress']);

        if ($sessions->isEmpty()) {
            return ['subscribers' => [], 'message' => 'لا يوجد مشتركون متصلون الآن.'];
        }

        return [
            'subscribers' => $sessions->map(fn ($session) => [
                'username' => $session->username,
                'ip' => $session->framedipaddress,
            ])->values()->all(),
        ];
    }
}
