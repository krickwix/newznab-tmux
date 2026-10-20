<?php

declare(strict_types=1);

return [
    /*
     * Shared secret required (via the X-Orchestrator-Control-Token header)
     * to reach the orchestrator *control* API route. Deliberately separate
     * from any user's api_token. Provisioned as a sops-managed k8s secret
     * alongside the nntmux-control app; never reused from another secret.
     */
    'control_token' => env('ORCHESTRATOR_CONTROL_TOKEN', ''),
];
