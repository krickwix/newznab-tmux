<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\OrchestratorStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Additive JSON surface for the nntmux-control webui. Mirrors the read-only
 * contract of AdminOrchestratorController: `status()` only ever calls
 * OrchestratorStatusService::report(). `mode()` only ever calls the
 * nntmux:orchestrator-mode Artisan command (the one sanctioned mutation
 * path) — it must never write to `settings`, WorkerControlStateStore, or
 * any other orchestrator-owned store directly. See
 * docs/plans/2026-10-20-nntmux-control-webui.md for the full design and the
 * one-writer invariant this must preserve.
 *
 * Skeleton only (architect handoff) — coder to fill in validation, the
 * Artisan::call() wiring, the confirm-for-free_run gate, and audit logging.
 */
class OrchestratorApiController extends Controller
{
    public function status(Request $request, OrchestratorStatusService $status): JsonResponse
    {
        return response()->json($status->report());
    }

    public function mode(Request $request): JsonResponse
    {
        // TODO(coder): validate `mode` against ControlProfile values (or
        // `reset`), require `confirm === true` in the body when
        // mode === 'free_run', then call ONLY:
        //   Artisan::call('nntmux:orchestrator-mode', [
        //       'action' => $request->boolean('reset') ? 'reset' : 'set',
        //       'mode' => $request->input('mode'),
        //       '--json' => true,
        //   ]);
        // and log the acting admin (from EnsureAdminApiUser's resolved
        // user), mode, ip, and the command's own JSON result.
        return response()->json(['error' => 'Not implemented'], 501);
    }
}
