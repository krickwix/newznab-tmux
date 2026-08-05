<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Settings;
use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlProfileOverride;
use App\Services\Orchestrator\WorkerControlProfile;
use App\Services\Orchestrator\WorkerControlStateStore;
use Illuminate\Console\Command;

/**
 * Operator control for which orchestrator mode the fleet runs in.
 *
 * `get` deliberately reports three layers rather than one number. The worst
 * orchestrator failure this fleet has had was not a wrong mode, it was a mode
 * that disagreed with itself: the control loop reported profile=free_run while
 * the adaptive planner floored every worker timer straight back up, so the
 * fleet had no brakes AND no speed. Anything that shows only the decided
 * profile would have shown that as healthy.
 */
class NntmuxOrchestratorMode extends Command
{
    protected $signature = 'nntmux:orchestrator-mode
                            {action=get : list, get, set, or reset}
                            {mode? : The mode to pin, for `set`}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'List, inspect, pin, or reset the orchestrator control mode';

    public function handle(ControlProfileOverride $override, WorkerControlStateStore $store): int
    {
        return match ((string) $this->argument('action')) {
            'list' => $this->list(),
            'get' => $this->get($override, $store),
            'set' => $this->set($override),
            'reset' => $this->reset($override),
            default => $this->unknownAction(),
        };
    }

    private function unknownAction(): int
    {
        $this->error(sprintf(
            'Unknown action "%s". Expected one of: list, get, set, reset.',
            (string) $this->argument('action'),
        ));

        return self::FAILURE;
    }

    private function list(): int
    {
        $modes = array_map(static function (ControlProfile $profile): array {
            $worker = WorkerControlProfile::for($profile);

            return [
                'mode' => $profile->value,
                'rung' => $profile->rung(),
                'adaptive' => $profile !== ControlProfile::FreeRun,
                'bypasses_safety' => $profile->bypassesSafety(),
                'backfill' => $worker->backfillEnabled,
                'sleeps' => [
                    'binaries' => $worker->binariesSleepSeconds,
                    'backfill' => $worker->backfillSleepSeconds,
                    'releases' => $worker->releasesSleepSeconds,
                    'nzb' => $worker->nzbSleepSeconds,
                ],
                'description' => $profile->description(),
            ];
        }, ControlProfile::cases());

        if ($this->option('json')) {
            return $this->json(['modes' => $modes]);
        }

        $this->table(
            ['mode', 'rung', 'backfill', 'sleeps (bin/bf/rel/nzb)', 'description'],
            array_map(static fn (array $mode): array => [
                $mode['mode'].($mode['bypasses_safety'] ? ' *' : ''),
                (string) $mode['rung'],
                $mode['backfill'] ? 'on' : 'off',
                implode('/', array_map(strval(...), array_values($mode['sleeps']))),
                $mode['description'],
            ], $modes),
        );
        $this->line('  * bypasses the hard-safety gates. Every other mode still yields to them.');
        $this->line('  Sleep seconds are the profile floor; the adaptive planner may lengthen them.');

        return self::SUCCESS;
    }

    private function get(ControlProfileOverride $override, WorkerControlStateStore $store): int
    {
        $pinned = $override->stored();
        $effective = $override->effective();
        $state = $store->loadState();
        $applied = trim((string) (Settings::settingValue('orchestrator_profile') ?? ''));
        $decision = $store->lastDecision();

        $payload = [
            // What an operator pinned, if anything.
            'pinned' => $pinned?->value,
            // What `reset` would fall back to (the deployed FREE_RUN default).
            'configured_default' => $override->configuredDefault()?->value,
            // What the next control cycle will select, before safety demotion.
            'effective' => $effective?->value,
            // Where the control loop's own state machine currently sits.
            'control_state' => $state->profile->value,
            // What was last written to the workers. Disagreement with
            // control_state means the loop is not applying its own decision.
            'applied_to_workers' => $applied === '' ? null : $applied,
            'reasons' => $decision['reasons'] ?? [],
            'decided_at' => $decision['observed_at'] ?? null,
        ];

        if ($this->option('json')) {
            return $this->json($payload);
        }

        $this->line(sprintf('pinned              : %s', $pinned?->value ?? '(none -- adaptive ladder)'));
        $this->line(sprintf('configured default  : %s', $override->configuredDefault()?->value ?? '(none -- adaptive ladder)'));
        $this->line(sprintf('effective mode      : %s', $effective?->value ?? '(adaptive)'));
        $this->line(sprintf('control state       : %s', $state->profile->value));
        $this->line(sprintf('applied to workers  : %s', $payload['applied_to_workers'] ?? '(never applied)'));
        if ($payload['reasons'] !== []) {
            $this->line(sprintf('last reasons        : %s', implode(', ', array_map(strval(...), (array) $payload['reasons']))));
        }

        // The disagreement that matters. control_state is what the loop
        // decided; applied_to_workers is what the workers were actually told.
        if ($payload['applied_to_workers'] !== null && $payload['applied_to_workers'] !== $state->profile->value) {
            $this->newLine();
            $this->warn(sprintf(
                'Control state (%s) and applied profile (%s) disagree. The loop is deciding one thing and the workers are running another -- check the orchestrator pod before trusting either.',
                $state->profile->value,
                $payload['applied_to_workers'],
            ));
        }

        return self::SUCCESS;
    }

    private function set(ControlProfileOverride $override): int
    {
        $raw = trim((string) ($this->argument('mode') ?? ''));
        if ($raw === '') {
            $this->error('A mode is required: nntmux:orchestrator-mode set <mode>');
            $this->line('Run `nntmux:orchestrator-mode list` to see them.');

            return self::FAILURE;
        }

        $profile = ControlProfile::tryFrom($raw);
        if ($profile === null) {
            $this->error(sprintf(
                'Unknown mode "%s". Valid modes: %s',
                $raw,
                implode(', ', array_map(static fn (ControlProfile $p): string => $p->value, ControlProfile::cases())),
            ));

            return self::FAILURE;
        }

        $previous = $override->stored();
        $override->set($profile);

        if ($this->option('json')) {
            return $this->json([
                'pinned' => $profile->value,
                'previous' => $previous?->value,
                'bypasses_safety' => $profile->bypassesSafety(),
            ]);
        }

        $this->info(sprintf('Pinned the orchestrator to %s.', $profile->value));
        $this->line($profile->description());
        if ($profile->bypassesSafety()) {
            $this->newLine();
            $this->warn('This mode ignores the hard-safety gates -- database memory, CPU, row-lock waits and disk. Those gates exist because this fleet has twice been taken down without them. Nothing will step the fleet back down on its own; reset when you are done.');
        }
        $this->newLine();
        $this->line('Takes effect on the next control cycle (up to the orchestrator --sleep interval).');

        return self::SUCCESS;
    }

    private function reset(ControlProfileOverride $override): int
    {
        $previous = $override->stored();
        $override->clear();
        $fallback = $override->configuredDefault();

        if ($this->option('json')) {
            return $this->json([
                'pinned' => null,
                'previous' => $previous?->value,
                'effective' => $fallback?->value,
            ]);
        }

        $this->info('Cleared the pinned mode.');
        // Reset means "back to how this fleet was deployed", which is not
        // necessarily adaptive -- if FREE_RUN is set in the manifest, clearing
        // the pin hands the fleet straight back to free-run. Saying so here is
        // the difference between an off switch and a surprise.
        if ($fallback !== null) {
            $this->warn(sprintf(
                'The deployed default is %s, so that is what the fleet returns to -- not the adaptive ladder. Pin a mode explicitly to override it.',
                $fallback->value,
            ));
        } else {
            $this->line('The adaptive ladder resumes control.');
        }
        $this->newLine();
        $this->line('Takes effect on the next control cycle (up to the orchestrator --sleep interval).');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): int
    {
        $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
