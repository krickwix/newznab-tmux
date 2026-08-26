<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Services\OrchestratorStatusService;
use Illuminate\View\View;

class AdminOrchestratorController extends BasePageController
{
    /**
     * Read-only view of the adaptive worker orchestrator's published state.
     *
     * There is no companion write action and there must never be one: permits,
     * profile pins and fail-safe recovery stay on the CLI so the orchestrator's
     * generation fencing and single-supply-window invariants keep one writer.
     */
    public function index(OrchestratorStatusService $status): View
    {
        $this->setAdminPrefs();

        return view('admin.orchestrator.index', [
            'report' => $status->report(),
            'title' => 'Worker Orchestrator',
            'page_title' => 'Worker Orchestrator',
            'meta_title' => 'Worker Orchestrator',
            'meta_description' => 'Read-only adaptive worker orchestrator state: profile, lease, permits, pressure and lane liveness.',
        ]);
    }
}
