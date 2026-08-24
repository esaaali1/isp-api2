<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\PackagePrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** إعدادات الوكيل: اسمه ورصيده (للعرض فقط)، أسعار الباقات، تفعيل الدفع الإلكتروني، ونصوص إشعارات واتساب. */
class SettingsController extends Controller
{
    private const DEFAULT_PAY_MESSAGE = 'مرحباً {name}، تم تسديد {amount} د.ع من دينك. شكراً لك.';

    private const DEFAULT_ADD_DEBT_MESSAGE = 'مرحباً {name}، تمت إضافة {amount} د.ع إلى دينك.';

    private const DEFAULT_RENEW_MESSAGE = 'مرحباً {name}، تم تجديد اشتراكك 30 يوماً حتى {date}.';

    public function show(Request $request): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->user();

        return response()->json($this->payload($agent));
    }

    public function update(Request $request): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->user();

        $validated = $request->validate([
            'electronic_payment_enabled' => ['required', 'boolean'],
            'package_prices' => ['required', 'array'],
            'package_prices.*.package' => ['required', 'string'],
            'package_prices.*.price' => ['required', 'integer', 'min:0'],
            'pay_notify_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'add_debt_notify_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'renew_notify_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $agent->update([
            'electronic_payment_enabled' => $validated['electronic_payment_enabled'],
            'pay_notify_message' => $validated['pay_notify_message'] ?? null,
            'add_debt_notify_message' => $validated['add_debt_notify_message'] ?? null,
            'renew_notify_message' => $validated['renew_notify_message'] ?? null,
        ]);

        foreach ($validated['package_prices'] as $row) {
            PackagePrice::updateOrCreate(
                ['agent_id' => $agent->id, 'package' => $row['package']],
                ['price' => $row['price']],
            );
        }

        return response()->json($this->payload($agent->fresh()));
    }

    private function payload(Agent $agent): array
    {
        return [
            'agent_name' => $agent->name,
            'balance' => $agent->balance,
            'electronic_payment_enabled' => $agent->electronic_payment_enabled,
            'package_prices' => $agent->packagePrices()->get(['package', 'price']),
            'pay_notify_message' => $agent->pay_notify_message ?? self::DEFAULT_PAY_MESSAGE,
            'add_debt_notify_message' => $agent->add_debt_notify_message ?? self::DEFAULT_ADD_DEBT_MESSAGE,
            'renew_notify_message' => $agent->renew_notify_message ?? self::DEFAULT_RENEW_MESSAGE,
        ];
    }
}
