<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the caller from `api_token`/`apikey` (the existing per-user token
 * scheme also used by the public search API) and requires UserRole::ADMIN.
 *
 * This is the read/identity side of nntmux-control's auth: it proves WHO is
 * calling, for audit logging, and that they hold an Admin role. It is not,
 * by itself, sufficient authorization for the orchestrator *control* route —
 * see EnsureOrchestratorControlToken for the separate, more privileged check
 * required there.
 */
class EnsureAdminApiUser
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('Authorization')
            ? str_replace('Bearer ', '', (string) $request->header('Authorization'))
            : ($request->input('api_token') ?? $request->input('apikey'));

        $user = is_string($token) && $token !== ''
            ? User::findVerifiedByApiToken($token)
            : null;

        if ($user === null || $user->roles_id !== UserRole::ADMIN->value) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
