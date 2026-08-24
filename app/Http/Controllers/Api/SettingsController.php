<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\PackagePrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** إعدادات الوكيل: اسمه ورصيده (للعرض فقط)، أسعار الباقات، وتفعيل الدفع الإلكتروني. */
class SettingsController extends Controller
{
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
        ]);

        $agent->update(['electronic_payment_enabled' => $validated['electronic_payment_enabled']]);

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
        ];
    }
}
