<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CollectionFileCheckStatus;
use App\Models\Collection;
use App\Support\TransientDatabaseError;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deletes collections whose only binary holds one part of a many-part file
 * long after the collection was first seen.
 *
 * Per-article obfuscated posts give every article its own random subject
 * and poster, so each article becomes a collection of one binary with one
 * part that no later article can join. Depending on the subject shape the
 * part total also lands in totalfiles, so totalfiles is not a filter. A
 * real post of that size arrives within minutes, so after the age cutoff
 * such a collection cannot reach completion and would otherwise sit in the
 * backlog until collection_timeout.
 *
 * Shared by the release worker's cleanup stage and the dedicated
 * hopeless-purge lane, so both apply exactly the same test.
 */
final class HopelessCollectionPurger
{
    private const int MAX_RETRIES = 5;

    private const int RETRY_BASE_DELAY_US = 20000;

    public function __construct(
        private readonly CollectionCleanupService $collectionCleanupService = new CollectionCleanupService,
    ) {}

    /**
     * Scan one page of collection ids after $after and delete the hopeless ones.
     *
     * `next` is the cursor for the following page, or 0 once the page
     * reached the last collection.
     *
     * @return array{next: int, scanned: int, deleted: int}
     *
     * @throws Throwable
     */
    public function purgePage(
        int $groupId,
        int $after,
        DateTimeInterface $cutoff,
        int $minParts,
        int $window,
        bool $echoCLI = false,
    ): array {
        $minParts = max(2, $minParts);
        $window = max(1, $window);

        $page = $this->retryTransient(
            fn (): array => Collection::query()
                ->whereIn('filecheck', [
                    CollectionFileCheckStatus::Default->value,
                    CollectionFileCheckStatus::CompleteCollection->value,
                ])
                ->where('id', '>', $after)
                ->when($groupId !== 0, static fn ($q) => $q->where('groups_id', $groupId))
                ->orderBy('id')
                ->limit($window)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all()
        );
        if ($page === []) {
            return ['next' => 0, 'scanned' => 0, 'deleted' => 0];
        }

        $ids = $this->retryTransient(
            static fn (): array => DB::table('collections as c')
                ->join('binaries as b', 'b.collections_id', '=', 'c.id')
                ->whereIn('c.id', $page)
                ->whereNull('c.releases_id')
                ->where('c.dateadded', '<', $cutoff)
                ->groupBy('c.id')
                ->havingRaw('COUNT(b.id) = 1')
                ->havingRaw('MAX(b.currentparts) <= 1')
                ->havingRaw('MIN(b.totalparts) >= ?', [$minParts])
                ->havingRaw('MAX((SELECT COUNT(*) FROM parts p WHERE p.binaries_id = b.id)) <= 1')
                ->pluck('c.id')
                ->map(static fn ($id): int => (int) $id)
                ->all()
        );

        $deleted = $ids === [] ? 0 : $this->collectionCleanupService->deleteCollectionsAndDescendants(
            $ids,
            'Hopeless single-part collections cleanup',
            $echoCLI
        );

        return [
            'next' => \count($page) < $window ? 0 : max($page),
            'scanned' => \count($page),
            'deleted' => $deleted,
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     *
     * @throws Throwable
     */
    private function retryTransient(callable $operation): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $operation();
            } catch (Throwable $e) {
                if (! TransientDatabaseError::is($e) || $attempt >= self::MAX_RETRIES) {
                    throw $e;
                }

                $attempt++;
                usleep(self::RETRY_BASE_DELAY_US * (2 ** $attempt));
            }
        }
    }
}
