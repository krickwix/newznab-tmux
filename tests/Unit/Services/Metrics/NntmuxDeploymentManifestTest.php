<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Metrics;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Parser;

final class NntmuxDeploymentManifestTest extends TestCase
{
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
            $expectedImage = $name === 'nntmux-worker-backfill'
                ? ':microservices-pods-20260714-permit-handoff-v144@sha256:53d14c7febe7b0f50c9676a3f374a4a31ec7244e13ec9f4dc98cf3a811a2c732'
                : ($name === 'nntmux-worker-nzb-backlog'
                    ? ':microservices-pods-20260714-nzb-saturated-retry-v147@sha256:6ede1752f1d15e0334f6f8f91d9f3c3869034c65484d60a568bcccdf62703cc1'
                    : ($name === 'nntmux-worker-post-additional'
                        ? ':microservices-pods-20260714-nfo-gate-v143@sha256:82bc4bf05d48dc1e4af9ce19f6219e7dfeee16bde3d32b48cf20ace464a11ab3'
                : ($name === 'nntmux-worker-hashed-fixnames'
                    ? ':microservices-pods-20260713-fresh-hashed-retry-v114'
                    : (in_array($name, [
                        'nntmux-worker-binaries',
                        'nntmux-worker-current-forward',
                        'nntmux-worker-releases',
                    ], true)
                ? ':microservices-pods-20260714-current-forward-v154@sha256:81ebf199e79c6c064e6663695939cde48313f0fa04e1c8be38fb963c7053a84d'
                : (in_array($name, [
                    'nntmux-worker-removecrap',
                    'nntmux-worker-per-group',
                ], true)
                ? ':microservices-pods-20260711-nzb-cleanup-lock-v9'
                : ':microservices-pods-20260710-nzb-query-v8')))));
            self::assertStringEndsWith(
                $expectedImage,
                (string) ($container['image'] ?? ''),
                sprintf('%s must retain its intended isolated image.', $name),
            );
            $environment = array_column($container['env'] ?? [], 'value', 'name');
            self::assertSame(
                explode('@', ltrim($expectedImage, ':'), 2)[0],
                $environment['NNTMUX_BUILD_VERSION'] ?? null,
                sprintf('%s build telemetry must match its image.', $name),
            );
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

    public function test_web_uses_lock_safe_overlay_without_changing_scheduler_or_legacy_worker(): void
    {
        $path = dirname(__DIR__, 5).'/mediahome/manifests/media/nntmux/app.yaml';
        if (! is_file($path)) {
            self::markTestSkipped('mediahome sibling checkout is required for the workspace manifest regression.');
        }

        $parser = new Parser(maxAliasesForCollections: 1000);
        $documents = preg_split('/^---\s*$/m', (string) file_get_contents($path));
        self::assertIsArray($documents);

        $images = [];
        foreach ($documents as $document) {
            $resource = $parser->parse($document);
            if (is_array($resource) && ($resource['kind'] ?? null) === 'Deployment') {
                $images[(string) ($resource['metadata']['name'] ?? '')] =
                    (string) ($resource['spec']['template']['spec']['containers'][0]['image'] ?? '');
            }
        }

        self::assertStringEndsWith(
            ':microservices-pods-20260711-nzb-cleanup-web-amd64-v9',
            $images['nntmux-web'] ?? '',
        );
        foreach (['nntmux-worker', 'nntmux-scheduler'] as $deployment) {
            self::assertStringEndsWith(
                ':microservices-pods-20260615-group-delete-v1',
                $images[$deployment] ?? '',
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
        foreach ($monitoringDocuments as $document) {
            $resource = $parser->parse($document);
            if (is_array($resource) && ($resource['kind'] ?? null) === 'PrometheusRule') {
                $prometheusRule = $resource;
                break;
            }
        }
        self::assertIsArray($prometheusRule);
        $permitAlert = null;
        foreach ($prometheusRule['spec']['groups'] ?? [] as $group) {
            foreach ($group['rules'] ?? [] as $rule) {
                if (($rule['alert'] ?? null) === 'NntmuxBackfillPermitWithoutProgress') {
                    $permitAlert = $rule;
                    break 2;
                }
            }
        }
        self::assertIsArray($permitAlert);
        self::assertStringContainsString('max without(instance, pod)', (string) ($permitAlert['expr'] ?? ''));
        self::assertStringContainsString('[20m:1m]', (string) ($permitAlert['expr'] ?? ''));
        self::assertStringEndsWith(
            ':microservices-pods-20260713-backfill-source-v97',
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
        self::assertSame('microservices-pods-20260713-backfill-source-v97', $metricsEnvironment['NNTMUX_BUILD_VERSION'] ?? null);
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
        self::assertSame('microservices-pods-20260714-current-forward-v154', $orchestratorBuild);
        self::assertSame(
            'docker.io/krickwix/nntmux:'.$orchestratorBuild.'@sha256:81ebf199e79c6c064e6663695939cde48313f0fa04e1c8be38fb963c7053a84d',
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
            'alt.binaries.dvd.movies:147218921,alt.binaries.tvseries:948528922,alt.binaries.movies.dvd:66033468,alt.binaries.hdtv.tv-episodes:99610786',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_STOP_CURSORS'] ?? null,
        );
        $currentForwardWindow = 'alt.binaries.hdtv.tv-episodes:99730786-99740785@99802459';
        self::assertSame(
            $currentForwardWindow,
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_WINDOWS'] ?? null,
        );
        $currentForward = $deployments['nntmux-worker-current-forward'] ?? null;
        self::assertIsArray($currentForward);
        self::assertSame(1, $currentForward['spec']['replicas'] ?? null);
        self::assertSame(
            ['php', 'artisan', 'nntmux:worker', 'current-forward'],
            $currentForward['spec']['template']['spec']['containers'][0]['args'] ?? null,
        );
        self::assertSame(
            $currentForwardWindow,
            $environment($currentForward)['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_WINDOWS'] ?? null,
        );
        self::assertSame(
            $currentForwardWindow,
            $environment($deployments['nntmux-worker-binaries'])['NNTMUX_ORCHESTRATOR_CURRENT_FORWARD_WINDOWS'] ?? null,
        );
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
            'microservices-pods-20260714-current-forward-v154',
            $environment($releases)['NNTMUX_BUILD_VERSION'] ?? null,
        );
        self::assertSame(
            'alt.binaries.dvd.movies,alt.binaries.movies.dvd,alt.binaries.hdtv.tv-episodes',
            $environment($releases)['NNTMUX_SPLIT_COLLECTION_RECONCILE_GROUPS'] ?? null,
        );
        self::assertSame(
            'alt.binaries.hdtv.tv-episodes:2000',
            $environment($releases)['NNTMUX_SPLIT_COLLECTION_XREF_GAP_OVERRIDES'] ?? null,
        );
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
            'alt.binaries.dvd.movies,alt.binaries.tvseries,alt.binaries.movies.dvd,alt.binaries.hdtv.tv-episodes',
            $probeGroupsValue,
        );
        $probeGroups = explode(',', (string) $probeGroupsValue);
        self::assertCount(4, $probeGroups);
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
            '10000',
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
        $v144Image = 'docker.io/krickwix/nntmux:microservices-pods-20260714-permit-handoff-v144@sha256:53d14c7febe7b0f50c9676a3f374a4a31ec7244e13ec9f4dc98cf3a811a2c732';
        self::assertSame(
            $v144Image,
            (string) ($deployments['nntmux-worker-backfill']['spec']['template']['spec']['containers'][0]['image'] ?? ''),
        );
        self::assertSame(
            'docker.io/krickwix/nntmux:microservices-pods-20260714-nzb-saturated-retry-v147@sha256:6ede1752f1d15e0334f6f8f91d9f3c3869034c65484d60a568bcccdf62703cc1',
            (string) ($deployments['nntmux-worker-nzb-backlog']['spec']['template']['spec']['containers'][0]['image'] ?? ''),
        );
        self::assertSame(
            'alt.binaries.dvd.movies:147218921,alt.binaries.tvseries:948528922,alt.binaries.movies.dvd:66033468,alt.binaries.hdtv.tv-episodes:99610786',
            $environment($deployments['nntmux-worker-backfill'])['NNTMUX_ORCHESTRATOR_BACKFILL_STOP_CURSORS'] ?? null,
        );
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
}
