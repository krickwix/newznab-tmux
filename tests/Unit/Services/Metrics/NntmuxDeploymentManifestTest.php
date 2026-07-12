<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Metrics;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Parser;

final class NntmuxDeploymentManifestTest extends TestCase
{
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
        foreach ($manifest['items'] as $deployment) {
            if (! is_array($deployment) || ($deployment['kind'] ?? null) !== 'Deployment') {
                continue;
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
                ? ':microservices-pods-20260712-worker-orchestrator-v31'
                : (in_array($name, [
                    'nntmux-worker-binaries',
                    'nntmux-worker-releases',
                    'nntmux-worker-nzb-backlog',
                ], true)
                ? ':microservices-pods-20260711-worker-orchestrator-v22'
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
        self::assertStringEndsWith(
            ':microservices-pods-20260712-worker-orchestrator-v31',
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
        self::assertSame('microservices-pods-20260712-worker-orchestrator-v31', $metricsEnvironment['NNTMUX_BUILD_VERSION'] ?? null);
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
            ':microservices-pods-20260712-worker-orchestrator-v31',
            (string) ($orchestrator['spec']['template']['spec']['containers'][0]['image'] ?? ''),
        );
        self::assertSame(
            'microservices-pods-20260712-worker-orchestrator-v31',
            $environment($orchestrator)['NNTMUX_BUILD_VERSION'] ?? null,
        );
        self::assertNotContains('--shadow', $orchestrator['spec']['template']['spec']['containers'][0]['args'] ?? []);
        self::assertSame(
            'true',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_AUTO_BACKFILL'] ?? null,
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
            'alt.binaries.sounds.lossless.metal,alt.binaries.dvd.criterion,alt.binaries.dvd-freak,alt.binaries.dvd-r',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_PROBE_GROUPS'] ?? null,
        );
        self::assertSame(
            '86400',
            $environment($orchestrator)['NNTMUX_ORCHESTRATOR_BACKFILL_YIELD_TTL_SECONDS'] ?? null,
        );
        self::assertStringEndsWith(
            ':microservices-pods-20260712-worker-orchestrator-v31',
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
