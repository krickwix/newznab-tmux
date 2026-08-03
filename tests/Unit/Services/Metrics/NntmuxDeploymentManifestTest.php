<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Metrics;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Parser;

final class NntmuxDeploymentManifestTest extends TestCase
{
    /**
     * The single image every app lane runs, pinned by manifest list digest so a
     * per-arch digest cannot be substituted on this mixed arm64/amd64 cluster.
     * nntmux-web is excluded: it keeps its own amd64 imdb-identity lineage.
     */
    private const FLEET_IMAGE = 'microservices-pods-20260803-brace-token-residue-v218@sha256:5c2ea675a8419355437f7ecdb25b91a9fc369ea1350a0caf79bda47ad74eee5c';

    public function test_worker_orchestrator_overlay_packages_the_backfill_source_activation_command(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/worker-orchestrator.Dockerfile');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'COPY app/Console/Commands/ActivateBackfillSource.php /app/app/Console/Commands/ActivateBackfillSource.php',
            $dockerfile,
        );
    }

    public function test_release_overlay_packages_the_stage6_coverage_predicate(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/release-stage6-coverage.Dockerfile');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260713-raw-context-v112@sha256:1b6b8f67ccf8069de9352a49e794f5c3f7acddcda90418b69ebf7ebc98338c84',
            $dockerfile,
        );
        self::assertStringContainsString(
            'COPY app/Services/ReleaseProcessingService.php /app/app/Services/ReleaseProcessingService.php',
            $dockerfile,
        );
    }

    public function test_nzb_saturated_retry_overlay_packages_the_fresh_control_loop(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/nzb-saturated-retry.Dockerfile');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260714-nzb-completion-v143@sha256:3f5ccef8d0c088dac9d07c4ae01426ce8aa4d109858bb91075e672ae61665e0b',
            $dockerfile,
        );
        self::assertStringContainsString(
            'COPY app/Services/Distributed/DistributedJobWorker.php /app/app/Services/Distributed/DistributedJobWorker.php',
            $dockerfile,
        );
        self::assertStringContainsString(
            'COPY app/Services/Tmux/TmuxMonitorService.php /app/app/Services/Tmux/TmuxMonitorService.php',
            $dockerfile,
        );
    }

    public function test_current_forward_overlay_packages_the_matching_header_parser(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/current-forward-v154.Dockerfile');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260714-current-forward-v153@sha256:5b1970082c6702bb96f146adf238e224efdb101dda3fbffdcc22a9e03d970767',
            $dockerfile,
        );
        self::assertStringContainsString(
            'COPY app/Services/Binaries/HeaderParser.php /app/app/Services/Binaries/HeaderParser.php',
            $dockerfile,
        );
    }

    public function test_current_forward_recovery_overlay_packages_the_fenced_permit_gate(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/current-forward-v155.Dockerfile');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260714-current-forward-v154@sha256:81ebf199e79c6c064e6663695939cde48313f0fa04e1c8be38fb963c7053a84d',
            $dockerfile,
        );
        self::assertStringContainsString(
            'COPY app/Services/Distributed/CurrentForwardPermitGate.php /app/app/Services/Distributed/CurrentForwardPermitGate.php',
            $dockerfile,
        );
    }

    public function test_current_forward_binaries_overlay_packages_the_matching_missed_part_handler(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/current-forward-v156.Dockerfile');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260714-current-forward-v155@sha256:ac170cbb8eeb1e6c5eb2e3166c38c1fb1888a4cfa373842bd40e543b98edda0a',
            $dockerfile,
        );
        self::assertStringContainsString(
            'COPY app/Services/Binaries/MissedPartHandler.php /app/app/Services/Binaries/MissedPartHandler.php',
            $dockerfile,
        );
    }

    public function test_contention_overlay_packages_the_complete_controller_contract(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/current-forward-v157.Dockerfile');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260714-current-forward-v156@sha256:53bc17e26ba3f9ac66665999fcff6de7fbebf017b9741eaf9e18c9ab8b32296d',
            $dockerfile,
        );
        foreach ([
            'app/Services/Metrics/NntmuxPrometheusMetrics.php',
            'app/Services/Orchestrator/PipelineSnapshot.php',
            'app/Services/Orchestrator/PipelineSnapshotRepository.php',
            'app/Services/Orchestrator/WorkerControlStateStore.php',
            'app/Services/Orchestrator/WorkerOrchestrator.php',
            'app/Services/Distributed/CurrentForwardPermitGate.php',
            'config/nntmux.php',
        ] as $path) {
            self::assertStringContainsString("COPY {$path} /app/{$path}", $dockerfile);
        }
    }

    public function test_contention_metrics_overlay_preserves_the_metrics_runtime_base(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/row-lock-metrics-v158.Dockerfile');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260713-backfill-source-v97@sha256:653f3982d2a35cf05981916a167d10cc1a231e6d4aedd6eeef1e5bab6257363f',
            $dockerfile,
        );
        self::assertStringContainsString(
            'COPY app/Services/Metrics/NntmuxPrometheusMetrics.php /app/app/Services/Metrics/NntmuxPrometheusMetrics.php',
            $dockerfile,
        );
    }

    public function test_autonomous_current_forward_overlay_packages_the_ranked_corridor_contract(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/current-forward-v160.Dockerfile');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260714-admission-settle-v159@sha256:bf5a60955fe65d7a692619ae75094d3ab33aba0e107becff69a8917796724414',
            $dockerfile,
        );
        foreach ([
            'app/Services/Orchestrator/CurrentForwardStopCursorPolicy.php',
            'app/Services/Distributed/CurrentForwardPermitGate.php',
            'app/Services/Distributed/DistributedJobWorker.php',
            'app/Services/Orchestrator/WorkerOrchestrator.php',
            'app/Console/Commands/GetArticleRange.php',
            'config/nntmux.php',
        ] as $path) {
            self::assertStringContainsString("COPY {$path} /app/{$path}", $dockerfile);
        }
    }

    public function test_provider_reserve_overlay_packages_every_runtime_gate_and_the_live_migration(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/cf-provider-reserve-v180.Dockerfile');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-release-disposition-v179@sha256:a68a20906d9ff1481a9784464c60669eee247b36cf2b07150cf11fa18b245ef6',
            $dockerfile,
        );
        foreach ([
            'app/Services/Distributed/CurrentForwardPermitGate.php',
            'app/Services/Orchestrator/CurrentForwardProviderCoverage.php',
            'app/Services/Orchestrator/CurrentForwardRefreshAuditor.php',
            'app/Services/Orchestrator/CurrentForwardRefreshLedger.php',
            'app/Services/Orchestrator/CurrentForwardRefreshPlanner.php',
            'config/nntmux.php',
            'database/migrations/2026_07_18_074500_relax_current_forward_provider_reserve_floor.php',
        ] as $path) {
            self::assertStringContainsString("COPY {$path} /app/{$path}", $dockerfile);
        }
    }

    public function test_collection_handoff_overlays_package_the_lineage_merge_contract(): void
    {
        foreach ([
            'cf-collection-handoff-v181.Dockerfile',
            'cf-collection-handoff-release-v181.Dockerfile',
        ] as $filename) {
            $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/'.$filename);

            self::assertIsString($dockerfile);
            foreach ([
                'app/Services/Orchestrator/CurrentForwardWindowLineage.php',
                'app/Services/Releases/SplitCollectionReconciler.php',
                'database/migrations/2026_07_18_081500_add_current_forward_collection_handoffs.php',
            ] as $path) {
                self::assertStringContainsString("COPY {$path} /app/{$path}", $dockerfile);
            }
        }
    }

    public function test_split_backlog_release_overlay_packages_fair_discovery_and_lineage_guards(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/cf-split-backlog-release-v182.Dockerfile');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-collection-handoff-release-v181@sha256:8b6bae391cd799b6a188be9b31df906ebbc9b7f531b3c60c8335095963ee8c6c',
            $dockerfile,
        );
        foreach ([
            'app/Services/Releases/SplitCollectionReconciler.php',
            'app/Services/Orchestrator/CurrentForwardWindowLineage.php',
            'config/nntmux.php',
        ] as $path) {
            self::assertStringContainsString("COPY {$path} /app/{$path}", $dockerfile);
        }
    }

    public function test_dynamic_pair_shadow_overlays_package_the_rule_and_telemetry(): void
    {
        $main = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/cf-dynamic-pair-shadow-v183.Dockerfile');
        $release = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/cf-dynamic-pair-shadow-release-v183.Dockerfile');

        self::assertIsString($main);
        self::assertIsString($release);
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-collection-handoff-v181@sha256:30740c6a253c778ce5796b448e4721279738a07a6e7b1642f79df26240c8d965',
            $main,
        );
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-split-backlog-release-v182@sha256:cc31f60143352c75eede4cc9225fa720c1a77a7bf447ec41bc89232cc142f220',
            $release,
        );
        foreach ([$main, $release] as $dockerfile) {
            self::assertStringContainsString(
                'COPY app/Services/Metrics/SplitCollectionTelemetry.php /app/app/Services/Metrics/SplitCollectionTelemetry.php',
                $dockerfile,
            );
            self::assertStringContainsString('COPY config/nntmux.php /app/config/nntmux.php', $dockerfile);
        }
        self::assertStringContainsString(
            'COPY app/Services/Metrics/NntmuxPrometheusMetrics.php /app/app/Services/Metrics/NntmuxPrometheusMetrics.php',
            $main,
        );
        self::assertStringContainsString(
            'COPY app/Services/Releases/SplitCollectionReconciler.php /app/app/Services/Releases/SplitCollectionReconciler.php',
            $release,
        );
    }

    public function test_qualified_supply_overlay_packages_the_complete_controller_contract(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/qualified-supply-v187.Dockerfile');

        self::assertIsString($dockerfile);
        foreach ([
            'app/Console/Commands/AuditCurrentForwardRefresh.php',
            'app/Services/Metrics/NntmuxPrometheusMetrics.php',
            'app/Services/Orchestrator/AdaptiveWorkerControlPlanner.php',
            'app/Services/Orchestrator/ControlState.php',
            'app/Services/Orchestrator/CurrentForwardRefreshLedger.php',
            'app/Services/Orchestrator/PipelineSnapshot.php',
            'app/Services/Orchestrator/PipelineSnapshotRepository.php',
            'app/Services/Orchestrator/WorkerControlPolicy.php',
            'app/Services/Orchestrator/WorkerControlStateStore.php',
            'app/Services/Orchestrator/WorkerOrchestrator.php',
            'config/nntmux.php',
        ] as $path) {
            self::assertStringContainsString("COPY {$path} /app/{$path}", $dockerfile);
        }
    }

    public function test_dynamic_pair_hdtv_overlay_promotes_the_reviewed_shadow_image_unchanged(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/cf-dynamic-pair-hdtv-release-v184.Dockerfile');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-dynamic-pair-shadow-release-v183@sha256:aec282800a761450edb313ea11287766fc05b91239f32b357d9ed4cfcb152231',
            $dockerfile,
        );
    }

    public function test_terminal_pair_hdtv_overlay_packages_guarded_repair_and_deferred_search_sync(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/cf-terminal-pair-hdtv-release-v186.Dockerfile');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-dynamic-pair-shadow-release-v183@sha256:aec282800a761450edb313ea11287766fc05b91239f32b357d9ed4cfcb152231',
            $dockerfile,
        );
        foreach ([
            'app/Services/Orchestrator/CurrentForwardTerminalSplitRepair.php',
            'app/Services/Releases/SplitCollectionReconciler.php',
            'app/Services/ReleaseCreationService.php',
            'app/Models/Release.php',
            'database/migrations/2026_07_18_094500_add_current_forward_terminal_split_repairs.php',
            'config/nntmux.php',
        ] as $path) {
            self::assertStringContainsString("COPY {$path} /app/{$path}", $dockerfile);
        }
    }

    public function test_terminal_pair_migration_uses_the_pinned_image_with_all_repair_flags_disabled(): void
    {
        $manifest = file_get_contents(
            dirname(__DIR__, 5).'/mediahome/manifests/media/nntmux/current-forward-terminal-pair-migration-v186.yaml',
        );

        self::assertIsString($manifest);
        self::assertStringContainsString(
            'docker.io/krickwix/nntmux:microservices-pods-20260718-cf-terminal-pair-hdtv-release-v186@sha256:adb8d04376221749d054ba176b6e52fdfbc066319766c18eb24f710e76546988',
            $manifest,
        );
        foreach ([
            'NNTMUX_SPLIT_COLLECTION_DYNAMIC_PAIR_GAP_GROUPS',
            'NNTMUX_SPLIT_COLLECTION_TERMINAL_PAIR_REPAIR_GROUPS',
            'NNTMUX_SPLIT_COLLECTION_TERMINAL_PAIR_REPAIR_ROOTS',
        ] as $flag) {
            self::assertStringContainsString("- name: {$flag}\n              value: \"\"", $manifest);
        }
    }

    public function test_current_forward_metrics_overlay_preserves_the_metrics_runtime_base(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/current-forward-metrics-v161.Dockerfile');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260714-row-lock-metrics-v158@sha256:f27ee85998da04a98393c95c82d4f79fb47423e1a6a12005ca2a41670a712ff5',
            $dockerfile,
        );
        self::assertStringContainsString(
            'COPY app/Services/Metrics/NntmuxPrometheusMetrics.php /app/app/Services/Metrics/NntmuxPrometheusMetrics.php',
            $dockerfile,
        );
    }

    public function test_admission_settlement_overlay_packages_staleness_and_profile_guards(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/row-lock-v159.Dockerfile');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260714-row-lock-v157@sha256:4fc77278733238ae06c598489fb8743098b2f9cdc0429176db1bb1f4aa2435ab',
            $dockerfile,
        );
        foreach ([
            'app/Services/Orchestrator/PipelineSnapshotRepository.php',
            'app/Services/Orchestrator/WorkerControlPolicy.php',
            'app/Services/Orchestrator/WorkerOrchestrator.php',
        ] as $path) {
            self::assertStringContainsString("COPY {$path} /app/{$path}", $dockerfile);
        }
    }

    public function test_brace_token_residue_overlay_packages_the_per_file_key_and_the_repair_pass(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 4).'/docker/overlays/brace-token-residue-v218.Dockerfile');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'FROM docker.io/krickwix/nntmux:microservices-pods-20260803-obfuscated-config-regression-v217@sha256:f3825e86776d60952d5db28c98b87d3e9fe0ef5ceaa1258b4bf598eaa41f1494',
            $dockerfile,
        );
        // All three ingest files ship together or not at all: HeaderParser sets
        // the brace-token flag that CollectionHandler consumes to call the
        // normalizer's static key. Shipping any one alone is a silent no-op.
        foreach ([
            'app/Services/Binaries/ObfuscatedSubjectNormalizer.php',
            'app/Services/Binaries/HeaderParser.php',
            'app/Services/Binaries/CollectionHandler.php',
            'app/Services/Diagnostics/BraceTokenIdentityRepairService.php',
            'app/Console/Commands/RepairBraceTokenIdentity.php',
            // Unrelated to brace-token keying, but the fleet cannot process any
            // reclaimed collection while the orchestrator crashes into
            // failClosed() every cycle, so the guard ships in the same image.
            'app/Services/Orchestrator/BackfillTargetSelector.php',
        ] as $path) {
            self::assertStringContainsString("COPY {$path} /app/{$path}", $dockerfile);
        }
    }

    public function test_brace_token_overlay_does_not_copy_config_over_the_repaired_keys(): void
    {
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 4).'/docker/overlays/brace-token-residue-v218.Dockerfile');

        // v214/v215 shipped a config/nntmux.php from a branch that lacked the
        // obfuscated_* keys, which silently NULLed them out and made the
        // normalizers permanent no-ops. This overlay changes no config, so it
        // must not carry that COPY at all.
        self::assertStringNotContainsString('config/nntmux.php', $dockerfile);
    }

    public function test_lossless_body_preamble_repair_is_explicitly_enabled(): void
    {
        $path = dirname(__DIR__, 5).'/mediahome/manifests/media/nntmux/infra.yaml';
        if (! is_file($path)) {
            self::markTestSkipped('mediahome sibling checkout is required for the workspace manifest regression.');
        }

        $documents = preg_split('/^---\s*$/m', (string) file_get_contents($path));
        $configMap = (new Parser)->parse((string) ($documents[0] ?? ''));
        self::assertIsArray($configMap);
        $groups = explode(',', (string) ($configMap['data']['NNTMUX_BODY_PREAMBLE_DEOBFUSCATE_GROUPS'] ?? ''));

        self::assertContains('alt.binaries.lossless', $groups);
        self::assertSame('1000', $configMap['data']['NNTMUX_BODY_PREAMBLE_DEOBFUSCATE_LIMIT'] ?? null);
        self::assertSame('60', $configMap['data']['NNTMUX_BODY_PREAMBLE_DEOBFUSCATE_MAX_SECONDS'] ?? null);
    }

    public function test_orchestrated_workers_keep_isolated_images_and_backfill_ready(): void
    {
        $path = dirname(__DIR__, 5).'/mediahome/manifests/media/nntmux/distributed-workers.yaml';
        if (! is_file($path)) {
            self::markTestSkipped('mediahome sibling checkout is required for the workspace manifest regression.');
        }

        $manifest = (new Parser(maxAliasesForCollections: 1000))->parse((string) file_get_contents($path));
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['items'] ?? null);

        $workers = [];
        $backfill = null;
        $bodyRecovery = null;
        $bodyRecoveryWorker = null;
        $providerRangeRefresh = null;
        $currentForwardRefreshAudit = null;
        $orchestrator = null;
        foreach ($manifest['items'] as $deployment) {
            if (is_array($deployment)
                && ($deployment['kind'] ?? null) === 'CronJob'
                && ($deployment['metadata']['name'] ?? null) === 'nntmux-provider-range-refresh'
            ) {
                $providerRangeRefresh = $deployment;
            }
            if (is_array($deployment)
                && ($deployment['kind'] ?? null) === 'CronJob'
                && ($deployment['metadata']['name'] ?? null) === 'nntmux-current-forward-refresh-audit'
            ) {
                $currentForwardRefreshAudit = $deployment;
            }
            if (is_array($deployment)
                && ($deployment['kind'] ?? null) === 'CronJob'
                && ($deployment['metadata']['name'] ?? null) === 'nntmux-body-recovery'
            ) {
                $bodyRecovery = $deployment;
            }
            if (! is_array($deployment) || ($deployment['kind'] ?? null) !== 'Deployment') {
                continue;
            }

            if (($deployment['metadata']['name'] ?? null) === 'nntmux-body-recovery-worker') {
                $bodyRecoveryWorker = $deployment;
            }
            if (($deployment['metadata']['name'] ?? null) === 'nntmux-worker-orchestrator') {
                $orchestrator = $deployment;
            }

            if (($deployment['metadata']['name'] ?? null) === 'nntmux-worker-backfill') {
                $backfill = $deployment;
            }
            $container = $deployment['spec']['template']['spec']['containers'][0] ?? null;
            if (! is_array($container) || ! in_array('nntmux:worker', $container['args'] ?? [], true)) {
                continue;
            }

            $name = (string) ($deployment['metadata']['name'] ?? 'unknown');
            $workers[] = $name;

            // The lanes no longer run per-lane isolated images. Each fix used to
            // ship to only the lanes that executed it, which is what the long
            // per-lane table here recorded; the fleet has since converged on one
            // overlay, because every image in that lineage is a strict superset
            // of the one below it. One expected pin is now the honest assertion
            // -- and a stronger one, since it also catches a lane left behind.
            $expectedImage = ':'.self::FLEET_IMAGE;
            self::assertStringEndsWith(
                $expectedImage,
                (string) ($container['image'] ?? ''),
                sprintf('%s must run the converged fleet image.', $name),
            );
            $environment = array_column($container['env'] ?? [], 'value', 'name');
            self::assertSame(
                explode('@', ltrim($expectedImage, ':'), 2)[0],
                $environment['NNTMUX_BUILD_VERSION'] ?? null,
                sprintf('%s build telemetry must match its image.', $name),
            );
            if ($name === 'nntmux-worker-post-movies') {
                self::assertSame('false', $environment['IMDBAPI_DEV_ENABLED'] ?? null);
                $mountPaths = array_column($container['volumeMounts'] ?? [], 'mountPath');
                self::assertNotContains('/app/app/Services/MovieService.php', $mountPaths);
                self::assertNotContains('/app/app/Services/Runners/PostProcessRunner.php', $mountPaths);
            }
            if (in_array($name, [
                'nntmux-worker-binaries',
                'nntmux-worker-current-forward',
                'nntmux-worker-backfill',
                'nntmux-worker-releases',
            ], true)) {
                self::assertSame(
                    'true',
                    $environment['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_CONTINUATION_ENABLED'] ?? null,
                    sprintf('%s must share the bounded continuation canary flag.', $name),
                );
            }
            if ($name === 'nntmux-worker-current-forward') {
                self::assertSame(
                    '19000',
                    $environment['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_PROVIDER_RESERVE'] ?? null,
                );
            }
        }

        self::assertNotEmpty($workers);
        self::assertIsArray($providerRangeRefresh);
        self::assertSame('*/5 * * * *', $providerRangeRefresh['spec']['schedule'] ?? null);
        self::assertSame('Forbid', $providerRangeRefresh['spec']['concurrencyPolicy'] ?? null);
        self::assertSame(
            ['php', 'artisan', 'groups:update'],
            $providerRangeRefresh['spec']['jobTemplate']['spec']['template']['spec']['containers'][0]['args'] ?? null,
        );
        self::assertStringEndsWith(
            ':microservices-pods-20260713-provider-range-v93',
            (string) ($providerRangeRefresh['spec']['jobTemplate']['spec']['template']['spec']['containers'][0]['image'] ?? ''),
        );
        self::assertIsArray($currentForwardRefreshAudit);
        self::assertSame('*/5 * * * *', $currentForwardRefreshAudit['spec']['schedule'] ?? null);
        self::assertSame('Forbid', $currentForwardRefreshAudit['spec']['concurrencyPolicy'] ?? null);
        $refreshContainer = $currentForwardRefreshAudit['spec']['jobTemplate']['spec']['template']['spec']['containers'][0] ?? [];
        self::assertSame(
            ['php', 'artisan', 'orchestrator:audit-current-forward', '--record', '--json'],
            $refreshContainer['args'] ?? null,
        );
        self::assertMatchesRegularExpression(
            '/:microservices-pods-20260720-sustainable-backfill-v188@sha256:9162bcefa4e5e0f7b6c0690d1e05c26ede86e938900dd2f8efda36dd20468a0a$/',
            (string) ($refreshContainer['image'] ?? ''),
        );
        $refreshEnvironment = array_column($refreshContainer['env'] ?? [], 'value', 'name');
        self::assertSame(
            'microservices-pods-20260720-sustainable-backfill-v188',
            $refreshEnvironment['NNTMUX_BUILD_VERSION'] ?? null,
        );
        self::assertSame('true', $refreshEnvironment['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_REFRESH_ENABLED'] ?? null);
        self::assertSame('true', $refreshEnvironment['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_CONTINUATION_ENABLED'] ?? null);
        self::assertSame('19000', $refreshEnvironment['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_PROVIDER_RESERVE'] ?? null);
        self::assertSame(
            'alt.binaries.teevee:3590755586-3590765585,alt.binaries.tvseries:948898922-948908921',
            $refreshEnvironment['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_REFRESH_SOURCES'] ?? null,
        );
        self::assertArrayNotHasKey('NNTMUX_ORCHESTRATOR_AUTO_CURRENT_FORWARD', $refreshEnvironment);
        self::assertArrayNotHasKey('NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_PERMIT', $refreshEnvironment);
        self::assertIsArray($backfill);
        self::assertSame(1, $backfill['spec']['replicas'] ?? null);
        self::assertIsArray($orchestrator);
        $orchestratorEnv = array_column(
            $orchestrator['spec']['template']['spec']['containers'][0]['env'] ?? [],
            'value',
            'name',
        );
        self::assertSame('alt.binaries.lossless', $orchestratorEnv['NNTMUX_ORCHESTRATOR_BODY_RECOVERY_SOURCE_GROUPS'] ?? null);
        self::assertSame('80000', $orchestratorEnv['NNTMUX_ORCHESTRATOR_COLLECTIONS_TOTAL_HIGH'] ?? null);
        self::assertSame('60000', $orchestratorEnv['NNTMUX_ORCHESTRATOR_RECOVERY_SOURCES_HIGH'] ?? null);
        self::assertSame('60000', $orchestratorEnv['NNTMUX_ORCHESTRATOR_COLLECTIONS_TOTAL_LOW'] ?? null);
        self::assertSame('50000', $orchestratorEnv['NNTMUX_ORCHESTRATOR_RECOVERY_SOURCES_LOW'] ?? null);
        self::assertSame('60', $orchestratorEnv['NNTMUX_ORCHESTRATOR_DB_ROW_LOCK_WINDOW_SECONDS'] ?? null);
        self::assertSame('4.0', $orchestratorEnv['NNTMUX_ORCHESTRATOR_DB_ROW_LOCK_ADMISSION_BLOCK_RATE'] ?? null);
        self::assertSame('3.0', $orchestratorEnv['NNTMUX_ORCHESTRATOR_DB_ROW_LOCK_ADMISSION_REOPEN_RATE'] ?? null);
        self::assertSame('6.0', $orchestratorEnv['NNTMUX_ORCHESTRATOR_DB_ROW_LOCK_HARD_RATE'] ?? null);
        self::assertSame('12', $orchestratorEnv['NNTMUX_ORCHESTRATOR_DB_ROW_LOCK_BURST_WAITS'] ?? null);
        self::assertSame('60', $orchestratorEnv['NNTMUX_ORCHESTRATOR_DB_ROW_LOCK_BURST_SECONDS'] ?? null);
        self::assertSame('30.0', $orchestratorEnv['NNTMUX_ORCHESTRATOR_DB_ROW_LOCK_INSTANT_HARD_RATE'] ?? null);
        self::assertSame('600', $orchestratorEnv['NNTMUX_ORCHESTRATOR_DB_ROW_LOCK_HARD_COOLDOWN_SECONDS'] ?? null);
        self::assertSame('30', $orchestratorEnv['NNTMUX_ORCHESTRATOR_DB_CURRENT_WAIT_HARD_SECONDS'] ?? null);
        self::assertSame('120', $orchestratorEnv['NNTMUX_ORCHESTRATOR_DB_PROFILE_STABLE_SECONDS'] ?? null);
        self::assertSame('19000', $orchestratorEnv['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_PROVIDER_RESERVE'] ?? null);
        self::assertSame('3', $orchestratorEnv['NNTMUX_ORCHESTRATOR_BACKFILL_TERMINAL_MIN_ATTEMPTS'] ?? null);
        self::assertSame('1.0', $orchestratorEnv['NNTMUX_ORCHESTRATOR_BACKFILL_TERMINAL_MIN_YIELD'] ?? null);
        self::assertIsArray($bodyRecovery);
        self::assertSame('*/5 * * * *', $bodyRecovery['spec']['schedule'] ?? null);
        self::assertTrue($bodyRecovery['spec']['suspend'] ?? null);
        self::assertSame('Forbid', $bodyRecovery['spec']['concurrencyPolicy'] ?? null);
        self::assertStringEndsWith(
            ':microservices-pods-20260712-recovery-fairness-v76',
            (string) ($bodyRecovery['spec']['jobTemplate']['spec']['template']['spec']['containers'][0]['image'] ?? ''),
        );
        $recoveryArgs = $bodyRecovery['spec']['jobTemplate']['spec']['template']['spec']['containers'][0]['args'] ?? [];
        self::assertNotContains('--regex=-20', $recoveryArgs);
        self::assertNotContains('--max-current-parts=2', $recoveryArgs);
        self::assertNotContains('--min-total-parts=10', $recoveryArgs);
        self::assertNotContains('--cutoff-hours=2', $recoveryArgs);
        self::assertIsArray($bodyRecoveryWorker);
        self::assertSame(0, $bodyRecoveryWorker['spec']['replicas'] ?? null);
        $bodyContainer = $bodyRecoveryWorker['spec']['template']['spec']['containers'][0] ?? [];
        self::assertStringEndsWith(
            ':microservices-pods-20260712-recovery-fairness-v76',
            (string) ($bodyContainer['image'] ?? ''),
        );
        self::assertSame([
            'php',
            'artisan',
            'nntmux:body-preamble-recovery-worker',
            'alt.binaries.lossless',
            '--batch=75',
            '--lease-seconds=180',
            '--idle-sleep=20',
        ], $bodyContainer['args'] ?? null);
    }

    public function test_web_uses_sustainable_backfill_overlay_without_changing_scheduler_or_legacy_worker(): void
    {
        $path = dirname(__DIR__, 5).'/mediahome/manifests/media/nntmux/app.yaml';
        if (! is_file($path)) {
            self::markTestSkipped('mediahome sibling checkout is required for the workspace manifest regression.');
        }

        $parser = new Parser(maxAliasesForCollections: 1000);
        $documents = preg_split('/^---\s*$/m', (string) file_get_contents($path));
        self::assertIsArray($documents);

        $deployments = [];
        foreach ($documents as $document) {
            $resource = $parser->parse($document);
            if (is_array($resource) && ($resource['kind'] ?? null) === 'Deployment') {
                $deployments[(string) ($resource['metadata']['name'] ?? '')] = $resource;
            }
        }

        self::assertStringEndsWith(
            ':microservices-pods-20260721-imdb-identity-web-amd64-v13@sha256:602c341b665631ae4e03f0acf544d11d80fa05acd9cb2ee9a32e614651550b08',
            (string) ($deployments['nntmux-web']['spec']['template']['spec']['containers'][0]['image'] ?? ''),
        );
        $webEnvironment = array_column(
            $deployments['nntmux-web']['spec']['template']['spec']['containers'][0]['env'] ?? [],
            'value',
            'name',
        );
        self::assertSame('false', $webEnvironment['IMDBAPI_DEV_ENABLED'] ?? null);
        // These two are no longer held back on an old pin: they run the
        // converged fleet image with every other app lane. Web is the one
        // deployment that stays on its own amd64 lineage above.
        foreach (['nntmux-worker', 'nntmux-scheduler'] as $deployment) {
            self::assertStringEndsWith(
                ':'.self::FLEET_IMAGE,
                (string) ($deployments[$deployment]['spec']['template']['spec']['containers'][0]['image'] ?? ''),
            );
        }
    }

    public function test_active_orchestrator_preserves_nzb_throughput_contract_and_backfill_zero(): void
    {
        $manifestRoot = dirname(__DIR__, 5).'/mediahome/manifests/media/nntmux';
        $workersPath = $manifestRoot.'/distributed-workers.yaml';
        $monitoringPath = $manifestRoot.'/monitoring.yaml';
        if (! is_file($workersPath) || ! is_file($monitoringPath)) {
            self::markTestSkipped('mediahome sibling checkout is required for the workspace manifest regression.');
        }

        $parser = new Parser(maxAliasesForCollections: 1000);
        $workersManifest = $parser->parse((string) file_get_contents($workersPath));
        self::assertIsArray($workersManifest);
        self::assertIsArray($workersManifest['items'] ?? null);

        $deployments = [];
        foreach ($workersManifest['items'] as $deployment) {
            if (is_array($deployment) && ($deployment['kind'] ?? null) === 'Deployment') {
                $deployments[(string) ($deployment['metadata']['name'] ?? '')] = $deployment;
            }
        }

        $monitoringDocuments = preg_split('/^---\s*$/m', (string) file_get_contents($monitoringPath));
        self::assertIsArray($monitoringDocuments);
        $metrics = $parser->parse($monitoringDocuments[0]);
        self::assertIsArray($metrics);
        self::assertSame('nntmux-metrics', $metrics['metadata']['name'] ?? null);

        $prometheusRule = null;
        $dashboardConfig = null;
        foreach ($monitoringDocuments as $document) {
            $resource = $parser->parse($document);
            if (is_array($resource) && ($resource['kind'] ?? null) === 'ConfigMap'
                && ($resource['metadata']['name'] ?? null) === 'nntmux-grafana-dashboard') {
                $dashboardConfig = $resource;
            }
            if (is_array($resource) && ($resource['kind'] ?? null) === 'PrometheusRule') {
                $prometheusRule = $resource;
                break;
            }
        }
        self::assertIsArray($dashboardConfig);
        $dashboard = json_decode(
            (string) ($dashboardConfig['data']['nntmux-overview.json'] ?? ''),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($dashboard);
        $dashboardExpressions = [];
        foreach ($dashboard['panels'] ?? [] as $panel) {
            foreach ($panel['targets'] ?? [] as $target) {
                if (is_string($target['expr'] ?? null)) {
                    $dashboardExpressions[] = $target['expr'];
                }
            }
        }
        self::assertContains(
            'increase(nntmux_worker_items_total{worker="releases",item="release",result="created"}[1h])',
            array_map(
                static fn (string $expression): string => preg_replace('/^sum\((.*)\)$/', '$1', $expression) ?? $expression,
                $dashboardExpressions,
            ),
        );
        self::assertContains('nntmux_orchestrator_release_yield_per_minute', $dashboardExpressions);
        // The starvation-deadlock alert reads a yield of 0, which is also what an
        // unmeasurable window exports, so it must require a known reading before
        // firing.
        self::assertStringContainsString(
            'max_over_time(nntmux_orchestrator_release_yield_known[30m]) == 1',
            (string) file_get_contents($monitoringPath),
        );
        self::assertNotContains(
            'nntmux_orchestrator_schedulable_backlog{stage="releases"} or vector(0)',
            $dashboardExpressions,
        );
        self::assertNotContains('nntmux_orchestrator_eligible_nzbs or vector(0)', $dashboardExpressions);

        self::assertIsArray($prometheusRule);
        $permitAlert = null;
        $nzbNoCreationAlert = null;
        $currentForwardAlerts = [];
        foreach ($prometheusRule['spec']['groups'] ?? [] as $group) {
            foreach ($group['rules'] ?? [] as $rule) {
                if (($rule['alert'] ?? null) === 'NntmuxBackfillPermitWithoutProgress') {
                    $permitAlert = $rule;
                }
                if (($rule['alert'] ?? null) === 'NntmuxNzbNoCreationProgress') {
                    $nzbNoCreationAlert = $rule;
                }
                if (in_array(($rule['alert'] ?? null), [
                    'NntmuxCurrentForwardPermitStalled',
                    'NntmuxCurrentForwardClaimStalled',
                    'NntmuxCurrentForwardLedgerSettlementStalled',
                    'NntmuxCurrentForwardContinuationExpired',
                    'NntmuxCurrentForwardContinuationDisabledOpen',
                    'NntmuxCurrentForwardQuarantinedWindow',
                    'NntmuxCurrentForwardHalted',
                ], true)) {
                    $currentForwardAlerts[(string) $rule['alert']] = $rule;
                }
            }
        }
        self::assertIsArray($permitAlert);
        self::assertStringContainsString('max without(instance, pod)', (string) ($permitAlert['expr'] ?? ''));
        self::assertStringContainsString('[20m:1m]', (string) ($permitAlert['expr'] ?? ''));
        self::assertIsArray($nzbNoCreationAlert);
        self::assertMatchesRegularExpression(
            '/sum\(\s*increase\(\s*nntmux_worker_runs_total/s',
            (string) ($nzbNoCreationAlert['expr'] ?? ''),
        );
        self::assertMatchesRegularExpression(
            '/sum\(\s*increase\(\s*nntmux_worker_items_total/s',
            (string) ($nzbNoCreationAlert['expr'] ?? ''),
        );
        self::assertCount(7, $currentForwardAlerts);
        self::assertStringContainsString(
            'nntmux_orchestrator_current_forward_claim_age_seconds',
            (string) ($currentForwardAlerts['NntmuxCurrentForwardClaimStalled']['expr'] ?? ''),
        );
        self::assertStringContainsString(
            'nntmux_orchestrator_current_forward_refresh_unresolved_age_seconds',
            (string) ($currentForwardAlerts['NntmuxCurrentForwardLedgerSettlementStalled']['expr'] ?? ''),
        );
        self::assertStringContainsString(
            'nntmux_orchestrator_current_forward_refresh_windows{state="continuation_pending"}',
            (string) ($currentForwardAlerts['NntmuxCurrentForwardContinuationExpired']['expr'] ?? ''),
        );
        self::assertStringContainsString(
            'nntmux_orchestrator_current_forward_continuation_deadline_remaining_seconds',
            (string) ($currentForwardAlerts['NntmuxCurrentForwardContinuationExpired']['expr'] ?? ''),
        );
        self::assertStringContainsString(
            'nntmux_orchestrator_current_forward_continuation_enabled',
            (string) ($currentForwardAlerts['NntmuxCurrentForwardContinuationDisabledOpen']['expr'] ?? ''),
        );
        self::assertStringContainsString(
            'nntmux_orchestrator_current_forward_quarantined_windows',
            (string) ($currentForwardAlerts['NntmuxCurrentForwardQuarantinedWindow']['expr'] ?? ''),
        );
        self::assertStringContainsString(
            'nntmux_orchestrator_current_forward_last_quarantined_timestamp_seconds',
            (string) ($currentForwardAlerts['NntmuxCurrentForwardQuarantinedWindow']['expr'] ?? ''),
        );
        self::assertSame('critical', $currentForwardAlerts['NntmuxCurrentForwardHalted']['labels']['severity'] ?? null);
        self::assertMatchesRegularExpression(
            '/:microservices-pods-20260721-metrics-truth-v189@sha256:36a9484dc9c45dd51db8b78ed11ddf54411eb9a298f061d6e9246fb265026a2c$/',
            (string) ($metrics['spec']['template']['spec']['containers'][0]['image'] ?? ''),
        );

        $expected = [
            'NNTMUX_INLINE_NZB_CREATION' => 'false',
            'NNTMUX_DISTRIBUTED_NZB_LIMIT' => '20',
            'NNTMUX_DISTRIBUTED_NZB_SLEEP' => '55',
            'NNTMUX_DISTRIBUTED_NZB_SCAN_CAP' => '10000',
            'NNTMUX_DISTRIBUTED_NZB_LOCK_SECONDS' => '7200',
        ];
        $environment = static fn (array $deployment): array => array_column(
            $deployment['spec']['template']['spec']['containers'][0]['env'] ?? [],
            'value',
            'name',
        );

        $backlogEnvironment = $environment($deployments['nntmux-worker-nzb-backlog'] ?? []);
        $metricsEnvironment = $environment($metrics);
        self::assertSame('microservices-pods-20260714-nzb-saturated-retry-v147', $backlogEnvironment['NNTMUX_BUILD_VERSION'] ?? null);
        self::assertSame('168', $backlogEnvironment['NNTMUX_DISTRIBUTED_NZB_TERMINAL_STALE_HOURS'] ?? null);
        self::assertSame('true', $backlogEnvironment['NNTMUX_DISTRIBUTED_NZB_TERMINAL_STALE_ENABLED'] ?? null);
        self::assertSame('microservices-pods-20260721-metrics-truth-v189', $metricsEnvironment['NNTMUX_BUILD_VERSION'] ?? null);
        self::assertSame(
            'alt.binaries.documentaries,alt.binaries.dvd.movies,alt.binaries.movies.dvd,alt.binaries.hdtv.tv-episodes',
            $metricsEnvironment['NNTMUX_SPLIT_COLLECTION_RECONCILE_GROUPS'] ?? null,
        );
        self::assertSame('true', $metricsEnvironment['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_CONTINUATION_ENABLED'] ?? null);
        self::assertSame('true', $metricsEnvironment['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_REFRESH_ENABLED'] ?? null);
        self::assertSame('true', $metricsEnvironment['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_LEDGER_ISSUANCE_ENABLED'] ?? null);
        self::assertSame(
            'alt.binaries.teevee:3590755586-3590765585,alt.binaries.tvseries:948898922-948908921',
            $metricsEnvironment['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_REFRESH_SOURCES'] ?? null,
        );
        $backlogContract = array_intersect_key($backlogEnvironment, $expected);
        $metricsContract = array_intersect_key($metricsEnvironment, $expected);
        ksort($expected);
        ksort($backlogContract);
        ksort($metricsContract);

        self::assertSame($expected, $backlogContract);
        self::assertSame($expected, $metricsContract);
        self::assertSame(1, $deployments['nntmux-worker-nzb-backlog']['spec']['replicas'] ?? null);
        self::assertSame(1, $deployments['nntmux-worker-backfill']['spec']['replicas'] ?? null);
        $orchestrator = $deployments['nntmux-worker-orchestrator'] ?? null;
        self::assertIsArray($orchestrator);
        self::assertSame(1, $orchestrator['spec']['replicas'] ?? null);
        $orchestratorImage = (string) ($orchestrator['spec']['template']['spec']['containers'][0]['image'] ?? '');
        $orchestratorBuild = (string) ($environment($orchestrator)['NNTMUX_BUILD_VERSION'] ?? '');
        self::assertSame('microservices-pods-20260721-metrics-truth-v189', $orchestratorBuild);
        self::assertSame(
            'docker.io/krickwix/nntmux:'.$orchestratorBuild.'@sha256:36a9484dc9c45dd51db8b78ed11ddf54411eb9a298f061d6e9246fb265026a2c',
            $orchestratorImage,
        );
        self::assertSame(
            ['php', 'artisan', 'nntmux:worker-orchestrator', '--sleep=15'],
            $orchestrator['spec']['template']['spec']['containers'][0]['args'] ?? null,
        );
        self::assertNotContains('--shadow', $orchestrator['spec']['template']['spec']['containers'][0]['args'] ?? []);
        self::assertSame(
            'true',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_AUTO_BACKFILL'] ?? null,
        );
        self::assertSame(
            'true',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_CONTINUATION_ENABLED'] ?? null,
        );
        self::assertSame(
            'alt.binaries.dvd.movies:147218921,alt.binaries.tvseries:948528922,alt.binaries.movies.dvd:65143468,alt.binaries.hdtv.tv-episodes:99610786',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_STOP_CURSORS'] ?? null,
        );
        self::assertSame(
            'true',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_AUTO_CURRENT_FORWARD'] ?? null,
        );
        self::assertSame(
            'true',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_LEDGER_ISSUANCE_ENABLED'] ?? null,
        );
        $currentForwardWindow = 'alt.binaries.movies.dvd:66163468-66383467@66408646,'
            .'alt.binaries.hdtv.tv-episodes:99760786-99920785@99940788,'
            .'alt.binaries.dvd.movies:147318921-147638920@147661960';
        self::assertSame(
            $currentForwardWindow,
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_WINDOWS'] ?? null,
        );
        $currentForward = $deployments['nntmux-worker-current-forward'] ?? null;
        self::assertIsArray($currentForward);
        self::assertSame(1, $currentForward['spec']['replicas'] ?? null);
        self::assertSame(
            ['php', 'artisan', 'nntmux:worker', 'current-forward', '--sleep=5'],
            $currentForward['spec']['template']['spec']['containers'][0]['args'] ?? null,
        );
        self::assertSame(
            $currentForwardWindow,
            $environment($currentForward)['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_WINDOWS'] ?? null,
        );
        self::assertSame(
            'true',
            $environment($currentForward)['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_LEDGER_ISSUANCE_ENABLED'] ?? null,
        );
        self::assertSame(
            'alt.binaries.teevee:3590755586-3590765585,alt.binaries.tvseries:948898922-948908921',
            $environment($currentForward)['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_REFRESH_SOURCES'] ?? null,
        );
        self::assertSame(
            $currentForwardWindow,
            $environment($deployments['nntmux-worker-binaries'])['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_WINDOWS'] ?? null,
        );
        self::assertSame(
            'true',
            $environment($deployments['nntmux-worker-binaries'])['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_LEDGER_ISSUANCE_ENABLED'] ?? null,
        );
        self::assertSame(
            'alt.binaries.teevee:3590755586-3590765585,alt.binaries.tvseries:948898922-948908921',
            $environment($deployments['nntmux-worker-binaries'])['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_REFRESH_SOURCES'] ?? null,
        );
        self::assertSame(
            'alt.binaries.teevee:3590755586-3590765585,alt.binaries.tvseries:948898922-948908921',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_REFRESH_SOURCES'] ?? null,
        );
        self::assertSame('true', $environment($orchestrator)['NNTMUX_ORCHESTRATOR_QUALIFIED_SUPPLY_STARVATION_ENABLED'] ?? null);
        self::assertSame('900', $environment($orchestrator)['NNTMUX_ORCHESTRATOR_QUALIFIED_SUPPLY_STARVATION_DWELL_SECONDS'] ?? null);
        self::assertSame('300', $environment($orchestrator)['NNTMUX_ORCHESTRATOR_QUALIFIED_SUPPLY_BINARIES_SLEEP_SECONDS'] ?? null);
        self::assertSame(
            '4',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_CONTEXT_MAX_CHAIN_WINDOWS'] ?? null,
        );
        self::assertSame(
            '120',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_LEADER_LOCK_SECONDS'] ?? null,
        );
        self::assertSame(
            '1200',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_PERMIT_OBSERVATION_SECONDS'] ?? null,
        );
        self::assertSame(
            '120',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_PERMIT_CLAIM_GRACE_SECONDS'] ?? null,
        );
        self::assertSame(
            '104857600',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_MIN_PAYLOAD_BYTES'] ?? null,
        );
        self::assertSame(
            'alt.binaries.tvseries',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_TV_DATE_RANGE_GROUPS'] ?? null,
        );
        self::assertSame(
            'alt.binaries.tvseries',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_TV_COMPLETE_SERIES_GROUPS'] ?? null,
        );
        self::assertSame(
            'alt.binaries.tvseries',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_TV_SERIES_PACK_GROUPS'] ?? null,
        );
        $releases = $deployments['nntmux-worker-releases'] ?? null;
        self::assertIsArray($releases);
        self::assertSame(
            'microservices-pods-20260802-split-lookback-retention-v215',
            $environment($releases)['NNTMUX_BUILD_VERSION'] ?? null,
        );
        self::assertSame(
            'docker.io/krickwix/nntmux:microservices-pods-20260802-split-lookback-retention-v215@sha256:028421bdfb9c2b12f682331cb8c5bde98c005ae6684442a76c9d4e3a2431951c',
            $releases['spec']['template']['spec']['containers'][0]['image'] ?? null,
        );
        self::assertSame('25', $environment($releases)['NNTMUX_DISTRIBUTED_RELEASE_PUMP_DEADLINE_SECONDS'] ?? null);
        self::assertSame('200', $environment($releases)['NNTMUX_DISTRIBUTED_RELEASE_PUMP_BATCH_SIZE'] ?? null);
        self::assertSame('1', $environment($releases)['NNTMUX_DISTRIBUTED_RELEASE_SWEEP_GROUPS'] ?? null);
        self::assertSame('5', $environment($releases)['NNTMUX_DISTRIBUTED_CONTROL_SLEEP_SLICE_SECONDS'] ?? null);
        foreach (['nntmux-worker-releases', 'nntmux-worker-hashed-fixnames'] as $imageOwnedMiscWorker) {
            $worker = $deployments[$imageOwnedMiscWorker] ?? null;
            self::assertIsArray($worker);
            $mountPaths = array_column(
                $worker['spec']['template']['spec']['containers'][0]['volumeMounts'] ?? [],
                'mountPath',
            );
            self::assertNotContains(
                '/app/app/Services/Categorization/Categorizers/MiscCategorizer.php',
                $mountPaths,
                sprintf('%s must use the tested image-owned compact-TV misc categorizer.', $imageOwnedMiscWorker),
            );
        }
        self::assertSame(
            'alt.binaries.documentaries,alt.binaries.dvd.movies,alt.binaries.movies.dvd,alt.binaries.hdtv.tv-episodes',
            $environment($releases)['NNTMUX_SPLIT_COLLECTION_RECONCILE_GROUPS'] ?? null,
        );
        self::assertSame(
            'alt.binaries.documentaries:2000,alt.binaries.hdtv.tv-episodes:2000',
            $environment($releases)['NNTMUX_SPLIT_COLLECTION_XREF_GAP_OVERRIDES'] ?? null,
        );
        // Tracks the partretentionhours setting; a cohort is only mergeable
        // while the retention cleanup has spared its collections.
        self::assertSame('96', $environment($releases)['NNTMUX_SPLIT_COLLECTION_RECONCILE_LOOKBACK_HOURS'] ?? null);
        self::assertSame('redis', $environment($releases)['NNTMUX_SPLIT_COLLECTION_RECONCILE_CURSOR_STORE'] ?? null);
        self::assertSame(
            'alt.binaries.hdtv.tv-episodes',
            $environment($releases)['NNTMUX_SPLIT_COLLECTION_DYNAMIC_PAIR_GAP_GROUPS'] ?? null,
        );
        self::assertSame(
            'alt.binaries.hdtv.tv-episodes',
            $environment($releases)['NNTMUX_SPLIT_COLLECTION_TERMINAL_PAIR_REPAIR_GROUPS'] ?? null,
        );
        self::assertSame('3', $environment($releases)['NNTMUX_SPLIT_COLLECTION_TERMINAL_PAIR_REPAIR_ROOTS'] ?? null);
        self::assertSame(
            'alt.binaries.tvseries',
            $environment($releases)['NNTMUX_ORCHESTRATOR_BACKFILL_TV_COMPLETE_SERIES_GROUPS'] ?? null,
        );
        self::assertSame(
            'alt.binaries.tvseries',
            $environment($releases)['NNTMUX_ORCHESTRATOR_BACKFILL_TV_SERIES_PACK_GROUPS'] ?? null,
        );
        self::assertSame(
            '0.90',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_MIN_TARGET_BYTE_SHARE'] ?? null,
        );
        self::assertSame(
            '1',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_MAX_NON_TARGET_RELEASES'] ?? null,
        );
        self::assertSame(
            '536870912',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_MAX_NON_TARGET_BYTES'] ?? null,
        );
        self::assertSame(
            '3600',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_TARGET_LOCK_RETRY_SECONDS'] ?? null,
        );
        self::assertSame(
            '120',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_PRESSURE_HORIZON_MINUTES'] ?? null,
        );
        self::assertSame(
            '3',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_PROMETHEUS_RETRY_ATTEMPTS'] ?? null,
        );
        self::assertSame(
            '120',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_PROMETHEUS_SAMPLE_MAX_AGE_SECONDS'] ?? null,
        );
        self::assertSame(
            '180',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_SNAPSHOT_MAX_AGE_SECONDS'] ?? null,
        );
        self::assertSame(
            'max(timestamp(kubelet_volume_stats_available_bytes{namespace="media",persistentvolumeclaim="data-nntmux-mariadb-0"}))',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_STORAGE_FRESHNESS_PROMQL'] ?? null,
        );
        self::assertSame(
            'max(timestamp(container_memory_working_set_bytes{namespace="media",pod="nntmux-mariadb-0",container!="",container!="POD"}))',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_DB_MEMORY_FRESHNESS_PROMQL'] ?? null,
        );
        self::assertSame(
            'max(timestamp(container_cpu_usage_seconds_total{namespace="media",pod="nntmux-mariadb-0",container!="",container!="POD"}))',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_DB_CPU_FRESHNESS_PROMQL'] ?? null,
        );
        $probeGroupsValue = $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_PROBE_GROUPS'] ?? null;
        self::assertSame(
            'alt.binaries.documentaries,alt.binaries.dvd.movies,alt.binaries.tvseries,alt.binaries.movies.dvd,alt.binaries.hdtv.tv-episodes',
            $probeGroupsValue,
        );
        $probeGroups = explode(',', (string) $probeGroupsValue);
        self::assertCount(5, $probeGroups);
        self::assertLessThanOrEqual(16, count($probeGroups));
        self::assertCount(count($probeGroups), array_unique($probeGroups));
        self::assertSame(
            '86400',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_YIELD_TTL_SECONDS'] ?? null,
        );
        self::assertSame(
            '9',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_EXPLOIT_ATTEMPTS_BEFORE_EXPLORE'] ?? null,
        );
        self::assertSame(
            '0.15',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_AGGRESSIVE_EXPLORE_BELOW_YIELD'] ?? null,
        );
        self::assertArrayNotHasKey('NNTMUX_ORCHESTRATOR_BACKFILL_CONTEXT_RETRY_QUANTITY', $environment($orchestrator));
        self::assertSame(
            '300',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_ZERO_OUTPUT_GRACE_SECONDS'] ?? null,
        );
        self::assertSame(
            '600',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_INCOMPLETE_RELEASE_GRACE_SECONDS'] ?? null,
        );
        self::assertSame(
            '45',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_PRODUCTIVE_SETTLEMENT_GRACE_SECONDS'] ?? null,
        );
        self::assertSame(
            '9000',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_DELAYED_ATTRIBUTION_SECONDS'] ?? null,
        );
        self::assertSame(
            '0.20',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_HEADROOM_FRACTION'] ?? null,
        );
        self::assertSame(
            '20000',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_MAX_QUANTITY'] ?? null,
        );
        self::assertSame(
            '0.15',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_SCALE_MIN_YIELD'] ?? null,
        );
        self::assertSame(
            '14400',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_COHORT_POSTDATE_TOLERANCE_SECONDS'] ?? null,
        );
        self::assertSame(
            '1000',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_COLLECTIONS_PER_10K'] ?? null,
        );
        self::assertSame(
            '100',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_RELEASES_PER_10K'] ?? null,
        );
        self::assertSame(
            '100',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_NZBS_PER_10K'] ?? null,
        );
        self::assertSame(
            '12',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_GROWTH_LEARNING_MIN_SAMPLES'] ?? null,
        );
        self::assertSame(
            '2.0',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_GROWTH_LEARNING_SAFETY_MULTIPLIER'] ?? null,
        );
        self::assertSame(
            '0.25',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_GROWTH_LEARNING_PRIOR_FLOOR_FRACTION'] ?? null,
        );
        self::assertSame(
            '7200',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_GROWTH_LEARNING_LATEST_SAMPLE_SECONDS'] ?? null,
        );
        $sustainableBackfillImage = 'docker.io/krickwix/nntmux:microservices-pods-20260720-sustainable-backfill-v188@sha256:9162bcefa4e5e0f7b6c0690d1e05c26ede86e938900dd2f8efda36dd20468a0a';
        self::assertSame(
            $sustainableBackfillImage,
            (string) ($deployments['nntmux-worker-backfill']['spec']['template']['spec']['containers'][0]['image'] ?? ''),
        );
        self::assertSame(
            'docker.io/krickwix/nntmux:microservices-pods-20260714-nzb-saturated-retry-v147@sha256:6ede1752f1d15e0334f6f8f91d9f3c3869034c65484d60a568bcccdf62703cc1',
            (string) ($deployments['nntmux-worker-nzb-backlog']['spec']['template']['spec']['containers'][0]['image'] ?? ''),
        );
        self::assertSame(
            'alt.binaries.dvd.movies:147218921,alt.binaries.tvseries:948528922,alt.binaries.movies.dvd:65143468,alt.binaries.hdtv.tv-episodes:99610786',
            $environment($deployments['nntmux-worker-backfill'])['NNTMUX_ORCHESTRATOR_BACKFILL_STOP_CURSORS'] ?? null,
        );
        self::assertSame(
            'true',
            $environment($deployments['nntmux-worker-backfill'])['NNTMUX_ORCHESTRATOR_REQUIRE_BACKFILL_PERMIT'] ?? null,
        );
        self::assertSame(0, $deployments['nntmux-worker-per-group']['spec']['replicas'] ?? null);
        self::assertArrayNotHasKey('serviceAccountName', $orchestrator['spec']['template']['spec'] ?? []);

        foreach ($deployments as $name => $deployment) {
            $container = $deployment['spec']['template']['spec']['containers'][0] ?? [];
            if ($name === 'nntmux-worker-nzb-backlog' || ! in_array('nntmux:worker', $container['args'] ?? [], true)) {
                continue;
            }

            self::assertSame(
                '1',
                $environment($deployment)['NNTMUX_DISTRIBUTED_NZB_LIMIT'] ?? null,
                sprintf('%s must retain the shared one-item limit.', $name),
            );
            self::assertSame(
                '60',
                $environment($deployment)['NNTMUX_DISTRIBUTED_NZB_SLEEP'] ?? null,
                sprintf('%s must retain the shared 60-second sleep.', $name),
            );
        }
    }

    /**
     * Every NNTMUX_ variable the manifests set must be read by a config file.
     *
     * A thin overlay that COPYs config/nntmux.php from a branch missing a key
     * fails silently: config() returns null, the feature becomes dead code, and
     * nothing logs. That is exactly how the brace-token and hash-set
     * normalizers ran as no-ops for ~75h while their env vars were set. An
     * orphan variable here means either the reader never shipped or an overlay
     * reverted the config that read it.
     */
    public function test_every_declared_nntmux_variable_is_read_by_a_config_file(): void
    {
        // Variables whose readers have not shipped yet. Empty on purpose: the
        // last two entries (fair-share newest cursor and fill quantity) were
        // cleared when fix/backfill-fair-share-newest-cursor landed. Add an
        // entry only to cover a genuinely pending reader, and remove it as soon
        // as that reader merges -- the assertion below enforces that.
        $pendingReaders = [];

        $manifestRoot = dirname(__DIR__, 5).'/mediahome/manifests/media/nntmux';
        if (! is_dir($manifestRoot)) {
            self::markTestSkipped('mediahome sibling checkout is required for the workspace manifest regression.');
        }

        $declared = self::declaredNntmuxVariables($manifestRoot);
        // Guards against a silently-skipping sweep: the manifests have carried
        // ~100 of these for a long time, so a near-empty scan means the walk
        // broke, not that the fleet stopped being configured.
        self::assertGreaterThan(90, count($declared), 'Manifest scan collected implausibly few variables.');

        $configured = self::configuredNntmuxVariables();

        // Keep the exemption list honest: once a reader ships, the entry must go,
        // otherwise the list quietly grows into a place to hide real drift.
        self::assertSame(
            [],
            array_values(array_intersect($pendingReaders, $configured)),
            'These variables now have config readers -- remove them from $pendingReaders.',
        );

        $orphans = array_values(array_diff(array_keys($declared), $configured, $pendingReaders));

        self::assertSame([], $orphans, sprintf(
            'These NNTMUX_ variables are set in the manifests but no config/*.php reads them, '.
            "so they are silently inert:\n  - %s",
            implode("\n  - ", array_map(
                static fn (string $name): string => $name.' ('.implode(', ', $declared[$name]).')',
                $orphans,
            )),
        ));
    }

    /**
     * The obfuscation normalizers are opt-in per group and default to an empty
     * list, so a missing variable disables them without any error. They were
     * applied out-of-band and are absent from the manifests, which means a
     * `kubectl apply` of this repo would strip them and silently re-break the
     * collections pipeline. Pin them here so the manifests stay the source of
     * truth.
     */
    public function test_obfuscation_normalizer_groups_are_declared_in_the_manifests(): void
    {
        $manifestRoot = dirname(__DIR__, 5).'/mediahome/manifests/media/nntmux';
        if (! is_dir($manifestRoot)) {
            self::markTestSkipped('mediahome sibling checkout is required for the workspace manifest regression.');
        }

        $declared = self::declaredNntmuxVariables($manifestRoot);

        foreach (['NNTMUX_OBFUSCATED_BRACE_TOKEN_GROUPS', 'NNTMUX_OBFUSCATED_HASH_SET_GROUPS'] as $variable) {
            self::assertArrayHasKey($variable, $declared, sprintf(
                '%s must be declared in the manifests, or applying them strips it from the running fleet.',
                $variable,
            ));
        }
    }

    /**
     * Anything under apply management must be in the repo, or apply strips it.
     *
     * `kubectl apply` reconciles against the last-applied-configuration
     * annotation: a key present there but absent from the file being applied is
     * deleted. Keys added purely out-of-band (set env / patch / edit) never
     * enter that annotation and survive, which is why plain live-vs-repo diffing
     * over-reports. Only apply-managed keys are true strip hazards -- that is
     * how NNTMUX_OBFUSCATED_{BRACE_TOKEN,HASH_SET}_GROUPS came to be one commit
     * away from silently re-breaking the collections pipeline.
     *
     * Read-only, and skips when no cluster is reachable, so it is a no-op in CI
     * and in the Docker gate; run it on a host with KUBECONFIG to get coverage.
     */
    public function test_apply_managed_nntmux_variables_are_declared_in_the_manifests(): void
    {
        $manifestRoot = dirname(__DIR__, 5).'/mediahome/manifests/media/nntmux';
        if (! is_dir($manifestRoot)) {
            self::markTestSkipped('mediahome sibling checkout is required for the workspace manifest regression.');
        }
        if (self::shell('kubectl version --client -o json 2>/dev/null') === null) {
            self::markTestSkipped('kubectl is required for the live apply-management check.');
        }

        $raw = self::shell(
            'kubectl -n media get deploy,statefulset,cronjob,configmap '
            .'-o json 2>/dev/null',
        );
        if ($raw === null) {
            self::markTestSkipped('No reachable cluster for the live apply-management check.');
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload) || ! is_array($payload['items'] ?? null)) {
            self::markTestSkipped('Unexpected kubectl payload for the live apply-management check.');
        }

        $applyManaged = [];
        foreach ($payload['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $annotation = $item['metadata']['annotations']['kubectl.kubernetes.io/last-applied-configuration'] ?? null;
            if (! is_string($annotation)) {
                continue;
            }
            $lastApplied = json_decode($annotation, true);
            if (! is_array($lastApplied)) {
                continue;
            }
            $name = is_string($item['metadata']['name'] ?? null) ? $item['metadata']['name'] : '?';
            $found = [];
            self::collectNntmuxVariables($lastApplied, $name, $found);
            foreach (array_keys($found) as $variable) {
                $applyManaged[$variable][] = $name;
            }
        }

        if ($applyManaged === []) {
            self::markTestSkipped('No apply-managed workloads found; nothing to compare.');
        }

        $declared = self::declaredNntmuxVariables($manifestRoot);
        $hazards = [];
        foreach ($applyManaged as $variable => $workloads) {
            if (! array_key_exists($variable, $declared)) {
                $hazards[] = $variable.' (live on '.implode(', ', array_unique($workloads)).')';
            }
        }
        sort($hazards);

        self::assertSame([], $hazards, sprintf(
            'These NNTMUX_ variables are under apply management live but are absent '
            ."from the manifests, so applying this repo would strip them:\n  - %s",
            implode("\n  - ", $hazards),
        ));
    }

    private static function shell(string $command): ?string
    {
        $output = @shell_exec($command);

        return is_string($output) && trim($output) !== '' ? $output : null;
    }

    /**
     * A variable only reaches PHP from a container that loads the app env.
     *
     * Four NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_* settings were applied
     * out-of-band onto the `prepare-volumes` init container, which is
     * busybox:1.36 running mkdir/chmod with no envFrom. They looked configured
     * from `kubectl get`, but PHP never saw them and each silently fell back to
     * its config/nntmux.php default. The reader guard above cannot catch this:
     * the variables have readers, they are simply attached to a container that
     * cannot reach them.
     */
    public function test_nntmux_variables_are_never_set_on_a_non_app_container(): void
    {
        $manifestRoot = dirname(__DIR__, 5).'/mediahome/manifests/media/nntmux';
        if (! is_dir($manifestRoot)) {
            self::markTestSkipped('mediahome sibling checkout is required for the workspace manifest regression.');
        }

        $misplaced = [];
        foreach (self::manifestPodSpecs($manifestRoot) as [$file, $workload, $spec]) {
            foreach ($spec['initContainers'] ?? [] as $container) {
                if (! is_array($container)) {
                    continue;
                }
                foreach ($container['env'] ?? [] as $entry) {
                    if (! is_array($entry) || ! is_string($entry['name'] ?? null)) {
                        continue;
                    }
                    if (str_starts_with($entry['name'], 'NNTMUX_')) {
                        $misplaced[] = sprintf(
                            '%s (%s, init container %s in %s)',
                            $entry['name'],
                            $workload,
                            is_string($container['name'] ?? null) ? $container['name'] : '?',
                            $file,
                        );
                    }
                }
            }
        }

        self::assertSame([], $misplaced, sprintf(
            'These NNTMUX_ variables sit on init containers, which do not run the app '
            ."and cannot pass them to PHP, so they silently resolve to config defaults:\n  - %s",
            implode("\n  - ", $misplaced),
        ));
    }

    /**
     * Every app container that reads NNTMUX_ variables must load the shared env.
     *
     * Declaring a variable inline on a container that lacks `envFrom` would work
     * for that one key while leaving DB/Redis credentials unset, so this catches
     * a container being wired up by hand instead of from the nntmux-env
     * ConfigMap.
     */
    public function test_app_containers_setting_nntmux_variables_load_the_shared_env(): void
    {
        $manifestRoot = dirname(__DIR__, 5).'/mediahome/manifests/media/nntmux';
        if (! is_dir($manifestRoot)) {
            self::markTestSkipped('mediahome sibling checkout is required for the workspace manifest regression.');
        }

        $unwired = [];
        $checked = 0;
        foreach (self::manifestPodSpecs($manifestRoot) as [$file, $workload, $spec]) {
            foreach ($spec['containers'] ?? [] as $container) {
                if (! is_array($container)) {
                    continue;
                }
                $setsNntmux = false;
                foreach ($container['env'] ?? [] as $entry) {
                    if (is_array($entry) && is_string($entry['name'] ?? null)
                        && str_starts_with($entry['name'], 'NNTMUX_')) {
                        $setsNntmux = true;
                        break;
                    }
                }
                if (! $setsNntmux) {
                    continue;
                }
                $checked++;
                if (($container['envFrom'] ?? []) === []) {
                    $unwired[] = sprintf(
                        '%s / %s (%s)',
                        $workload,
                        is_string($container['name'] ?? null) ? $container['name'] : '?',
                        $file,
                    );
                }
            }
        }

        // A zero here would mean the walk broke rather than the fleet being clean.
        self::assertGreaterThan(5, $checked, 'Pod-spec walk collected implausibly few app containers.');
        self::assertSame([], $unwired, sprintf(
            'These containers set NNTMUX_ variables but have no envFrom, so they '
            ."miss the shared nntmux-env ConfigMap:\n  - %s",
            implode("\n  - ", $unwired),
        ));
    }

    /**
     * Every pod spec in the manifests, as [file, workload name, spec].
     *
     * CronJobs nest their template a level deeper than Deployments and
     * StatefulSets, so unwrap that here rather than at each call site.
     *
     * @return list<array{0:string,1:string,2:array<string, mixed>}>
     */
    private static function manifestPodSpecs(string $manifestRoot): array
    {
        $parser = new Parser(maxAliasesForCollections: 1000);
        $specs = [];

        foreach (glob($manifestRoot.'/*.yaml') ?: [] as $file) {
            $documents = preg_split('/^---\s*$/m', (string) file_get_contents($file)) ?: [];
            foreach ($documents as $document) {
                try {
                    $resource = $parser->parse($document);
                } catch (\Throwable) {
                    // Templates and partial documents are not our concern here.
                    continue;
                }
                if (is_array($resource)) {
                    self::collectPodSpecs($resource, basename($file), $specs);
                }
            }
        }

        return $specs;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<array{0:string,1:string,2:array<string, mixed>}>  $specs
     */
    private static function collectPodSpecs(array $node, string $file, array &$specs): void
    {
        // A List resource nests real workloads under `items`, so recurse over
        // every node rather than only inspecting the document root.
        $kind = $node['kind'] ?? null;
        if (in_array($kind, ['Deployment', 'StatefulSet', 'DaemonSet', 'CronJob', 'Job'], true)) {
            $spec = $kind === 'CronJob'
                ? ($node['spec']['jobTemplate']['spec']['template']['spec'] ?? null)
                : ($node['spec']['template']['spec'] ?? null);
            if (is_array($spec)) {
                $name = is_string($node['metadata']['name'] ?? null)
                    ? $node['metadata']['name']
                    : '?';
                $specs[] = [$file, $name, $spec];
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                self::collectPodSpecs($value, $file, $specs);
            }
        }
    }

    /**
     * @return array<string, list<string>> variable name => manifest files declaring it
     */
    private static function declaredNntmuxVariables(string $manifestRoot): array
    {
        $parser = new Parser(maxAliasesForCollections: 1000);
        $declared = [];

        foreach (glob($manifestRoot.'/*.yaml') ?: [] as $file) {
            $documents = preg_split('/^---\s*$/m', (string) file_get_contents($file)) ?: [];
            foreach ($documents as $document) {
                try {
                    $resource = $parser->parse($document);
                } catch (\Throwable) {
                    // Templates and partial documents are not our concern here.
                    continue;
                }
                if (! is_array($resource)) {
                    continue;
                }
                self::collectNntmuxVariables($resource, basename($file), $declared);
            }
        }

        ksort($declared);

        return $declared;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<string, list<string>>  $declared
     */
    private static function collectNntmuxVariables(array $node, string $file, array &$declared): void
    {
        // ConfigMap keys and container `env` entries are the only two shapes
        // that actually reach a pod's environment.
        if (($node['kind'] ?? null) === 'ConfigMap' && is_array($node['data'] ?? null)) {
            foreach (array_keys($node['data']) as $key) {
                if (is_string($key) && str_starts_with($key, 'NNTMUX_')) {
                    $declared[$key][] = $file;
                }
            }
        }

        if (is_string($node['name'] ?? null) && array_key_exists('value', $node)
            && str_starts_with($node['name'], 'NNTMUX_')) {
            $declared[$node['name']][] = $file;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                self::collectNntmuxVariables($value, $file, $declared);
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function configuredNntmuxVariables(): array
    {
        $variables = [];

        foreach (glob(dirname(__DIR__, 4).'/config/*.php') ?: [] as $file) {
            // env() calls wrap across lines in these files, so match the name
            // argument rather than a single-line call.
            if (preg_match_all(
                '/env\(\s*[\'"](NNTMUX_[A-Z0-9_]+)[\'"]/',
                (string) file_get_contents($file),
                $matches,
            )) {
                foreach ($matches[1] as $variable) {
                    $variables[$variable] = true;
                }
            }
        }

        return array_keys($variables);
    }
}
