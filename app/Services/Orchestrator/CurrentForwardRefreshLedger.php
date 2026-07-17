<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CurrentForwardRefreshLedger
{
    private const int WINDOW_SIZE = 10_000;

    private const int PROVIDER_RESERVE = 20_000;

    public function seedSource(string $group): int
    {
        $group = trim($group);
        $policy = new CurrentForwardStopCursorPolicy;
        $corridor = $policy->window($group);
        if (! $policy->isValid() || $corridor === null) {
            throw new RuntimeException("Current-forward source {$group} is not in the static trusted policy.");
        }

        return DB::transaction(function () use ($group, $corridor): int {
            $local = DB::table('usenet_groups')->where('name', $group)->lockForUpdate()->first();
            if ($local === null) {
                throw new RuntimeException("Local current-forward source {$group} does not exist.");
            }

            $existing = DB::table('current_forward_sources')->where('group_name', $group)->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->groups_id !== (int) $local->id
                    || (int) $existing->anchor_first !== $corridor['first']
                ) {
                    throw new RuntimeException("Current-forward source {$group} conflicts with its immutable trust anchor.");
                }

                return (int) $existing->id;
            }

            return (int) DB::table('current_forward_sources')->insertGetId([
                'groups_id' => (int) $local->id,
                'group_name' => $group,
                'anchor_first' => $corridor['first'],
                'audited_last' => $corridor['last'],
                'state' => 'PROBATION',
                'last_audited_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * @param  array{group:string,source_id:int,first:int,last:int,provider_first:int,provider_high:int,provider_observed_at:string}  $proposal
     * @param  array{headers:int,yenc_headers:int,multipart_headers:int,complete_binary_files:int,evidence_hash:string}  $audit
     */
    public function recordAudit(array $proposal, array $audit, string $policyVersion): int
    {
        $this->validateEvidence($proposal, $audit, $policyVersion);

        return DB::transaction(function () use ($proposal, $audit, $policyVersion): int {
            $source = DB::table('current_forward_sources')
                ->where('id', $proposal['source_id'])
                ->where('group_name', $proposal['group'])
                ->lockForUpdate()
                ->first();
            if ($source === null) {
                throw new RuntimeException('Current-forward audit source is not explicitly registered.');
            }
            if (! in_array((string) $source->state, ['PROBATION', 'READY'], true)) {
                throw new RuntimeException('Current-forward audit source is not eligible for new evidence.');
            }

            $existing = DB::table('current_forward_windows')
                ->where('source_id', $proposal['source_id'])
                ->where('first_article', $proposal['first'])
                ->where('last_article', $proposal['last'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $this->assertSameEvidence($existing, $proposal, $audit, $policyVersion);

                return (int) $existing->id;
            }
            if ($proposal['first'] !== (int) $source->audited_last + 1) {
                throw new RuntimeException('Current-forward audit is not the next append-only source window.');
            }

            $id = (int) DB::table('current_forward_windows')->insertGetId([
                'source_id' => $proposal['source_id'],
                'first_article' => $proposal['first'],
                'last_article' => $proposal['last'],
                'provider_first' => $proposal['provider_first'],
                'provider_high' => $proposal['provider_high'],
                'provider_observed_at' => $proposal['provider_observed_at'],
                'headers' => $audit['headers'],
                'yenc_headers' => $audit['yenc_headers'],
                'multipart_headers' => $audit['multipart_headers'],
                'complete_binary_files' => $audit['complete_binary_files'],
                'evidence_hash' => $audit['evidence_hash'],
                'policy_version' => $policyVersion,
                'state' => 'AUDITED',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('current_forward_sources')->where('id', $source->id)->update([
                'audited_last' => $proposal['last'],
                'state' => 'READY',
                'last_audited_at' => now(),
                'updated_at' => now(),
            ]);

            return $id;
        });
    }

    /**
     * @param  array{group:string,source_id:int,first:int,last:int,provider_first:int,provider_high:int,provider_observed_at:string}  $proposal
     * @param  array{headers:int,yenc_headers:int,multipart_headers:int,complete_binary_files:int,evidence_hash:string}  $audit
     */
    private function validateEvidence(array $proposal, array $audit, string $policyVersion): void
    {
        if ($proposal['source_id'] <= 0
            || $proposal['first'] <= 0
            || $proposal['last'] - $proposal['first'] + 1 !== self::WINDOW_SIZE
            || $proposal['provider_first'] <= 0
            || $proposal['provider_first'] > $proposal['first']
            || $proposal['last'] > PHP_INT_MAX - self::PROVIDER_RESERVE
            || $proposal['provider_high'] < $proposal['last'] + self::PROVIDER_RESERVE
        ) {
            throw new RuntimeException('Current-forward audit proposal violates the exact-window provider contract.');
        }
        if ($audit['headers'] < 9_000
            || $audit['yenc_headers'] / max(1, $audit['headers']) < 0.5
            || $audit['multipart_headers'] < 1
            || $audit['complete_binary_files'] < 1
            || preg_match('/^[a-f0-9]{64}$/D', $audit['evidence_hash']) !== 1
            || preg_match('/^[A-Za-z0-9._-]{1,32}$/D', $policyVersion) !== 1
        ) {
            throw new RuntimeException('Current-forward audit evidence does not satisfy the quality contract.');
        }
    }

    /**
     * @param  array{group:string,source_id:int,first:int,last:int,provider_first:int,provider_high:int,provider_observed_at:string}  $proposal
     * @param  array{headers:int,yenc_headers:int,multipart_headers:int,complete_binary_files:int,evidence_hash:string}  $audit
     */
    private function assertSameEvidence(object $existing, array $proposal, array $audit, string $policyVersion): void
    {
        $same = (int) $existing->provider_first === $proposal['provider_first']
            && (int) $existing->provider_high === $proposal['provider_high']
            && (string) $existing->provider_observed_at === $proposal['provider_observed_at']
            && (int) $existing->headers === $audit['headers']
            && (int) $existing->yenc_headers === $audit['yenc_headers']
            && (int) $existing->multipart_headers === $audit['multipart_headers']
            && (int) $existing->complete_binary_files === $audit['complete_binary_files']
            && hash_equals((string) $existing->evidence_hash, $audit['evidence_hash'])
            && (string) $existing->policy_version === $policyVersion;
        if (! $same) {
            throw new RuntimeException('Current-forward window evidence is immutable and cannot be rewritten.');
        }
    }
}
