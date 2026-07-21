<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Services\ReleaseProcessingService;

/**
 * Runs one cooperative, single-writer slice of the release pipeline.
 *
 * Deadline checks happen only between database-safe stage boundaries. A stage
 * is responsible for honoring the supplied batch size and never abandons a
 * collection while its release transaction is in progress.
 */
class ReleasePump
{
    protected ReleaseProcessingService $processing;

    public function __construct(?ReleaseProcessingService $processing = null)
    {
        $this->processing = $processing ?? app(ReleaseProcessingService::class);
    }

    /**
     * @return array{created:int, budget_exhausted:bool}
     */
    public function run(string $groupId, int $deadlineSeconds = 25, int $batchSize = 200): array
    {
        $deadlineSeconds = max(1, min(30, $deadlineSeconds));
        $batchSize = max(1, min(500, $batchSize));
        $deadlineAt = $this->monotonicNow() + $deadlineSeconds;

        $created = $this->drainReady($groupId, $batchSize, $deadlineAt);
        if ($this->deadlineReached($deadlineAt)) {
            return ['created' => $created, 'budget_exhausted' => true];
        }

        $this->advancePreparation($groupId, $batchSize, $deadlineAt);
        if (! $this->deadlineReached($deadlineAt)) {
            $created += $this->drainReady($groupId, $batchSize, $deadlineAt);
        }

        if (! $this->deadlineReached($deadlineAt)) {
            $this->cleanup($groupId, $batchSize);
        }

        return [
            'created' => $created,
            'budget_exhausted' => $this->deadlineReached($deadlineAt),
        ];
    }

    protected function drainReady(string $groupId, int $batchSize, float $deadlineAt): int
    {
        if ($this->deadlineReached($deadlineAt)) {
            return 0;
        }
        $ids = $this->processing->readyCollectionIds($groupId, $batchSize);
        if ($ids === []) {
            return 0;
        }

        $ids = $this->processing->filterReadyCollectionIds($groupId, $ids);
        if ($ids === [] || $this->deadlineReached($deadlineAt)) {
            return 0;
        }

        $result = $this->processing->createReleasesForCollectionIds($groupId, $ids, $deadlineAt);
        if (! $this->deadlineReached($deadlineAt)) {
            $this->processing->createNZBsIfEnabled($groupId);
        }

        return $result->added;
    }

    protected function advancePreparation(string $groupId, int $batchSize, float $deadlineAt): void
    {
        $this->processing->processIncompleteCollectionsSlice($groupId, $batchSize, $deadlineAt);
        if (! $this->deadlineReached($deadlineAt)) {
            $this->processing->processCollectionSizesSlice($groupId, $batchSize);
        }
    }

    protected function cleanup(string $groupId, int $batchSize): void
    {
        $this->processing->deleteCollectionsSlice($groupId, $batchSize);
    }

    /** @phpstan-impure */
    private function deadlineReached(float $deadlineAt): bool
    {
        return $this->monotonicNow() >= $deadlineAt;
    }

    protected function monotonicNow(): float
    {
        return microtime(true);
    }
}
