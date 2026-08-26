<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** يحمي مسارات لوحة الإدارة (/admin/*) — يُطبَّق بعد auth:sanctum. */
class EnsureAgentIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Agent|null $agent */
        $agent = $request->user();

        abort_unless($agent?->is_admin, 403, 'هذا الإجراء مخصص للإدارة فقط.');

        return $next($request);
    }
}
