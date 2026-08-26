@extends('layouts.admin')

@section('content')
@php
    $toneClass = static fn (?string $tone): string => match ($tone) {
        'ok' => 'text-green-700 dark:text-green-300 font-medium',
        'warn' => 'text-amber-700 dark:text-amber-300 font-medium',
        'bad' => 'text-red-700 dark:text-red-300 font-medium',
        'muted' => 'text-gray-400 dark:text-gray-500 italic',
        default => 'text-gray-900 dark:text-gray-100',
    };

    $pill = static fn (string $tone): string => 'px-2 py-0.5 inline-flex text-xs leading-5 font-semibold rounded-full '.match ($tone) {
        'ok' => 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200',
        'warn' => 'bg-amber-100 dark:bg-amber-900 text-amber-800 dark:text-amber-200',
        'bad' => 'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200',
        'info' => 'bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200',
        default => 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200',
    };

    $duration = static function ($seconds): string {
        if ($seconds === null) {
            return 'unavailable';
        }
        $s = (int) round((float) $seconds);
        if ($s < 60) {
            return $s.'s';
        }
        if ($s < 3600) {
            return intdiv($s, 60).'m '.($s % 60).'s';
        }
        if ($s < 86400) {
            return intdiv($s, 3600).'h '.intdiv($s % 3600, 60).'m';
        }

        return intdiv($s, 86400).'d '.intdiv($s % 86400, 3600).'h';
    };

    $stamp = static fn ($ts): string => ($ts === null || (int) $ts <= 0)
        ? 'never'
        : date('Y-m-d H:i:s', (int) $ts);

    $count = static fn ($n): string => $n === null ? 'unavailable' : number_format((float) $n, 0);

    $yesNo = static fn ($b): string => $b === null ? 'unavailable' : ($b ? 'yes' : 'no');

    $bytes = static function ($value): string {
        if ($value === null) {
            return 'unavailable';
        }
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $size = (float) $value;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 1).' '.$units[$unit];
    };

    $fresh = (bool) ($report['freshness']['fresh'] ?? false);
    $freshnessKnown = (bool) ($report['freshness']['available'] ?? false);
    $stale = $freshnessKnown && ! $fresh;

    $profile = $report['profile'];
    $lease = $report['lease'];
    $failSafe = $report['fail_safe'];
    $pressure = $report['pressure'];
    $backfill = $report['backfill'];
    $observation = $backfill['observation'];
    $denial = $report['denial'];
    $currentForward = $report['current_forward'];
    $yield = $report['yield'];
    $safety = $report['safety'];
    $lanes = $report['lanes'];

    $pressureTone = match ($pressure['classification'] ?? null) {
        'high' => 'bad',
        'low' => 'ok',
        'neutral' => 'info',
        default => 'default',
    };
@endphp

<div class="space-y-6">
    <x-admin.card>
        <x-admin.page-header :title="$title" icon="fas fa-sliders-h"
            subtitle="Read-only view of the adaptive worker orchestrator. Nothing on this page changes state.">
            <x-slot:actions>
                <span class="text-sm text-gray-600 dark:text-gray-400">
                    Rendered {{ $stamp($report['generated_at']) }}
                </span>
                <a href="{{ route('admin.orchestrator.index') }}" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-100 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600">
                    <i class="fas fa-rotate mr-2"></i>Refresh
                </a>
            </x-slot:actions>
        </x-admin.page-header>

        <x-admin.info-alert>
            Permits, profile pins and fail-safe recovery stay on the CLI
            (<code>php artisan nntmux:worker-orchestrator</code>,
            <code>php artisan nntmux:orchestrator-mode</code>). This page only reads what the
            orchestrator already publishes to the settings table and Redis; it never captures a
            live pipeline snapshot, so figures below are the controller's own last observation.
        </x-admin.info-alert>

        @if(! $freshnessKnown)
            <div class="px-6 py-4 bg-red-50 dark:bg-red-900/20 border-b border-red-200 dark:border-red-800 text-sm text-red-800 dark:text-red-200">
                <i class="fas fa-triangle-exclamation mr-2"></i>
                <strong>No controller observation available.</strong>
                The Redis state store returned nothing for the last decision key. Either the
                orchestrator has never run against this store, or Redis is unreachable. Treat every
                Redis-sourced panel below as unknown, not as zero.
            </div>
        @elseif($stale)
            <div class="px-6 py-4 bg-red-50 dark:bg-red-900/20 border-b border-red-200 dark:border-red-800 text-sm text-red-800 dark:text-red-200">
                <i class="fas fa-triangle-exclamation mr-2"></i>
                <strong>Controller observation is stale.</strong>
                Last observed {{ $duration($report['freshness']['age_seconds']) }} ago
                ({{ $stamp($report['freshness']['observed_at']) }}), past the
                {{ $duration($report['freshness']['max_age_seconds']) }} maximum age. The
                orchestrator fails closed on stale telemetry, so backfill is denied until a fresh
                cycle lands.
            </div>
        @else
            <div class="px-6 py-4 bg-green-50 dark:bg-green-900/20 border-b border-green-200 dark:border-green-800 text-sm text-green-800 dark:text-green-200">
                <i class="fas fa-circle-check mr-2"></i>
                Controller observation is fresh: {{ $duration($report['freshness']['age_seconds']) }} old,
                within the {{ $duration($report['freshness']['max_age_seconds']) }} maximum age.
            </div>
        @endif

        <div class="px-6 py-4">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Data sources</h2>
            <div class="flex flex-wrap gap-2">
                @php
                    $sourceLabels = [
                        'settings' => 'settings table',
                        'state_store' => 'control state (Redis)',
                        'decision' => 'last decision (Redis)',
                        'permit_observation' => 'permit observation (Redis)',
                        'yield_history' => 'yield history (Redis)',
                        'current_forward_windows' => 'current-forward ledger',
                    ];
                @endphp
                @foreach($sourceLabels as $key => $label)
                    <span class="{{ $pill($report['sources'][$key] ? 'ok' : 'bad') }}">
                        <i class="fas {{ $report['sources'][$key] ? 'fa-circle-check' : 'fa-circle-xmark' }} mr-1"></i>{{ $label }}
                    </span>
                @endforeach
            </div>
        </div>
    </x-admin.card>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <x-admin.stat-card
            label="Control profile"
            :value="$profile['profile'] !== '' ? $profile['profile'] : 'unknown'"
            icon="fas fa-gauge-high"
            :color="$profile['profile'] === 'fail_safe' ? 'red' : ($profile['profile'] === 'free_run' ? 'yellow' : 'blue')"
            :footer="'mode: '.($profile['mode'] !== '' ? $profile['mode'] : 'unknown').' · generation '.$count($profile['generation'])" />

        <x-admin.stat-card
            label="Pipeline pressure"
            :value="$pressure['available'] ? ($pressure['classification'] ?? 'unknown') : 'unavailable'"
            icon="fas fa-wave-square"
            :color="($pressure['classification'] ?? null) === 'high' ? 'red' : ((($pressure['classification'] ?? null) === 'low') ? 'green' : 'indigo')"
            :footer="$stale ? 'from a stale observation' : 'from the last controller observation'" />

        <x-admin.stat-card
            label="Backfill permit"
            :value="$backfill['granted'] ? '#'.$count($backfill['permit_generation']) : 'none'"
            icon="fas fa-ticket"
            :color="$backfill['granted'] ? 'green' : 'red'"
            :footer="$backfill['paused'] ? 'backfill paused' : 'backfill admission open'" />

        <x-admin.stat-card
            label="Orchestrator lease"
            :value="$lease['available'] ? ($lease['expired'] ? 'expired' : $duration($lease['remaining_seconds']).' left') : 'unavailable'"
            icon="fas fa-key"
            :color="(! $lease['available'] || $lease['expired']) ? 'red' : 'green'"
            :footer="$lease['lock']['available'] ? 'leader lock '.($lease['lock']['held'] ? 'held' : 'free') : 'leader lock unavailable'" />
    </div>

    {{-- Why backfill is or is not running --}}
    <x-admin.card>
        <x-admin.page-header title="Why backfill is or is not running" icon="fas fa-circle-question" />
        <div class="px-6 py-5 space-y-4">
            @php
                $denialRows = [
                    ['Deterministic gates permitted backfill', $denial['policy_permitted'] === null ? 'unavailable' : $yesNo($denial['policy_permitted']), $denial['policy_permitted'] === null ? 'muted' : ($denial['policy_permitted'] ? 'ok' : 'bad')],
                    ['Permit granted on the last cycle', $denial['permit_granted_last_cycle'] === null ? 'unavailable' : $yesNo($denial['permit_granted_last_cycle']), $denial['permit_granted_last_cycle'] === null ? 'muted' : ($denial['permit_granted_last_cycle'] ? 'ok' : 'warn')],
                    ['Backfill paused flag (settings)', $yesNo($denial['paused_setting']), $denial['paused_setting'] ? 'bad' : 'ok'],
                    ['Target locked out after ineffective permits', $denial['backfill_locked'] === null ? 'unavailable' : $yesNo($denial['backfill_locked']), $denial['backfill_locked'] === null ? 'muted' : ($denial['backfill_locked'] ? 'bad' : 'ok')],
                    ['Consecutive ineffective permits', $count($denial['ineffective_permits']), $denial['ineffective_permits'] === null ? 'muted' : ((int) $denial['ineffective_permits'] > 0 ? 'warn' : 'default')],
                    ['Qualified supply starved', $denial['qualified_supply_starved'] === null ? 'unavailable' : $yesNo($denial['qualified_supply_starved']), $denial['qualified_supply_starved'] === null ? 'muted' : ($denial['qualified_supply_starved'] ? 'bad' : 'ok')],
                    ['Release yield per minute', $denial['release_yield_per_minute'] === null ? 'unmeasured' : (string) round((float) $denial['release_yield_per_minute'], 2), $denial['release_yield_per_minute'] === null ? 'muted' : 'default'],
                    ['Unsettled current-forward windows (blocks permits)', $denial['current_forward_unsettled'] === null ? 'unavailable' : $count($denial['current_forward_unsettled']), $denial['current_forward_unsettled'] === null ? 'muted' : ((int) $denial['current_forward_unsettled'] > 0 ? 'warn' : 'ok')],
                    ['Workers require a permit (config)', $yesNo($denial['require_permit']), 'default'],
                    ['Automatic permit issuance (config)', $yesNo($denial['auto_backfill']), 'default'],
                ];
            @endphp
            <dl class="grid grid-cols-1 lg:grid-cols-2 gap-x-8 gap-y-2">
                @foreach($denialRows as [$label, $value, $tone])
                    <div class="flex justify-between gap-4 py-1 border-b border-gray-100 dark:border-gray-700">
                        <dt class="text-sm text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="text-sm text-right {{ $toneClass($tone) }}">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <div>
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Reasons recorded by the last decision</h3>
                @if(! $denial['fresh'] && $denial['reasons'] !== [])
                    <p class="text-xs text-amber-700 dark:text-amber-300 mb-2">
                        <i class="fas fa-triangle-exclamation mr-1"></i>These reasons come from a stale observation.
                    </p>
                @endif
                @forelse($denial['reasons'] as $reason)
                    <span class="{{ $pill('info') }} mr-2 mb-2">{{ $reason }}</span>
                @empty
                    <p class="text-sm {{ $toneClass('muted') }}">
                        {{ $denial['fresh'] ? 'No reasons recorded on the last cycle.' : 'unavailable — no fresh controller observation' }}
                    </p>
                @endforelse
            </div>

            @if(($denial['ineffective_by_target'] ?? []) !== [])
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Ineffective permits by target group</h3>
                    <x-admin.data-table>
                        <x-slot:head>
                            <x-admin.th>Group</x-admin.th>
                            <x-admin.th align="right">Ineffective permits</x-admin.th>
                        </x-slot:head>
                        @foreach($denial['ineffective_by_target'] as $group => $ineffective)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-6 py-3 text-sm text-gray-900 dark:text-gray-100 font-mono">{{ $group }}</td>
                                <td class="px-6 py-3 text-sm text-right text-gray-900 dark:text-gray-100">{{ $count($ineffective) }}</td>
                            </tr>
                        @endforeach
                    </x-admin.data-table>
                </div>
            @endif
        </div>
    </x-admin.card>

    {{-- Profile, lease, fail-safe --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <x-admin.card>
            <x-admin.page-header title="Worker profile" icon="fas fa-gauge-high" />
            <div class="px-6 py-5">
                @if(! $profile['available'])
                    <p class="text-sm {{ $toneClass('bad') }}">Settings table unreadable — profile unavailable.</p>
                @else
                    @if($profile['description'] !== null)
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">{{ $profile['description'] }}</p>
                    @endif
                    @php
                        $profileRows = [
                            ['Effective profile', $profile['profile'] !== '' ? $profile['profile'] : 'unknown', $profile['profile'] === '' ? 'muted' : 'default'],
                            ['Mode', $profile['mode'] !== '' ? $profile['mode'] : 'unknown', $profile['mode'] === 'failsafe' ? 'bad' : 'default'],
                            ['Durable profile (settings)', $profile['durable_profile'] !== '' ? $profile['durable_profile'] : 'unset', $profile['durable_profile'] === '' ? 'muted' : 'default'],
                            ['Profile in Redis state', $profile['redis_available'] ? ($profile['redis_profile'] ?? 'unset') : 'unavailable', $profile['redis_available'] ? 'default' : 'muted'],
                            ['Ladder rung', $profile['rung'] === null ? 'unknown' : (string) $profile['rung'], $profile['rung'] === null ? 'muted' : 'default'],
                            ['Bypasses safety gates', $profile['bypasses_safety'] === null ? 'unknown' : $yesNo($profile['bypasses_safety']), $profile['bypasses_safety'] ? 'bad' : 'default'],
                            ['Free-run published to workers', $yesNo($profile['free_run_published']), $profile['free_run_published'] ? 'warn' : 'default'],
                            ['Free-run configured on this pod', $yesNo($profile['free_run_configured']), $profile['free_run_configured'] ? 'warn' : 'default'],
                            ['Recovery permitted', $yesNo($profile['recovery_ok']), 'default'],
                            ['Applied generation', $count($profile['generation']), 'default'],
                            ['Consecutive high samples', $profile['consecutive_high'] === null ? 'unavailable' : $count($profile['consecutive_high']), $profile['consecutive_high'] === null ? 'muted' : 'default'],
                            ['Consecutive low samples', $profile['consecutive_low'] === null ? 'unavailable' : $count($profile['consecutive_low']), $profile['consecutive_low'] === null ? 'muted' : 'default'],
                            ['Last profile transition', $profile['last_transition_at'] === null ? 'unavailable' : $stamp($profile['last_transition_at']), $profile['last_transition_at'] === null ? 'muted' : 'default'],
                            ['Binaries sleep', $duration($profile['timers']['binaries']), 'default'],
                            ['Backfill sleep', $duration($profile['timers']['backfill']), 'default'],
                            ['Releases sleep', $duration($profile['timers']['releases']), 'default'],
                            ['NZB sleep', $duration($profile['timers']['nzbs']), 'default'],
                            ['NZB batch size', $count($profile['nzb_batch_size']), 'default'],
                        ];
                    @endphp
                    <dl class="space-y-1">
                        @foreach($profileRows as [$label, $value, $tone])
                            <div class="flex justify-between gap-4 py-1 border-b border-gray-100 dark:border-gray-700">
                                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                                <dd class="text-sm text-right {{ $toneClass($tone) }}">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </div>
        </x-admin.card>

        <x-admin.card>
            <x-admin.page-header title="Lease and leader lock" icon="fas fa-key" />
            <div class="px-6 py-5">
                @php
                    $leaseRows = [
                        ['Lease expires at', $lease['available'] && $lease['lease_until'] > 0 ? $stamp($lease['lease_until']) : 'unavailable', $lease['available'] && $lease['lease_until'] > 0 ? 'default' : 'muted'],
                        ['Lease remaining', $lease['available'] ? ($lease['expired'] ? 'expired' : $duration($lease['remaining_seconds'])) : 'unavailable', ! $lease['available'] ? 'muted' : ($lease['expired'] ? 'bad' : 'ok')],
                        ['Lease age', $lease['age_seconds'] === null ? 'unavailable' : $duration($lease['age_seconds']), $lease['age_seconds'] === null ? 'muted' : 'default'],
                        ['Leader lock currently held', $lease['lock']['available'] ? $yesNo($lease['lock']['held']) : 'unavailable', ! $lease['lock']['available'] ? 'muted' : ($lease['lock']['held'] ? 'ok' : 'warn')],
                        ['Leader lock lifetime', $duration($lease['lock_ttl_seconds']), 'default'],
                    ];
                @endphp
                <dl class="space-y-1">
                    @foreach($leaseRows as [$label, $value, $tone])
                        <div class="flex justify-between gap-4 py-1 border-b border-gray-100 dark:border-gray-700">
                            <dt class="text-sm text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="text-sm text-right {{ $toneClass($tone) }}">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-4">
                    The leader lock's owner is a random per-run token, so it names no pod. An expired
                    lease with no held lock means no orchestrator cycle is running; workers fall back
                    to their conservative defaults.
                </p>
            </div>
        </x-admin.card>

        <x-admin.card>
            <x-admin.page-header title="Fail-safe" icon="fas fa-shield-halved" />
            <div class="px-6 py-5">
                @if(! $failSafe['available'])
                    <p class="text-sm {{ $toneClass('muted') }}">Redis state store unreadable — fail-safe state unavailable.</p>
                @else
                    @php
                        $causeLabel = match ($failSafe['cause']) {
                            'telemetry' => 'telemetry — a snapshot or safety signal could not be trusted',
                            'hard' => 'hard — a database, disk or pressure gate tripped',
                            'pinned' => 'pinned — an operator parked the fleet; nothing is broken',
                            'unknown' => 'unknown — recorded by a different binary; conservative recovery',
                            default => null,
                        };
                        $failSafeRows = [
                            ['Applier parked in fail-safe', $yesNo($failSafe['mode_failsafe']), $failSafe['mode_failsafe'] ? 'bad' : 'ok'],
                            ['Last fail-safe cause', $causeLabel ?? 'none recorded', $causeLabel === null ? 'muted' : 'bad'],
                            ['Recovery samples accumulated', $failSafe['recovery_samples'] === null ? 'unavailable' : $count($failSafe['recovery_samples']), $failSafe['recovery_samples'] === null ? 'muted' : 'default'],
                            ['Last observed in fail-safe', $failSafe['last_observed_at'] ? $stamp($failSafe['last_observed_at']) : 'never', $failSafe['last_observed_at'] ? 'default' : 'muted'],
                            ['Time since that observation', $failSafe['last_observed_age_seconds'] === null ? 'n/a' : $duration($failSafe['last_observed_age_seconds']), $failSafe['last_observed_age_seconds'] === null ? 'muted' : 'default'],
                            ['Recovery drain samples', $failSafe['recovery_drain_samples'] === null ? 'unavailable' : $count($failSafe['recovery_drain_samples']), $failSafe['recovery_drain_samples'] === null ? 'muted' : 'default'],
                            ['Recovery drain hold samples', $failSafe['recovery_drain_hold_samples'] === null ? 'unavailable' : $count($failSafe['recovery_drain_hold_samples']), $failSafe['recovery_drain_hold_samples'] === null ? 'muted' : 'default'],
                            ['Cooldown remaining', $failSafe['cooldown_remaining_seconds'] === null ? 'unavailable' : $duration($failSafe['cooldown_remaining_seconds']), $failSafe['cooldown_remaining_seconds'] === null ? 'muted' : 'default'],
                        ];
                    @endphp
                    <dl class="space-y-1">
                        @foreach($failSafeRows as [$label, $value, $tone])
                            <div class="flex justify-between gap-4 py-1 border-b border-gray-100 dark:border-gray-700">
                                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                                <dd class="text-sm text-right {{ $toneClass($tone) }}">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-4">
                        The cause is only recorded while the stored profile is <code>fail_safe</code>;
                        "none recorded" means the orchestrator is not currently parked.
                    </p>
                @endif
            </div>
        </x-admin.card>
    </div>

    {{-- Five-stage pressure --}}
    <x-admin.card>
        <x-admin.page-header title="Five-stage pressure classification" icon="fas fa-wave-square">
            <x-slot:actions>
                <span class="{{ $pill($pressureTone) }}">{{ $pressure['classification'] ?? 'unavailable' }}</span>
                @if($stale)
                    <span class="{{ $pill('bad') }}">stale observation</span>
                @endif
            </x-slot:actions>
        </x-admin.page-header>
        @if(! $pressure['available'])
            <div class="px-6 py-8 text-center text-sm {{ $toneClass('muted') }}">
                No controller observation in Redis — per-stage backlogs, growth and ages are unavailable.
            </div>
        @else
            <x-admin.data-table>
                <x-slot:head>
                    <x-admin.th>Stage</x-admin.th>
                    <x-admin.th align="right">Schedulable</x-admin.th>
                    <x-admin.th align="right">Physical</x-admin.th>
                    <x-admin.th align="right">High watermark</x-admin.th>
                    <x-admin.th align="right">EWMA / min</x-admin.th>
                    <x-admin.th align="right">Rate / min</x-admin.th>
                    <x-admin.th align="right">Oldest item</x-admin.th>
                    <x-admin.th align="right">Age SLO</x-admin.th>
                </x-slot:head>
                @foreach($pressure['stages'] as $stage => $data)
                    @php
                        $breached = $data['high_watermark'] !== null
                            && $data['schedulable'] !== null
                            && $data['schedulable'] >= $data['high_watermark'];
                        $ageBreached = $data['age_slo_seconds'] !== null
                            && $data['oldest_age_seconds'] !== null
                            && $data['oldest_age_seconds'] >= $data['age_slo_seconds'];
                    @endphp
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td class="px-6 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $stage }}</td>
                        <td class="px-6 py-3 text-sm text-right {{ $toneClass($data['schedulable'] === null ? 'muted' : ($breached ? 'bad' : 'default')) }}">
                            {{ $data['schedulable'] === null ? 'unavailable' : $count($data['schedulable']) }}
                        </td>
                        <td class="px-6 py-3 text-sm text-right {{ $toneClass($data['physical'] === null ? 'muted' : 'default') }}">
                            {{ $data['physical'] === null ? 'unavailable' : $count($data['physical']) }}
                        </td>
                        <td class="px-6 py-3 text-sm text-right {{ $toneClass($data['high_watermark'] === null ? 'muted' : 'default') }}">
                            {{ $data['high_watermark'] === null ? 'unset' : $count($data['high_watermark']) }}
                        </td>
                        <td class="px-6 py-3 text-sm text-right {{ $toneClass($data['ewma_per_minute'] === null ? 'muted' : (($data['ewma_per_minute'] > 0) ? 'warn' : 'default')) }}">
                            {{ $data['ewma_per_minute'] === null ? 'unavailable' : round($data['ewma_per_minute'], 2) }}
                        </td>
                        <td class="px-6 py-3 text-sm text-right {{ $toneClass($data['rate_per_minute'] === null ? 'muted' : 'default') }}">
                            {{ $data['rate_per_minute'] === null ? 'unavailable' : round($data['rate_per_minute'], 2) }}
                        </td>
                        <td class="px-6 py-3 text-sm text-right {{ $toneClass($data['oldest_age_seconds'] === null ? 'muted' : ($ageBreached ? 'bad' : 'default')) }}">
                            {{ $data['oldest_age_seconds'] === null ? 'unavailable' : $duration($data['oldest_age_seconds']) }}
                        </td>
                        <td class="px-6 py-3 text-sm text-right {{ $toneClass($data['age_slo_seconds'] === null ? 'muted' : 'default') }}">
                            {{ $data['age_slo_seconds'] === null ? 'unset' : $duration($data['age_slo_seconds']) }}
                        </td>
                    </tr>
                @endforeach
            </x-admin.data-table>
            <div class="px-6 py-4 text-xs text-gray-500 dark:text-gray-400 border-t border-gray-200 dark:border-gray-700">
                Total collections: {{ $pressure['collections_total'] === null ? 'unavailable' : $count($pressure['collections_total']) }}
                · body-recovery source collections: {{ $pressure['recovery_sources'] === null ? 'unavailable' : $count($pressure['recovery_sources']) }}.
                Schedulable counts exclude work the pipeline cannot act on; the classifier also
                projects growth forward, so a stage under its watermark can still read as high.
            </div>
        @endif
    </x-admin.card>

    {{-- Backfill permit --}}
    <x-admin.card>
        <x-admin.page-header title="Backfill permit" icon="fas fa-ticket">
            <x-slot:actions>
                <span class="{{ $pill($backfill['granted'] ? 'ok' : 'bad') }}">
                    {{ $backfill['granted'] ? 'permit generation #'.$count($backfill['permit_generation']) : 'no permit' }}
                </span>
                <span class="{{ $pill($backfill['claim_state'] === 'completed' ? 'ok' : ($backfill['claim_state'] === 'failed' ? 'bad' : 'info')) }}">
                    {{ $backfill['claim_state'] }}
                </span>
            </x-slot:actions>
        </x-admin.page-header>
        <div class="px-6 py-5">
            @php
                $permitRows = [
                    ['Target group', $backfill['group'] !== '' ? $backfill['group'] : 'none pinned', $backfill['group'] === '' ? 'muted' : 'default'],
                    ['Pinned quantity (articles)', $count($backfill['pinned_quantity']), 'default'],
                    ['Pinned stop cursor', $backfill['stop_cursor'] > 0 ? $count($backfill['stop_cursor']) : 'none', $backfill['stop_cursor'] > 0 ? 'default' : 'muted'],
                    ['Claimed generation', $backfill['claimed_generation'] > 0 ? '#'.$count($backfill['claimed_generation']) : 'none', $backfill['claimed_generation'] > 0 ? 'default' : 'muted'],
                    ['Completed generation', $backfill['completed_generation'] > 0 ? '#'.$count($backfill['completed_generation']) : 'none', $backfill['completed_generation'] > 0 ? 'default' : 'muted'],
                    ['Failed generation', $backfill['failed_generation'] > 0 ? '#'.$count($backfill['failed_generation']) : 'none', $backfill['failed_generation'] > 0 ? 'bad' : 'muted'],
                    ['Quality lock reason', $backfill['quality_lock'] !== '' ? $backfill['quality_lock'] : 'none', $backfill['quality_lock'] !== '' ? 'warn' : 'muted'],
                    ['Observation generation', $observation['present'] ? '#'.$count($observation['generation']) : ($observation['available'] ? 'none in flight' : 'unavailable'), $observation['present'] ? 'default' : 'muted'],
                    ['Observation group', $observation['present'] ? ($observation['group'] !== '' ? $observation['group'] : 'unset') : 'n/a', $observation['present'] ? 'default' : 'muted'],
                    ['Observation issued', $observation['present'] ? $stamp($observation['issued_at']) : 'n/a', $observation['present'] ? 'default' : 'muted'],
                    ['Observation age', $observation['age_seconds'] === null ? 'n/a' : $duration($observation['age_seconds']), $observation['age_seconds'] === null ? 'muted' : (($observation['age_seconds'] > $observation['observation_window_seconds']) ? 'warn' : 'default')],
                    ['Observation window', $duration($observation['observation_window_seconds']), 'default'],
                    ['Requested articles', $observation['requested_articles'] === null ? 'n/a' : $count($observation['requested_articles']), $observation['requested_articles'] === null ? 'muted' : 'default'],
                    ['Expected cursor delta', $observation['expected_cursor_delta'] === null ? 'n/a' : $count($observation['expected_cursor_delta']), $observation['expected_cursor_delta'] === null ? 'muted' : 'default'],
                    ['Cursor at issue', $observation['cursor'] === null ? 'n/a' : $count($observation['cursor']), $observation['cursor'] === null ? 'muted' : 'default'],
                    ['Cursor postdate at issue', $observation['cursor_postdate'] ?: 'n/a', $observation['cursor_postdate'] ? 'default' : 'muted'],
                    ['Ready collections at issue', $observation['ready_collections'] === null ? 'n/a' : $count($observation['ready_collections']), $observation['ready_collections'] === null ? 'muted' : 'default'],
                    ['Release high watermark at issue', $observation['release_high_watermark'] === null ? 'n/a' : $count($observation['release_high_watermark']), $observation['release_high_watermark'] === null ? 'muted' : 'default'],
                    ['Target group still enabled', $observation['group_active'] === null ? 'n/a' : $yesNo($observation['group_active']), $observation['group_active'] === null ? 'muted' : ($observation['group_active'] ? 'ok' : 'bad')],
                    ['Safety was clean at issue', $observation['safety_clean'] === null ? 'n/a' : $yesNo($observation['safety_clean']), $observation['safety_clean'] === null ? 'muted' : ($observation['safety_clean'] ? 'ok' : 'warn')],
                ];
            @endphp
            <dl class="grid grid-cols-1 lg:grid-cols-2 gap-x-8 gap-y-1">
                @foreach($permitRows as [$label, $value, $tone])
                    <div class="flex justify-between gap-4 py-1 border-b border-gray-100 dark:border-gray-700">
                        <dt class="text-sm text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="text-sm text-right {{ $toneClass($tone) }}">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        @if($observation['present'] && is_array($observation['baseline_backlogs']) && is_array($observation['peak_backlogs']))
            <x-admin.data-table>
                <x-slot:head>
                    <x-admin.th>Observed stage</x-admin.th>
                    <x-admin.th align="right">Baseline at issue</x-admin.th>
                    <x-admin.th align="right">Peak since issue</x-admin.th>
                    <x-admin.th align="right">Growth</x-admin.th>
                </x-slot:head>
                @foreach($observation['baseline_backlogs'] as $stage => $baseline)
                    @php
                        $peak = (int) ($observation['peak_backlogs'][$stage] ?? 0);
                        $growth = $peak - (int) $baseline;
                    @endphp
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td class="px-6 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $stage }}</td>
                        <td class="px-6 py-3 text-sm text-right text-gray-900 dark:text-gray-100">{{ $count($baseline) }}</td>
                        <td class="px-6 py-3 text-sm text-right text-gray-900 dark:text-gray-100">{{ $count($peak) }}</td>
                        <td class="px-6 py-3 text-sm text-right {{ $toneClass($growth > 0 ? 'warn' : 'default') }}">
                            {{ $growth > 0 ? '+'.$count($growth) : $count($growth) }}
                        </td>
                    </tr>
                @endforeach
            </x-admin.data-table>
        @elseif(! $observation['available'])
            <div class="px-6 py-4 text-sm {{ $toneClass('muted') }} border-t border-gray-200 dark:border-gray-700">
                Permit observation unavailable — the Redis state store could not be read.
            </div>
        @endif
    </x-admin.card>

    {{-- Current-forward --}}
    <x-admin.card>
        <x-admin.page-header title="Current-forward window" icon="fas fa-forward">
            <x-slot:actions>
                @if($currentForward['halted'])
                    <span class="{{ $pill('bad') }}">halted</span>
                @endif
                <span class="{{ $pill($currentForward['claim_in_progress'] ? 'warn' : 'default') }}">
                    {{ $currentForward['claim_in_progress'] ? 'claim in progress' : 'no claim in flight' }}
                </span>
            </x-slot:actions>
        </x-admin.page-header>
        <div class="px-6 py-5">
            @php
                $cfRows = [
                    ['Permit generation', $currentForward['permit_generation'] > 0 ? '#'.$count($currentForward['permit_generation']) : 'none', $currentForward['permit_generation'] > 0 ? 'default' : 'muted'],
                    ['Target group', $currentForward['group'] !== '' ? $currentForward['group'] : 'none', $currentForward['group'] !== '' ? 'default' : 'muted'],
                    ['Claimed generation', $currentForward['claimed_generation'] > 0 ? '#'.$count($currentForward['claimed_generation']) : 'none', $currentForward['claimed_generation'] > 0 ? 'default' : 'muted'],
                    ['Completed generation', $currentForward['completed_generation'] > 0 ? '#'.$count($currentForward['completed_generation']) : 'none', $currentForward['completed_generation'] > 0 ? 'default' : 'muted'],
                    ['Failed generation', $currentForward['failed_generation'] > 0 ? '#'.$count($currentForward['failed_generation']) : 'none', $currentForward['failed_generation'] > 0 ? 'bad' : 'muted'],
                    ['Claim age', $currentForward['claim_age_seconds'] === null ? 'n/a' : $duration($currentForward['claim_age_seconds']), $currentForward['claim_age_seconds'] === null ? 'muted' : (($currentForward['claim_age_seconds'] > $currentForward['claim_timeout_seconds']) ? 'bad' : 'default')],
                    ['Claim timeout', $duration($currentForward['claim_timeout_seconds']), 'default'],
                    ['Quarantined windows (settings)', $count($currentForward['quarantined_windows']), $currentForward['quarantined_windows'] > 0 ? 'warn' : 'default'],
                    ['Refresh discovery enabled', $yesNo($currentForward['refresh_enabled']), 'default'],
                    ['Ledger issuance enabled', $yesNo($currentForward['ledger_issuance_enabled']), 'default'],
                    ['Continuation enabled', $yesNo($currentForward['continuation_enabled']), 'default'],
                ];
            @endphp
            <dl class="grid grid-cols-1 lg:grid-cols-2 gap-x-8 gap-y-1">
                @foreach($cfRows as [$label, $value, $tone])
                    <div class="flex justify-between gap-4 py-1 border-b border-gray-100 dark:border-gray-700">
                        <dt class="text-sm text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="text-sm text-right {{ $toneClass($tone) }}">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        @if($currentForward['windows_available'])
            <div class="px-6 pb-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Ledger windows by lifecycle state</h3>
                <div class="flex flex-wrap gap-2">
                    @foreach($currentForward['windows'] as $windowState => $windowCount)
                        <span class="{{ $pill($windowState === 'QUARANTINED' && $windowCount > 0 ? 'bad' : ($windowCount > 0 ? 'info' : 'default')) }}">
                            {{ strtolower($windowState) }}: {{ $count($windowCount) }}
                        </span>
                    @endforeach
                </div>
            </div>
        @else
            <div class="px-6 pb-5 text-sm {{ $toneClass('muted') }}">
                Ledger window counts unavailable — the additive <code>current_forward_windows</code>
                table is missing or unreadable.
            </div>
        @endif
    </x-admin.card>

    {{-- Per-group yield --}}
    <x-admin.card>
        <x-admin.page-header title="Per-group backfill yield (EWMA)" icon="fas fa-chart-line"
            :subtitle="'Scale threshold '.round($yield['scale_min_yield'], 2).' NZBs/10k · terminal threshold '.round($yield['terminal_min_yield'], 2).' after '.$yield['terminal_min_attempts'].' attempts'" />
        @if(! $yield['available'])
            <div class="px-6 py-8 text-center text-sm {{ $toneClass('muted') }}">
                Yield history unavailable — the Redis state store could not be read.
            </div>
        @else
            <x-admin.data-table>
                <x-slot:head>
                    <x-admin.th>Group</x-admin.th>
                    <x-admin.th align="right">Attempts</x-admin.th>
                    <x-admin.th align="right">EWMA NZBs / 10k</x-admin.th>
                    <x-admin.th align="right">Last cursor delta</x-admin.th>
                    <x-admin.th align="right">Last attempt</x-admin.th>
                    <x-admin.th align="right">Last effective</x-admin.th>
                </x-slot:head>
                @forelse($yield['groups'] as $entry)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td class="px-6 py-3 text-sm text-gray-900 dark:text-gray-100 font-mono">{{ $entry['group'] }}</td>
                        <td class="px-6 py-3 text-sm text-right text-gray-900 dark:text-gray-100">{{ $count($entry['attempts']) }}</td>
                        <td class="px-6 py-3 text-sm text-right {{ $toneClass($entry['ewma_nzbs_per_10k'] < $yield['terminal_min_yield'] ? 'bad' : ($entry['ewma_nzbs_per_10k'] < $yield['scale_min_yield'] ? 'warn' : 'ok')) }}">
                            {{ round($entry['ewma_nzbs_per_10k'], 3) }}
                        </td>
                        <td class="px-6 py-3 text-sm text-right text-gray-900 dark:text-gray-100">{{ $count($entry['last_cursor_delta']) }}</td>
                        <td class="px-6 py-3 text-sm text-right {{ $toneClass($entry['last_attempt_age_seconds'] === null ? 'muted' : 'default') }}">
                            {{ $entry['last_attempt_age_seconds'] === null ? 'never' : $duration($entry['last_attempt_age_seconds']).' ago' }}
                        </td>
                        <td class="px-6 py-3 text-sm text-right {{ $toneClass($entry['last_effective_age_seconds'] === null ? 'bad' : 'default') }}">
                            {{ $entry['last_effective_age_seconds'] === null ? 'never' : $duration($entry['last_effective_age_seconds']).' ago' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                            <i class="fas fa-chart-line text-gray-400 dark:text-gray-500 text-4xl mb-3 block"></i>
                            No backfill yield recorded yet.
                        </td>
                    </tr>
                @endforelse
            </x-admin.data-table>
        @endif
    </x-admin.card>

    {{-- Safety signals --}}
    <x-admin.card>
        <x-admin.page-header title="Database and storage safety" icon="fas fa-database">
            <x-slot:actions>
                @if($stale)
                    <span class="{{ $pill('bad') }}">stale observation</span>
                @endif
            </x-slot:actions>
        </x-admin.page-header>
        <div class="px-6 py-5">
            @if(! $safety['available'])
                <p class="text-sm {{ $toneClass('muted') }}">
                    No controller observation in Redis — database and storage signals are unavailable.
                </p>
            @else
                @php
                    $safetyRows = [
                        ['Database admission safe', $yesNo($safety['admission_safe']), $safety['admission_safe'] ? 'ok' : 'bad'],
                        ['Row-lock admission blocked', $yesNo($safety['admission_blocked']), $safety['admission_blocked'] ? 'bad' : 'ok'],
                        ['Row-lock waits (cumulative)', $count($safety['row_lock_waits']), 'default'],
                        ['Row-lock delta since last observation', $count($safety['row_lock_delta']), $safety['row_lock_delta'] > 0 ? 'warn' : 'default'],
                        ['Row-lock rate per minute', (string) round($safety['row_lock_rate_per_minute'], 2), 'default'],
                        ['Last hard breach', $safety['hard_breach_at'] > 0 ? $stamp($safety['hard_breach_at']) : 'none', $safety['hard_breach_at'] > 0 ? 'bad' : 'muted'],
                        ['Storage available', $bytes($safety['storage_available_bytes']), $safety['storage_available_bytes'] === null ? 'muted' : 'default'],
                        ['Eligible NZBs in the selector frontier', $count($safety['eligible_nzbs']), 'default'],
                        ['Body-recovery queue', $count($safety['body_recovery_queue']), 'default'],
                    ];
                @endphp
                <dl class="grid grid-cols-1 lg:grid-cols-2 gap-x-8 gap-y-1">
                    @foreach($safetyRows as [$label, $value, $tone])
                        <div class="flex justify-between gap-4 py-1 border-b border-gray-100 dark:border-gray-700">
                            <dt class="text-sm text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="text-sm text-right {{ $toneClass($tone) }}">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </div>
    </x-admin.card>

    {{-- Distributed worker lanes --}}
    <x-admin.card>
        <x-admin.page-header title="Distributed worker lanes" icon="fas fa-diagram-project"
            subtitle="Best-effort Redis liveness. An in-progress marker older than the lane's lock lifetime is a leaked marker, not a running cycle." />
        @if(! $lanes['available'])
            <div class="px-6 py-8 text-center text-sm {{ $toneClass('muted') }}">
                Worker telemetry unavailable — the Redis telemetry store could not be read.
                Lane liveness is unknown, not idle.
            </div>
        @else
            <x-admin.data-table>
                <x-slot:head>
                    <x-admin.th>Lane</x-admin.th>
                    <x-admin.th>State</x-admin.th>
                    <x-admin.th align="right">Running for</x-admin.th>
                    <x-admin.th align="right">Last cycle</x-admin.th>
                    <x-admin.th align="right">Last duration</x-admin.th>
                    <x-admin.th align="right">Last success</x-admin.th>
                    <x-admin.th align="right">Runs (ok / fail / contended)</x-admin.th>
                </x-slot:head>
                @foreach($lanes['lanes'] as $lane)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td class="px-6 py-3 text-sm text-gray-900 dark:text-gray-100">
                            <span class="font-mono">{{ $lane['worker'] }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $lane['description'] }}</span>
                        </td>
                        <td class="px-6 py-3 text-sm">
                            @if($lane['stale_marker'])
                                <span class="{{ $pill('bad') }}">stale marker</span>
                            @elseif($lane['in_progress'])
                                <span class="{{ $pill('ok') }}">running</span>
                            @elseif(! $lane['observed'])
                                <span class="{{ $pill('default') }}">never seen</span>
                            @else
                                <span class="{{ $pill('default') }}">idle</span>
                            @endif
                        </td>
                        <td class="px-6 py-3 text-sm text-right {{ $toneClass($lane['in_progress_age_seconds'] === null ? 'muted' : 'default') }}">
                            {{ $lane['in_progress_age_seconds'] === null ? '—' : $duration($lane['in_progress_age_seconds']) }}
                        </td>
                        <td class="px-6 py-3 text-sm text-right {{ $toneClass($lane['last_completed_age_seconds'] === null ? 'muted' : 'default') }}">
                            {{ $lane['last_completed_age_seconds'] === null ? 'never' : $duration($lane['last_completed_age_seconds']).' ago' }}
                        </td>
                        <td class="px-6 py-3 text-sm text-right text-gray-900 dark:text-gray-100">
                            {{ $duration($lane['last_duration_seconds']) }}
                        </td>
                        <td class="px-6 py-3 text-sm text-right {{ $toneClass($lane['last_success_age_seconds'] === null ? 'bad' : 'default') }}">
                            {{ $lane['last_success_age_seconds'] === null ? 'never' : $duration($lane['last_success_age_seconds']).' ago' }}
                        </td>
                        <td class="px-6 py-3 text-sm text-right text-gray-900 dark:text-gray-100">
                            {{ $count($lane['runs']['success'] ?? 0) }}
                            / {{ $count(($lane['runs']['failure'] ?? 0) + ($lane['runs']['lock_error'] ?? 0) + ($lane['runs']['terminated'] ?? 0)) }}
                            / {{ $count($lane['runs']['lock_contended'] ?? 0) }}
                        </td>
                    </tr>
                @endforeach
            </x-admin.data-table>
            <div class="px-6 py-4 text-xs text-gray-500 dark:text-gray-400 border-t border-gray-200 dark:border-gray-700">
                Run counters reset whenever the Redis telemetry cache is replaced, so treat them as
                relative rather than lifetime totals.
            </div>
        @endif
    </x-admin.card>
</div>
@endsection
