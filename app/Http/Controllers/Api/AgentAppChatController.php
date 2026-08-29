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
 * شات ذكاء اصطناعي داخل تطبيق الوكلاء (بعد تسجيل الدخول) — هذا المسار
 * محمي بـ auth:sanctum (نفس middleware كل مسارات الوكيل الأخرى، انظر
 * routes/api.php)، فالهوية تُستخرج من $request->user() مباشرة، أي من
 * التوكن الموثَّق نفسه — لا تُقرأ أبداً من محتوى أي رسالة، ولا حاجة لأي
 * خطوة تحقق يدوية (بخلاف شات الموقع التسويقي العام الذي يتطلب تحقق
 * username+password صريح لعدم وجود جلسة دخول أصلاً هناك). هذا يزيل
 * احتمال انتحال الهوية من جذوره: طالما التوكن صالح، فهو حتماً لصاحبه.
 *
 * الأدوات كلها للقراءة فقط، ومُقيَّدة دائماً بالوكيل المستخرَج من
 * التوكن — لا اسم مستخدم يُقرأ من النموذج أو من الطلب إطلاقاً.
 */
class AgentAppChatController extends Controller
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
        ]);

        /** @var Agent $agent */
        $agent = $request->user();

        $apiKey = config('services.groq_agent_app.key');
        if (! $apiKey) {
            Log::error('GROQ_API_KEY_AGENT_APP غير مضبوط في .env — شات تطبيق الوكلاء معطَّل.');

            return response()->json(['message' => 'خدمة المحادثة غير متاحة حالياً.'], 503);
        }

        $cacheKey = "agent_app_chat:{$agent->id}";
        $messages = Cache::get($cacheKey, []);
        $messages[] = ['role' => 'user', 'content' => $validated['message']];

        $reply = $this->completeWithTools($apiKey, $agent, $messages);

        if ($reply === null) {
            return response()->json(['message' => 'تعذر الحصول على رد الآن، حاول مرة أخرى.'], 502);
        }

        Cache::put(
            $cacheKey,
            array_slice($messages, -self::MAX_HISTORY_MESSAGES),
            now()->addMinutes(self::SESSION_TTL_MINUTES),
        );

        return response()->json(['reply' => $reply]);
    }

    /**
     * يرسل المحادثة لـ Groq مع تعريف الأدوات. إن طلب النموذج استدعاء
     * أداة (أو أكثر)، يُنفَّذها محلياً — مقيَّدة بـ $agent الحقيقي
     * (المستخرَج من التوكن) دائماً — ثم يعيد نتيجتها للنموذج ليصوغ رداً
     * نهائياً.
     */
    private function completeWithTools(string $apiKey, Agent $agent, array &$messages): ?string
    {
        $payloadMessages = array_merge(
            [['role' => 'system', 'content' => self::SYSTEM_PROMPT]],
            $messages,
        );

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $response = Http::withToken($apiKey)
                ->timeout(20)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => config('services.groq_agent_app.model'),
                    'messages' => $payloadMessages,
                    'tools' => $this->toolDefinitions(),
                    'tool_choice' => 'auto',
                    'temperature' => 0.4,
                    'max_tokens' => 500,
                ]);

            if (! $response->successful()) {
                Log::warning('فشل طلب Groq API (تطبيق الوكلاء)', ['status' => $response->status(), 'body' => $response->body()]);

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
                $result = $this->executeTool($toolCall, $agent);

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

    /** $agent هو دائماً الوكيل المستخرَج من التوكن الموثَّق — أساس عزل بيانات كل وكيل عن غيره. */
    private function executeTool(array $toolCall, Agent $agent): array
    {
        $name = $toolCall['function']['name'] ?? '';

        return match ($name) {
            'get_mikrotik_status' => $this->getMikrotikStatus($agent),
            'check_client_status' => $this->checkClientStatus($agent, $toolCall),
            'get_agent_subscribers_count' => $this->getAgentSubscribersCount($agent),
            'list_agent_subscribers_ips' => $this->listAgentSubscribersIps($agent),
            default => ['error' => 'أداة غير معروفة.'],
        };
    }

    private function getMikrotikStatus(Agent $agent): array
    {
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

    private function checkClientStatus(Agent $agent, array $toolCall): array
    {
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

    private function getAgentSubscribersCount(Agent $agent): array
    {
        return ['subscribers_count' => $agent->clients()->count()];
    }

    /** عناوين IP الحالية من جلسات radacct المفتوحة فقط — مقيَّد بعملاء هذا الوكيل حصراً. */
    private function listAgentSubscribersIps(Agent $agent): array
    {
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
