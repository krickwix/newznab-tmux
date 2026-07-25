<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class CurrentForwardRefreshLedger
{
    private const int WINDOW_SIZE = 10_000;

    /**
     * Register at most one locally available source from the exact configured
     * trust anchors. Registration is intentionally separate from permit issue.
     *
     * @return array{group:string,source_id:int}|null
     */
    public function seedNextConfiguredSource(): ?array
    {
        $policy = new CurrentForwardRefreshTrustPolicy;
        if (! $policy->isValid()) {
            throw new RuntimeException('Current-forward refresh trust policy is invalid.');
        }

        foreach ($policy->groups() as $group) {
            if (DB::table('current_forward_sources')->where('group_name', $group)->exists()
                || ! DB::table('usenet_groups')->where('name', $group)->exists()
            ) {
                continue;
            }

            return [
                'group' => $group,
                'source_id' => $this->seedSource($group),
            ];
        }

        return null;
    }

    public function seedSource(string $group): int
    {
        $group = trim($group);
        $policy = new CurrentForwardRefreshTrustPolicy;
        $corridor = $policy->anchor($group);
        if (! $policy->isValid() || $corridor === null) {
            throw new RuntimeException("Current-forward source {$group} is not in the explicit refresh trust policy.");
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
            if ($policyVersion === 'exact-xover-continuation-v1') {
                $this->assertPendingContinuationProposal($proposal);
            }

            $existing = DB::table('current_forward_windows')
                ->where('source_id', $proposal['source_id'])
                ->where('first_article', $proposal['first'])
                ->where('last_article', $proposal['last'])
                ->when(
                    Schema::hasColumn('current_forward_windows', 'attempt_ordinal'),
                    static fn ($query) => $query->orderByDesc('attempt_ordinal'),
                )
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ((string) $existing->state === 'QUARANTINED') {
                    $group = DB::table('usenet_groups')
                        ->where('id', $source->groups_id)
                        ->where('name', $source->group_name)
                        ->lockForUpdate()
                        ->first();
                    if (($proposal['mode'] ?? null) !== 'RETRY'
                        || (int) ($proposal['window_id'] ?? 0) !== (int) $existing->id
                        || (int) ($proposal['retry_of_window_id'] ?? 0) !== (int) $existing->id
                        || (int) ($proposal['attempt_ordinal'] ?? 0) !== (int) ($existing->attempt_ordinal ?? 1) + 1
                        || $group === null
                        || ! (new CurrentForwardWindowRetryPolicy)->eligible($existing, $source, $group)
                    ) {
                        throw new RuntimeException('Current-forward terminal window is not safe to retry.');
                    }

                    return $this->recordRetryAttempt($existing, $source, $proposal, $audit, $policyVersion);
                }
                if ((string) $existing->state !== 'AUDITED'
                    || $existing->generation !== null
                ) {
                    throw new RuntimeException('Current-forward window cannot be reverified after issuance.');
                }
                if (! Schema::hasTable('current_forward_window_verifications')) {
                    $this->assertSameEvidence($existing, $proposal, $audit, $policyVersion);

                    return (int) $existing->id;
                }
                $this->recordVerification((int) $existing->id, $proposal, $audit, $policyVersion);
                DB::table('current_forward_windows')->where('id', $existing->id)->update([
                    'failure_reason' => null,
                    'updated_at' => now(),
                ]);
                DB::table('current_forward_sources')->where('id', $source->id)->update([
                    'state' => 'READY',
                    'last_audited_at' => now(),
                    'last_reason' => null,
                    'updated_at' => now(),
                ]);

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
            if (Schema::hasColumn('current_forward_windows', 'chain_root_id')) {
                DB::table('current_forward_windows')->where('id', $id)->update([
                    'chain_root_id' => $id,
                    'chain_ordinal' => 1,
                    'updated_at' => now(),
                ]);
            }
            if (Schema::hasTable('current_forward_window_verifications')) {
                $this->recordVerification($id, $proposal, $audit, $policyVersion);
            }

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
     * @param  array<string, mixed>  $proposal
     * @param  array{headers:int,yenc_headers:int,multipart_headers:int,complete_binary_files:int,evidence_hash:string}  $audit
     */
    private function recordRetryAttempt(
        object $predecessor,
        object $source,
        array $proposal,
        array $audit,
        string $policyVersion,
    ): int {
        $id = (int) DB::table('current_forward_windows')->insertGetId([
            'source_id' => $proposal['source_id'],
            'attempt_ordinal' => $proposal['attempt_ordinal'],
            'retry_of_window_id' => $predecessor->id,
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
        if (Schema::hasColumn('current_forward_windows', 'chain_root_id')) {
            DB::table('current_forward_windows')->where('id', $id)->update([
                'chain_root_id' => $id,
                'parent_window_id' => null,
                'chain_ordinal' => 1,
                'continuation_deadline_at' => null,
                'updated_at' => now(),
            ]);
        }
        $this->recordVerification($id, $proposal, $audit, $policyVersion);
        DB::table('current_forward_sources')->where('id', $source->id)->update([
            'last_audited_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  array{group:string,source_id:int,first:int,last:int,provider_first:int,provider_high:int,provider_observed_at:string}  $proposal
     * @param  array{headers:int,yenc_headers:int,multipart_headers:int,complete_binary_files:int,evidence_hash:string}  $audit
     */
    private function recordVerification(int $windowId, array $proposal, array $audit, string $policyVersion): int
    {
        $idempotencyKey = hash('sha256', implode('|', [
            $windowId,
            $proposal['provider_first'],
            $proposal['provider_high'],
            $proposal['provider_observed_at'],
            $audit['headers'],
            $audit['yenc_headers'],
            $audit['multipart_headers'],
            $audit['complete_binary_files'],
            $audit['evidence_hash'],
            $policyVersion,
        ]));
        $existingId = DB::table('current_forward_window_verifications')
            ->where('window_id', $windowId)
            ->where('idempotency_key', $idempotencyKey)
            ->value('id');
        if ($existingId !== null) {
            return (int) $existingId;
        }

        DB::table('current_forward_window_verifications')->insertOrIgnore([
            'window_id' => $windowId,
            'provider_first' => $proposal['provider_first'],
            'provider_high' => $proposal['provider_high'],
            'provider_observed_at' => $proposal['provider_observed_at'],
            'headers' => $audit['headers'],
            'yenc_headers' => $audit['yenc_headers'],
            'multipart_headers' => $audit['multipart_headers'],
            'complete_binary_files' => $audit['complete_binary_files'],
            'evidence_hash' => $audit['evidence_hash'],
            'policy_version' => $policyVersion,
            'idempotency_key' => $idempotencyKey,
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('current_forward_window_verifications')
            ->where('window_id', $windowId)
            ->where('idempotency_key', $idempotencyKey)
            ->value('id');
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
            || ! CurrentForwardProviderCoverage::covers(
                $proposal['provider_first'],
                $proposal['provider_high'],
                $proposal['first'],
                $proposal['last'],
            )
        ) {
            throw new RuntimeException('Current-forward audit proposal violates the exact-window provider contract.');
        }
        if ($audit['headers'] < 9_000
            || $audit['yenc_headers'] / max(1, $audit['headers']) < 0.5
            || $audit['multipart_headers'] < 1
            || ($audit['complete_binary_files'] < 1 && $policyVersion !== 'exact-xover-continuation-v1')
            || preg_match('/^[a-f0-9]{64}$/D', $audit['evidence_hash']) !== 1
            || preg_match('/^[A-Za-z0-9._-]{1,32}$/D', $policyVersion) !== 1
        ) {
            throw new RuntimeException('Current-forward audit evidence does not satisfy the quality contract.');
        }
    }

    /**
     * @param  array{group:string,source_id:int,first:int,last:int,provider_first:int,provider_high:int,provider_observed_at:string}  $proposal
     */
    private function assertPendingContinuationProposal(array $proposal): void
    {
        if (! config('nntmux.orchestrator.current_forward_continuation_enabled', false)
            || ! Schema::hasColumn('current_forward_windows', 'chain_root_id')
            || ! Schema::hasColumn('current_forward_windows', 'continuation_deadline_at')
        ) {
            throw new RuntimeException('Partial audit evidence requires an enabled continuation chain.');
        }
        $root = DB::table('current_forward_windows')
            ->where('source_id', $proposal['source_id'])
            ->where('state', 'CONTINUATION_PENDING')
            ->whereColumn('id', 'chain_root_id')
            ->lockForUpdate()
            ->first();
        $deadline = strtotime((string) ($root->continuation_deadline_at ?? ''));
        if ($root === null || $deadline === false || time() >= $deadline) {
            throw new RuntimeException('Partial audit evidence requires an open unexpired continuation root.');
        }
        $latestLast = DB::table('current_forward_windows')
            ->where('chain_root_id', $root->id)
            ->whereIn('state', ['CONTINUATION_PENDING', 'CHAINED'])
            ->lockForUpdate()
            ->max('last_article');
        if ($latestLast === null || (int) $latestLast + 1 !== $proposal['first']) {
            throw new RuntimeException('Partial audit evidence is not immediately adjacent to the open chain.');
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
