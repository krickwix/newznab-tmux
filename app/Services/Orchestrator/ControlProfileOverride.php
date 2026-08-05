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
    public const string SETTING = 'orchestrator_profile_override';

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
        return $this->stored()
            ?? ((bool) config('nntmux.orchestrator.free_run', false) ? ControlProfile::FreeRun : null);
    }

    /**
     * The mode `reset` would leave the fleet in: the deployed default, or null
     * for adaptive control.
     */
    public function configuredDefault(): ?ControlProfile
    {
        return (bool) config('nntmux.orchestrator.free_run', false) ? ControlProfile::FreeRun : null;
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
