<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Orchestrator\ControlProfile;
use App\Services\OrchestratorStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Additive JSON surface for the nntmux-control webui. Mirrors the read-only
 * contract of AdminOrchestratorController: `status()` only ever calls
 * OrchestratorStatusService::report(). `mode()` only ever calls the
 * nntmux:orchestrator-mode Artisan command (the one sanctioned mutation
 * path) — it must never write to `settings`, WorkerControlStateStore, or
 * any other orchestrator-owned store directly. See
 * docs/plans/2026-10-20-nntmux-control-webui.md for the full design and the
 * one-writer invariant this must preserve.
 */
class OrchestratorApiController extends Controller
{
    public function status(Request $request, OrchestratorStatusService $status): JsonResponse
    {
        return response()->json($status->report());
    }

    public function mode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', Rule::in(['set', 'reset'])],
            'mode' => ['nullable', 'string', Rule::in(array_map(
                static fn (ControlProfile $profile): string => $profile->value,
                ControlProfile::cases(),
            ))],
            'confirm' => ['nullable', 'boolean'],
        ]);

        $action = $validated['action'] ?? ($validated['mode'] === null ? 'reset' : 'set');
        $mode = $validated['mode'] ?? null;

        if ($action === 'set' && $mode === null) {
            throw ValidationException::withMessages([
                'mode' => 'A mode is required when action is "set".',
            ]);
        }

        if ($action === 'set' && $mode !== null && ControlProfile::from($mode)->isFreeRunFamily() && $request->boolean('confirm') !== true) {
            return response()->json([
                'error' => $mode.' requires an explicit confirm: true in the request body.',
            ], 422);
        }

        $arguments = ['action' => $action, '--json' => true];
        if ($action === 'set') {
            $arguments['mode'] = $mode;
        }

        // The ONLY sanctioned mutation path. This must never be replaced by a
        // direct write to `settings`, WorkerControlStateStore, or
        // ControlProfileOverride — see the class doc above.
        $exitCode = Artisan::call('nntmux:orchestrator-mode', $arguments);
        $result = json_decode(Artisan::output(), true);
        $result = is_array($result) ? $result : ['raw_output' => Artisan::output()];

        $admin = $request->user();

        Log::channel('orchestrator')->info('Orchestrator control action', [
            'actor_user_id' => $admin?->id,
            'actor_email' => $admin?->email,
            'action' => $action,
            'mode' => $mode,
            'ip' => $request->ip(),
            'exit_code' => $exitCode,
            'result' => $result,
        ]);

        return response()->json($result, $exitCode === 0 ? 200 : 422);
    }
}
