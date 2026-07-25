<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Append-only repair evidence for complete split pairs stranded under a
 * quarantined current-forward root.
 *
 * This deliberately does not reopen or settle a lineage root. It preserves
 * the exact pre-existing integrity defect sets and proves that the local
 * repair and its eventual release attribution add no new defects.
 */
final class CurrentForwardTerminalSplitRepair
{
    private const string POLICY_VERSION = 'terminal-split-pair-repair-v1';

    /** @var list<string> */
    private const array OPEN_STATES = [
        'OFFERED',
        'CLAIMED',
        'INGESTED',
        'ATTRIBUTING',
        'CONTINUATION_PENDING',
    ];

    /** @var list<string> */
    private const array ALLOWED_FAILURES = [
        'current_forward_continuation_admission_timeout',
        'current_forward_continuation_deadline',
        'current_forward_continuation_chain_exhausted',
    ];

    private readonly CurrentForwardWindowLineage $lineage;

    public function __construct(?CurrentForwardWindowLineage $lineage = null)
    {
        $this->lineage = $lineage ?? new CurrentForwardWindowLineage;
    }

    /**
     * Record immutable evidence before the caller moves binaries and deletes
     * the source collection in the same transaction.
     *
     * Null means that the pair is not lineage-owned and must use the ordinary
     * handoff path. A terminal lineage pair that is not explicitly enabled is
     * rejected rather than silently falling through to the open-root API.
     *
     * @param  array{group:string,totalparts:int,span:int,gap:int,residual:int}  $facts
     * @return array<string,mixed>|null
     */
    public function beginPairRepair(int $sourceCollectionId, int $targetCollectionId, array $facts): ?array
    {
        $this->assertTransaction();
        if (! $this->schemaReady()) {
            return null;
        }

        $owners = DB::table('current_forward_object_owners')
            ->where('object_type', CurrentForwardWindowLineage::COLLECTION)
            ->whereIn('object_id', [$sourceCollectionId, $targetCollectionId])
            ->orderBy('object_id')
            ->lockForUpdate()
            ->get(['object_id', 'chain_root_id']);
        if ($owners->isEmpty()) {
            return null;
        }
        if ($owners->count() !== 2) {
            throw new RuntimeException('Terminal split repair collection ownership is incomplete.');
        }
        $rootIds = $this->positiveUnique($owners->pluck('chain_root_id')->all());
        if (count($rootIds) !== 1) {
            throw new RuntimeException('Terminal split repair crosses lineage roots.');
        }
        $rootId = $rootIds[0];
        $root = DB::table('current_forward_windows')->where('id', $rootId)->lockForUpdate()->first();
        if ($root === null || (string) $root->state !== 'QUARANTINED') {
            return null;
        }
        $group = trim((string) ($facts['group'] ?? ''));
        if (! $this->enabled($group, $rootId)) {
            throw new RuntimeException('Terminal split repair is not enabled for this group and root.');
        }

        $settingsHash = $this->lockedIdleSettingsHash();
        $chain = $this->lockedTerminalChain($rootId);
        $root = $chain->first(static fn (object $window): bool => (int) $window->id === $rootId);
        if ($root === null
            || (string) ($root->failure_reason ?? '') === ''
            || ! in_array((string) $root->failure_reason, self::ALLOWED_FAILURES, true)
            || ($root->settled_at ?? null) === null
        ) {
            throw new RuntimeException('Terminal split repair root evidence is not eligible.');
        }
        $this->assertDynamicFacts($facts);

        $memberships = DB::table('current_forward_window_objects')
            ->where('chain_root_id', $rootId)
            ->where('object_type', CurrentForwardWindowLineage::COLLECTION)
            ->whereIn('object_id', [$sourceCollectionId, $targetCollectionId])
            ->orderBy('object_id')
            ->orderByDesc('window_id')
            ->lockForUpdate()
            ->get(['object_id', 'window_id']);
        $sourceMembership = $memberships->first(
            static fn (object $membership): bool => (int) $membership->object_id === $sourceCollectionId,
        );
        $targetMembership = $memberships->first(
            static fn (object $membership): bool => (int) $membership->object_id === $targetCollectionId,
        );
        if ($sourceMembership === null || $targetMembership === null) {
            throw new RuntimeException('Terminal split repair collection membership is incomplete.');
        }

        $collections = DB::table('collections')
            ->whereIn('id', [$sourceCollectionId, $targetCollectionId])
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'filecheck', 'releases_id']);
        if ($collections->count() !== 2 || $collections->contains(
            static fn (object $collection): bool => (int) $collection->filecheck !== 0
                || $collection->releases_id !== null,
        )) {
            throw new RuntimeException('Terminal split repair collections are no longer mutable.');
        }

        $sourceBinaryIds = $this->liveBinaryIds($sourceCollectionId, true);
        $targetBinaryIds = $this->liveBinaryIds($targetCollectionId, true);
        if ($sourceBinaryIds === [] || $targetBinaryIds === []) {
            throw new RuntimeException('Terminal split repair live binary cohort is incomplete.');
        }
        $this->assertLineageBinaryCohort($rootId, $sourceCollectionId, $sourceBinaryIds);
        $this->assertLineageBinaryCohort($rootId, $targetCollectionId, $targetBinaryIds);
        $this->assertNoHandoffCycle($rootId, $sourceCollectionId, $targetCollectionId);

        $preDefects = $this->defects($rootId);
        $preBadSetHash = $this->hash($preDefects);
        $preObservationHash = (string) $this->lineage->observe($rootId)['hash'];
        $chainHash = $this->hash($chain->map(static fn (object $row): array => (array) $row)->all());
        $observationRowsHash = $this->observationRowsHash($rootId);
        $mergedBinaryIds = $this->positiveUnique([...$targetBinaryIds, ...$sourceBinaryIds]);
        $sourceBinaryHash = $this->hash($sourceBinaryIds);
        $targetBinaryHash = $this->hash($targetBinaryIds);
        $mergedBinaryHash = $this->hash($mergedBinaryIds);

        $handoff = [
            'source_collection_id' => $sourceCollectionId,
            'target_collection_id' => $targetCollectionId,
            'chain_root_id' => $rootId,
            'source_window_id' => (int) $sourceMembership->window_id,
            'target_window_id' => (int) $targetMembership->window_id,
            'moved_binary_count' => count($sourceBinaryIds),
            'moved_binary_ids_hash' => $sourceBinaryHash,
            'reason' => 'split_collection_merge',
            'handed_off_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('current_forward_collection_handoffs')->insertOrIgnore($handoff);
        $handoffRow = DB::table('current_forward_collection_handoffs')
            ->where('chain_root_id', $rootId)
            ->where('source_collection_id', $sourceCollectionId)
            ->lockForUpdate()
            ->first();
        if ($handoffRow === null
            || (int) $handoffRow->target_collection_id !== $targetCollectionId
            || (int) $handoffRow->source_window_id !== (int) $sourceMembership->window_id
            || (int) $handoffRow->target_window_id !== (int) $targetMembership->window_id
            || (int) $handoffRow->moved_binary_count !== count($sourceBinaryIds)
            || ! hash_equals((string) $handoffRow->moved_binary_ids_hash, $sourceBinaryHash)
            || (string) $handoffRow->reason !== 'split_collection_merge'
        ) {
            throw new RuntimeException('Terminal split repair handoff identity drift detected.');
        }

        $evidence = [
            'handoff_id' => (int) $handoffRow->id,
            'chain_root_id' => $rootId,
            'source_window_id' => (int) $sourceMembership->window_id,
            'target_window_id' => (int) $targetMembership->window_id,
            'source_collection_id' => $sourceCollectionId,
            'target_collection_id' => $targetCollectionId,
            'root_state' => 'QUARANTINED',
            'root_failure_reason' => (string) $root->failure_reason,
            'root_settled_at' => (string) $root->settled_at,
            'group_name' => $group,
            'source_binary_count' => count($sourceBinaryIds),
            'source_binary_ids_hash' => $sourceBinaryHash,
            'target_binary_count' => count($targetBinaryIds),
            'target_binary_ids_hash' => $targetBinaryHash,
            'merged_binary_count' => count($mergedBinaryIds),
            'merged_binary_ids_hash' => $mergedBinaryHash,
            'anchor_totalparts' => (int) $facts['totalparts'],
            'anchor_article_span' => (int) $facts['span'],
            'forward_article_gap' => (int) $facts['gap'],
            'residual' => (int) $facts['residual'],
            'policy_version' => self::POLICY_VERSION,
            'pre_observation_hash' => $preObservationHash,
            'pre_bad_set_hash' => $preBadSetHash,
            'chain_hash' => $chainHash,
            'observation_rows_hash' => $observationRowsHash,
        ];
        $evidenceHash = $this->hash($evidence);
        DB::table('current_forward_terminal_collection_repairs')->insertOrIgnore([
            ...$evidence,
            'evidence_hash' => $evidenceHash,
            'repaired_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $repair = DB::table('current_forward_terminal_collection_repairs')
            ->where('handoff_id', (int) $handoffRow->id)
            ->lockForUpdate()
            ->first();
        if ($repair === null || ! hash_equals((string) $repair->evidence_hash, $evidenceHash)) {
            throw new RuntimeException('Terminal split repair evidence identity drift detected.');
        }

        return [
            'repair_id' => (int) $repair->id,
            'root_id' => $rootId,
            'source_collection_id' => $sourceCollectionId,
            'target_collection_id' => $targetCollectionId,
            'merged_binary_count' => count($mergedBinaryIds),
            'merged_binary_ids_hash' => $mergedBinaryHash,
            'pre_defects' => $preDefects,
            'chain_hash' => $chainHash,
            'settings_hash' => $settingsHash,
            'observation_rows_hash' => $observationRowsHash,
        ];
    }

    /** @param array<string,mixed> $context */
    public function finishPairRepair(array $context): void
    {
        $this->assertTransaction();
        $rootId = (int) ($context['root_id'] ?? 0);
        $sourceId = (int) ($context['source_collection_id'] ?? 0);
        $targetId = (int) ($context['target_collection_id'] ?? 0);
        $repair = DB::table('current_forward_terminal_collection_repairs')
            ->where('id', (int) ($context['repair_id'] ?? 0))
            ->lockForUpdate()
            ->first();
        if ($repair === null
            || (int) $repair->chain_root_id !== $rootId
            || (int) $repair->source_collection_id !== $sourceId
            || (int) $repair->target_collection_id !== $targetId
            || (int) $repair->merged_binary_count !== (int) ($context['merged_binary_count'] ?? 0)
            || ! hash_equals(
                (string) $repair->merged_binary_ids_hash,
                (string) ($context['merged_binary_ids_hash'] ?? ''),
            )
        ) {
            throw new RuntimeException('Terminal split repair completion identity drift detected.');
        }
        if (DB::table('collections')->where('id', $sourceId)->exists()
            || DB::table('binaries')->where('collections_id', $sourceId)->exists()
        ) {
            throw new RuntimeException('Terminal split repair source was not removed atomically.');
        }
        $targetBinaryIds = $this->liveBinaryIds($targetId, true);
        if (count($targetBinaryIds) !== (int) $repair->merged_binary_count
            || ! hash_equals((string) $repair->merged_binary_ids_hash, $this->hash($targetBinaryIds))
        ) {
            throw new RuntimeException('Terminal split repair merged binary identity drift detected.');
        }
        $this->assertPostState($rootId, $context);
    }

    /**
     * Append terminal attribution evidence after the standard release row and
     * collection link are created in this same transaction.
     */
    public function recordReleaseAttribution(int $collectionId, int $releaseId): bool
    {
        $this->assertTransaction();
        if (! $this->schemaReady()) {
            return false;
        }
        $repairs = DB::table('current_forward_terminal_collection_repairs')
            ->where('target_collection_id', $collectionId)
            ->lockForUpdate()
            ->get();
        if ($repairs->isEmpty()) {
            return false;
        }
        if ($repairs->count() !== 1) {
            throw new RuntimeException('Terminal release attribution target is ambiguous.');
        }
        $repair = $repairs->first();
        $rootId = (int) $repair->chain_root_id;
        $settingsHash = $this->lockedIdleSettingsHash();
        $chain = $this->lockedTerminalChain($rootId);
        $root = $chain->first(static fn (object $window): bool => (int) $window->id === $rootId);
        if ($root === null
            || (string) $root->state !== 'QUARANTINED'
            || (string) $root->failure_reason !== (string) $repair->root_failure_reason
            || (string) $root->settled_at !== (string) $repair->root_settled_at
            || strtotime((string) $repair->repaired_at) < strtotime((string) $root->settled_at)
        ) {
            throw new RuntimeException('Terminal release attribution root evidence changed.');
        }
        $collection = DB::table('collections')->where('id', $collectionId)->lockForUpdate()->first();
        $release = DB::table('releases')->where('id', $releaseId)->lockForUpdate()->first();
        if ($collection === null || $release === null || (int) ($collection->releases_id ?? 0) !== $releaseId) {
            throw new RuntimeException('Terminal release attribution live identity is incomplete.');
        }
        $collectionOwner = DB::table('current_forward_object_owners')
            ->where('object_type', CurrentForwardWindowLineage::COLLECTION)
            ->where('object_id', $collectionId)
            ->lockForUpdate()
            ->first(['chain_root_id']);
        $collectionMembership = DB::table('current_forward_window_objects')
            ->where('object_type', CurrentForwardWindowLineage::COLLECTION)
            ->where('object_id', $collectionId)
            ->orderByDesc('window_id')
            ->lockForUpdate()
            ->first(['window_id', 'chain_root_id']);
        if ($collectionOwner === null
            || (int) $collectionOwner->chain_root_id !== $rootId
            || $collectionMembership === null
            || (int) $collectionMembership->chain_root_id !== $rootId
            || (int) $collectionMembership->window_id !== (int) $repair->target_window_id
        ) {
            throw new RuntimeException('Terminal release attribution target ownership changed.');
        }
        $targetBinaryIds = $this->liveBinaryIds($collectionId, true);
        $targetBinaryHash = $this->hash($targetBinaryIds);
        if (count($targetBinaryIds) !== (int) $repair->merged_binary_count
            || ! hash_equals((string) $repair->merged_binary_ids_hash, $targetBinaryHash)
        ) {
            throw new RuntimeException('Terminal release attribution binary identity drift detected.');
        }
        $binaryOwners = DB::table('current_forward_object_owners')
            ->where('object_type', CurrentForwardWindowLineage::BINARY)
            ->whereIn('object_id', $targetBinaryIds)
            ->orderBy('object_id')
            ->lockForUpdate()
            ->get(['object_id', 'chain_root_id']);
        if ($binaryOwners->count() !== count($targetBinaryIds)
            || $binaryOwners->contains(
                static fn (object $owner): bool => (int) $owner->chain_root_id !== $rootId,
            )
        ) {
            throw new RuntimeException('Terminal release attribution binary ownership changed.');
        }
        $this->assertRepairEvidenceIdentity($repair);

        $chainHash = $this->hash($chain->map(static fn (object $row): array => (array) $row)->all());
        $observationRowsHash = $this->observationRowsHash($rootId);
        if (! hash_equals((string) $repair->chain_hash, $chainHash)
            || ! hash_equals((string) $repair->observation_rows_hash, $observationRowsHash)
        ) {
            throw new RuntimeException('Terminal release attribution control evidence changed since repair.');
        }
        $targetWindowId = (int) $repair->target_window_id;
        DB::table('current_forward_object_owners')->insertOrIgnore([
            'object_type' => CurrentForwardWindowLineage::RELEASE,
            'object_id' => $releaseId,
            'chain_root_id' => $rootId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $owner = DB::table('current_forward_object_owners')
            ->where('object_type', CurrentForwardWindowLineage::RELEASE)
            ->where('object_id', $releaseId)
            ->lockForUpdate()
            ->first();
        if ($owner === null || (int) $owner->chain_root_id !== $rootId) {
            throw new RuntimeException('Terminal release attribution crosses lineage roots.');
        }
        DB::table('current_forward_window_objects')->insertOrIgnore([
            'window_id' => $targetWindowId,
            'chain_root_id' => $rootId,
            'object_type' => CurrentForwardWindowLineage::RELEASE,
            'object_id' => $releaseId,
            'parent_object_id' => $collectionId,
            'inserted_parts' => 0,
            'created_in_window' => true,
            'touched_in_window' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $object = DB::table('current_forward_window_objects')
            ->where('window_id', $targetWindowId)
            ->where('object_type', CurrentForwardWindowLineage::RELEASE)
            ->where('object_id', $releaseId)
            ->lockForUpdate()
            ->first();
        if ($object === null
            || (int) $object->chain_root_id !== $rootId
            || (int) $object->parent_object_id !== $collectionId
        ) {
            throw new RuntimeException('Terminal release attribution membership identity drift detected.');
        }

        $evidence = [
            'release_id' => $releaseId,
            'repair_id' => (int) $repair->id,
            'handoff_id' => (int) $repair->handoff_id,
            'chain_root_id' => $rootId,
            'window_id' => $targetWindowId,
            'target_collection_id' => $collectionId,
            'target_binary_count' => count($targetBinaryIds),
            'target_binary_ids_hash' => $targetBinaryHash,
            'release_categories_id' => (int) ($release->categories_id ?? 0),
            'release_nzbstatus' => (int) ($release->nzbstatus ?? 0),
            'release_size' => (int) ($release->size ?? 0),
            'policy_version' => self::POLICY_VERSION,
        ];
        $evidenceHash = $this->hash($evidence);
        DB::table('current_forward_terminal_release_attributions')->insertOrIgnore([
            ...$evidence,
            'evidence_hash' => $evidenceHash,
            'attributed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attribution = DB::table('current_forward_terminal_release_attributions')
            ->where('release_id', $releaseId)
            ->lockForUpdate()
            ->first();
        if ($attribution === null || ! hash_equals((string) $attribution->evidence_hash, $evidenceHash)) {
            throw new RuntimeException('Terminal release attribution evidence identity drift detected.');
        }

        if (! hash_equals((string) $repair->pre_bad_set_hash, $this->hash($this->defects($rootId)))) {
            throw new RuntimeException('Terminal release attribution changed the pre-repair lineage defect set.');
        }
        if (! hash_equals($chainHash, $this->terminalChainHash($rootId))
            || ! hash_equals($settingsHash, $this->lockedIdleSettingsHash())
            || ! hash_equals($observationRowsHash, $this->observationRowsHash($rootId))
        ) {
            throw new RuntimeException('Terminal release attribution changed immutable control evidence.');
        }

        return true;
    }

    /** @param array<string,mixed> $context */
    private function assertPostState(int $rootId, array $context): void
    {
        $this->assertDefectsUnchanged($rootId, (array) ($context['pre_defects'] ?? []));
        if (! hash_equals((string) ($context['chain_hash'] ?? ''), $this->terminalChainHash($rootId))
            || ! hash_equals((string) ($context['settings_hash'] ?? ''), $this->lockedIdleSettingsHash())
            || ! hash_equals(
                (string) ($context['observation_rows_hash'] ?? ''),
                $this->observationRowsHash($rootId),
            )
        ) {
            throw new RuntimeException('Terminal split repair changed immutable control evidence.');
        }
    }

    /** @param array<string,list<int>> $expected */
    private function assertDefectsUnchanged(int $rootId, array $expected): void
    {
        if ($this->defects($rootId) !== $expected) {
            throw new RuntimeException('Terminal split repair introduced a new lineage defect.');
        }
    }

    /** @return array<string,list<int>> */
    private function defects(int $rootId): array
    {
        $integrity = $this->lineage->integrity($rootId);

        return [
            'orphan_release_ids' => $integrity['orphan_release_ids'],
            'invalid_collection_handoff_ids' => $integrity['invalid_collection_handoff_ids'],
            'missing_collection_ids' => $integrity['missing_collection_ids'],
            'missing_binary_ids' => $integrity['missing_binary_ids'],
            'dangling_collection_release_ids' => $integrity['dangling_collection_release_ids'],
        ];
    }

    /** @return Collection<int,\stdClass> */
    private function lockedTerminalChain(int $rootId): Collection
    {
        $chain = DB::table('current_forward_windows')
            ->where(static fn ($query) => $query->where('id', $rootId)->orWhere('chain_root_id', $rootId))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $root = $chain->first(static fn (object $window): bool => (int) $window->id === $rootId);
        $rootFailure = (string) ($root->failure_reason ?? '');
        if ($chain->isEmpty()
            || $root === null
            || ! in_array($rootFailure, self::ALLOWED_FAILURES, true)
            || $chain->contains(static fn (object $window): bool => (string) $window->state !== 'QUARANTINED'
                || (string) ($window->failure_reason ?? '') !== $rootFailure
                || ($window->settled_at ?? null) === null)
        ) {
            throw new RuntimeException('Terminal split repair chain is not fully quarantined.');
        }
        if (DB::table('current_forward_windows')->whereIn('state', self::OPEN_STATES)->exists()) {
            throw new RuntimeException('Terminal split repair requires all current-forward input to be idle.');
        }

        return $chain;
    }

    private function assertRepairEvidenceIdentity(object $repair): void
    {
        $evidence = [
            'handoff_id' => (int) $repair->handoff_id,
            'chain_root_id' => (int) $repair->chain_root_id,
            'source_window_id' => (int) $repair->source_window_id,
            'target_window_id' => (int) $repair->target_window_id,
            'source_collection_id' => (int) $repair->source_collection_id,
            'target_collection_id' => (int) $repair->target_collection_id,
            'root_state' => (string) $repair->root_state,
            'root_failure_reason' => (string) $repair->root_failure_reason,
            'root_settled_at' => (string) $repair->root_settled_at,
            'group_name' => (string) $repair->group_name,
            'source_binary_count' => (int) $repair->source_binary_count,
            'source_binary_ids_hash' => (string) $repair->source_binary_ids_hash,
            'target_binary_count' => (int) $repair->target_binary_count,
            'target_binary_ids_hash' => (string) $repair->target_binary_ids_hash,
            'merged_binary_count' => (int) $repair->merged_binary_count,
            'merged_binary_ids_hash' => (string) $repair->merged_binary_ids_hash,
            'anchor_totalparts' => (int) $repair->anchor_totalparts,
            'anchor_article_span' => (int) $repair->anchor_article_span,
            'forward_article_gap' => (int) $repair->forward_article_gap,
            'residual' => (int) $repair->residual,
            'policy_version' => (string) $repair->policy_version,
            'pre_observation_hash' => (string) $repair->pre_observation_hash,
            'pre_bad_set_hash' => (string) $repair->pre_bad_set_hash,
            'chain_hash' => (string) $repair->chain_hash,
            'observation_rows_hash' => (string) $repair->observation_rows_hash,
        ];
        if (! hash_equals((string) $repair->evidence_hash, $this->hash($evidence))) {
            throw new RuntimeException('Terminal split repair evidence identity drift detected.');
        }
        $handoff = DB::table('current_forward_collection_handoffs')
            ->where('id', (int) $repair->handoff_id)
            ->lockForUpdate()
            ->first();
        if ($handoff === null
            || (int) $handoff->chain_root_id !== (int) $repair->chain_root_id
            || (int) $handoff->source_window_id !== (int) $repair->source_window_id
            || (int) $handoff->target_window_id !== (int) $repair->target_window_id
            || (int) $handoff->source_collection_id !== (int) $repair->source_collection_id
            || (int) $handoff->target_collection_id !== (int) $repair->target_collection_id
            || (int) $handoff->moved_binary_count !== (int) $repair->source_binary_count
            || ! hash_equals((string) $handoff->moved_binary_ids_hash, (string) $repair->source_binary_ids_hash)
            || (string) $handoff->reason !== 'split_collection_merge'
        ) {
            throw new RuntimeException('Terminal split repair handoff evidence identity drift detected.');
        }
    }

    private function terminalChainHash(int $rootId): string
    {
        $chain = $this->lockedTerminalChain($rootId);

        return $this->hash($chain->map(static fn (object $row): array => (array) $row)->all());
    }

    private function lockedIdleSettingsHash(): string
    {
        if (! Schema::hasTable('settings')) {
            throw new RuntimeException('Terminal split repair settings evidence is unavailable.');
        }
        $names = [
            'orchestrator_bf_permit', 'orchestrator_bf_claimed', 'orchestrator_bf_completed', 'orchestrator_bf_failed',
            'orchestrator_cf_permit', 'orchestrator_cf_claimed', 'orchestrator_cf_completed', 'orchestrator_cf_failed',
        ];
        $settings = DB::table('settings')->whereIn('name', $names)->orderBy('name')->lockForUpdate()->pluck('value', 'name');
        $bfClaimed = (int) $settings->get('orchestrator_bf_claimed', 0);
        $cfClaimed = (int) $settings->get('orchestrator_cf_claimed', 0);
        if ((int) $settings->get('orchestrator_bf_permit', 0) !== 0
            || (int) $settings->get('orchestrator_cf_permit', 0) !== 0
            || ($bfClaimed !== 0
                && $bfClaimed !== (int) $settings->get('orchestrator_bf_completed', 0)
                && $bfClaimed !== (int) $settings->get('orchestrator_bf_failed', 0))
            || ($cfClaimed !== 0
                && $cfClaimed !== (int) $settings->get('orchestrator_cf_completed', 0)
                && $cfClaimed !== (int) $settings->get('orchestrator_cf_failed', 0))
        ) {
            throw new RuntimeException('Terminal split repair requires idle backfill and current-forward inputs.');
        }

        return $this->hash($settings->all());
    }

    private function observationRowsHash(int $rootId): string
    {
        return $this->hash(DB::table('current_forward_continuation_observations')
            ->where('chain_root_id', $rootId)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all());
    }

    /** @return list<int> */
    private function liveBinaryIds(int $collectionId, bool $lock): array
    {
        $query = DB::table('binaries')->where('collections_id', $collectionId)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /** @param list<int> $binaryIds */
    private function assertLineageBinaryCohort(int $rootId, int $collectionId, array $binaryIds): void
    {
        $lineageIds = DB::table('current_forward_window_objects')
            ->where('chain_root_id', $rootId)
            ->where('object_type', CurrentForwardWindowLineage::BINARY)
            ->where('parent_object_id', $collectionId)
            ->distinct()
            ->orderBy('object_id')
            ->lockForUpdate()
            ->pluck('object_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($lineageIds !== $binaryIds) {
            throw new RuntimeException('Terminal split repair binary lineage is incomplete.');
        }
        $owners = DB::table('current_forward_object_owners')
            ->where('object_type', CurrentForwardWindowLineage::BINARY)
            ->whereIn('object_id', $binaryIds)
            ->orderBy('object_id')
            ->lockForUpdate()
            ->get(['object_id', 'chain_root_id']);
        if ($owners->count() !== count($binaryIds)
            || $owners->contains(static fn (object $owner): bool => (int) $owner->chain_root_id !== $rootId)
        ) {
            throw new RuntimeException('Terminal split repair binary ownership is incomplete.');
        }
    }

    private function assertNoHandoffCycle(int $rootId, int $sourceId, int $targetId): void
    {
        $edges = DB::table('current_forward_collection_handoffs')
            ->where('chain_root_id', $rootId)
            ->pluck('target_collection_id', 'source_collection_id')
            ->mapWithKeys(static fn (mixed $target, mixed $source): array => [(int) $source => (int) $target])
            ->all();
        $cursor = $targetId;
        for ($step = 0; $step <= count($edges); $step++) {
            if ($cursor === $sourceId) {
                throw new RuntimeException('Terminal split repair handoff cycle detected.');
            }
            if (! isset($edges[$cursor])) {
                return;
            }
            $cursor = (int) $edges[$cursor];
        }
        throw new RuntimeException('Terminal split repair handoff cycle detected.');
    }

    /** @param array<string,mixed> $facts */
    private function assertDynamicFacts(array $facts): void
    {
        $parts = (int) ($facts['totalparts'] ?? 0);
        $span = (int) ($facts['span'] ?? 0);
        $gap = (int) ($facts['gap'] ?? 0);
        $residual = (int) ($facts['residual'] ?? PHP_INT_MAX);
        if ($parts < 1 || $parts > 12000
            || $span < 1 || $span >= $parts
            || $gap < 1 || $gap > 12000
            || $residual < -3 || $residual > 0
            || $gap - max(1, $parts - $span) !== $residual
        ) {
            throw new RuntimeException('Terminal split repair dynamic evidence is invalid.');
        }
    }

    private function enabled(string $group, int $rootId): bool
    {
        $groups = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) config('nntmux.split_collection_terminal_pair_repair_groups', []),
        ))));
        $roots = $this->positiveUnique((array) config('nntmux.split_collection_terminal_pair_repair_roots', []));

        return count($groups) <= 16
            && count($roots) <= 16
            && in_array($group, $groups, true)
            && in_array($rootId, $roots, true);
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('current_forward_terminal_collection_repairs')
            && Schema::hasTable('current_forward_terminal_release_attributions')
            && Schema::hasTable('current_forward_collection_handoffs');
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Terminal split repair requires an active transaction.');
        }
    }

    /** @param list<mixed> $ids
     * @return list<int>
     */
    private function positiveUnique(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0,
        )));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
