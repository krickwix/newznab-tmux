<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Metrics;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Parser;

final class NntmuxDeploymentManifestTest extends TestCase
{
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
        $orchestrator = null;
        foreach ($manifest['items'] as $deployment) {
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
                ? ':microservices-pods-20260712-part-gap-lock-v82'
                : (in_array($name, [
                    'nntmux-worker-binaries',
                    'nntmux-worker-releases',
                    'nntmux-worker-nzb-backlog',
                ], true)
                ? ($name === 'nntmux-worker-binaries'
                    ? ':microservices-pods-20260712-part-gap-lock-v82'
                    : ':microservices-pods-20260711-worker-orchestrator-v22')
                : (in_array($name, [
                    'nntmux-worker-removecrap',
                    'nntmux-worker-per-group',
                ], true)
                ? ':microservices-pods-20260711-nzb-cleanup-lock-v9'
                : ':microservices-pods-20260710-nzb-query-v8'));
            self::assertStringEndsWith(
                $expectedImage,
                (string) ($container['image'] ?? ''),
                sprintf('%s must retain its intended isolated image.', $name),
            );
            $environment = array_column($container['env'] ?? [], 'value', 'name');
            self::assertSame(
                ltrim($expectedImage, ':'),
                $environment['NNTMUX_BUILD_VERSION'] ?? null,
                sprintf('%s build telemetry must match its image.', $name),
            );
        }

        self::assertNotEmpty($workers);
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
            ':microservices-pods-20260712-recovery-fairness-v76',
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
        self::assertSame('microservices-pods-20260711-worker-orchestrator-v22', $backlogEnvironment['NNTMUX_BUILD_VERSION'] ?? null);
        self::assertSame('microservices-pods-20260712-recovery-fairness-v76', $metricsEnvironment['NNTMUX_BUILD_VERSION'] ?? null);
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
        self::assertStringEndsWith(
            ':microservices-pods-20260713-exact-release-cohort-v87',
            (string) ($orchestrator['spec']['template']['spec']['containers'][0]['image'] ?? ''),
        );
        self::assertSame(
            'microservices-pods-20260713-exact-release-cohort-v87',
            $environment($orchestrator)['NNTMUX_BUILD_VERSION'] ?? null,
        );
        self::assertNotContains('--shadow', $orchestrator['spec']['template']['spec']['containers'][0]['args'] ?? []);
        self::assertSame(
            'true',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_AUTO_BACKFILL'] ?? null,
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
            '120',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_PRESSURE_HORIZON_MINUTES'] ?? null,
        );
        self::assertSame(
            'alt.binaries.lossless,alt.binaries.sounds.lossless.metal,alt.binaries.sounds.lossless,alt.binaries.sounds.lossless.classical,alt.binaries.dvd.classics,alt.binaries.dvd.criterion,alt.binaries.dvd-freak,alt.binaries.dvd-r,alt.binaries.dvd',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_PROBE_GROUPS'] ?? null,
        );
        self::assertSame(
            '86400',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_YIELD_TTL_SECONDS'] ?? null,
        );
        self::assertSame(
            '3',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_EXPLOIT_ATTEMPTS_BEFORE_EXPLORE'] ?? null,
        );
        self::assertSame(
            '0.15',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_AGGRESSIVE_EXPLORE_BELOW_YIELD'] ?? null,
        );
        self::assertSame(
            '50000',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_CONTEXT_RETRY_QUANTITY'] ?? null,
        );
        self::assertSame(
            '300',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_ZERO_OUTPUT_GRACE_SECONDS'] ?? null,
        );
        self::assertSame(
            '0.20',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_HEADROOM_FRACTION'] ?? null,
        );
        self::assertSame(
            '600000',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_MAX_QUANTITY'] ?? null,
        );
        self::assertSame(
            '0.15',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_SCALE_MIN_YIELD'] ?? null,
        );
        self::assertSame(
            '3600',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_COHORT_POSTDATE_TOLERANCE_SECONDS'] ?? null,
        );
        self::assertSame(
            '1000',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_COLLECTIONS_PER_10K'] ?? null,
        );
        self::assertStringEndsWith(
            ':microservices-pods-20260712-part-gap-lock-v82',
            (string) ($deployments['nntmux-worker-backfill']['spec']['template']['spec']['containers'][0]['image'] ?? ''),
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
