<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the `X-Orchestrator-Control-Token` header to match a dedicated,
 * narrowly-scoped secret (config('nntmux_orchestrator.control_token'), from
 * env ORCHESTRATOR_CONTROL_TOKEN) held only by the nntmux-control backend.
 *
 * Deliberately separate from the general-purpose `api_token` scheme: a leaked
 * ordinary api_token must not be enough, by itself, to change the orchestrator
 * control profile. This middleware stacks on top of EnsureAdminApiUser; it
 * never substitutes for the admin-identity check, and it never writes
 * anything itself.
 */
class EnsureOrchestratorControlToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = Config::string('nntmux_orchestrator.control_token');
        $provided = $request->header('X-Orchestrator-Control-Token');

        if ($expected === '' || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
