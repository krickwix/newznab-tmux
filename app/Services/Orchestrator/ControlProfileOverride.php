<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use App\Models\Settings;

/**
 * The operator's pinned control mode.
 *
 * Free-run shipped as an env var, which means every mode change is a manifest
 * edit, a rebuild and a rollout -- minutes of latency on a control that exists
 * to be used when the fleet is misbehaving. This is the same override with a
 * runtime store: a settings row the next control cycle reads.
 *
 * It lives in `settings` rather than Redis deliberately. The orchestrator's own
 * state IS in Redis, and a flush there is a recoverable event (the ladder just
 * restarts at Drain); a flush that silently un-pins the fleet is not.
 */
final class ControlProfileOverride
{
    public const string SETTING = 'orchestrator_mode_pin';

    /**
     * Where the orchestrator publishes its own FREE_RUN default, so pods that
     * do not carry that env var can still report it. Written every control
     * cycle by WorkerProfileApplier.
     */
    public const string FREE_RUN_DEFAULT_SETTING = 'orchestrator_free_run';

    /**
     * The pin an operator has explicitly set, if any.
     */
    public function stored(): ?ControlProfile
    {
        $raw = trim((string) (Settings::settingValue(self::SETTING) ?? ''));

        return $raw === '' ? null : ControlProfile::tryFrom($raw);
    }

    /**
     * The pin the orchestrator will actually honour.
     *
     * A stored override outranks the deployed FREE_RUN default, which is what
     * makes this usable as an off switch for free-run without a rollout. With
     * nothing stored it falls back to that default, so `reset` returns the
     * fleet to how it was deployed rather than to a hardcoded mode.
     */
    public function effective(): ?ControlProfile
    {
        return $this->stored() ?? $this->configuredDefault();
    }

    /**
     * The mode `reset` would leave the fleet in: the deployed default, or null
     * for adaptive control.
     *
     * Prefers the value the orchestrator publishes over the local config,
     * because NNTMUX_ORCHESTRATOR_FREE_RUN is set on the orchestrator
     * deployment alone. Read locally from a worker pod it is false, so the CLI
     * would tell an operator that reset returns them to the adaptive ladder
     * while the orchestrator went straight back to free-run.
     */
    public function configuredDefault(): ?ControlProfile
    {
        $published = Settings::settingValue(self::FREE_RUN_DEFAULT_SETTING);
        $freeRun = $published === null
            ? (bool) config('nntmux.orchestrator.free_run', false)
            : (bool) $published;

        return $freeRun ? ControlProfile::FreeRun : null;
    }

    public function set(ControlProfile $profile): void
    {
        Settings::query()->updateOrCreate(
            ['name' => self::SETTING],
            ['value' => $profile->value],
        );
        Settings::forgetCachedSettings();
    }

    public function clear(): void
    {
        Settings::query()->updateOrCreate(
            ['name' => self::SETTING],
            ['value' => ''],
        );
        Settings::forgetCachedSettings();
    }
}
