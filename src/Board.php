<?php

namespace ErnestDefoe\CommunityGoals;

use Flarum\User\User;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository;

/**
 * The goals the index page shows: everything running now, plus a goal that
 * was reached in the last two days so the forum can still celebrate it.
 * Built once and cached (a minute at most, so a goal that starts or ends
 * appears or goes without anyone saving it); every count change forgets it.
 * It rides in the forum payload, so the banner draws with the page, with no
 * request of its own.
 */
class Board
{
    public const KEY = 'community-goals.board';

    /** At most this many goals on the index page; the rest are on /goals. */
    public const LIMIT = 3;

    /** Cached for everyone: more than LIMIT, so a viewer who can't see a tag goal still gets three. */
    public const CACHED = 10;

    public function __construct(
        private readonly Repository $cache,
        private readonly Present $present,
    ) {
    }

    /**
     * The banner as this viewer sees it: the cached goals minus tag goals they
     * can't read, at most LIMIT. No query on a cache hit.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleTo(User $actor): array
    {
        return array_slice($this->present->visible($actor, $this->get()), 0, self::LIMIT);
    }

    /** @return list<array<string, mixed>> Summaries, tag-restricted ones included (filtered per viewer by Present::visible). */
    public function get(): array
    {
        return (array) $this->cache->remember(self::KEY, 60, function () {
            $now = Carbon::now();
            // Running goals first, then reached ones (still open, or reached in the last two days and
            // still celebrated): two capped queries on a cache miss, no ordering by a computed column.
            $running = Goal::query()
                ->where('starts_at', '<=', $now)
                ->whereNull('hit_at')
                ->whereNull('closed_at')
                ->where('ends_at', '>', $now)
                ->orderBy('ends_at')
                ->limit(self::CACHED)
                ->get();
            $reached = $running->count() < self::CACHED ? Goal::query()
                ->where('starts_at', '<=', $now)
                ->whereNotNull('hit_at')
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->whereNull('closed_at')->where('ends_at', '>', $now))
                    ->orWhere('hit_at', '>=', $now->copy()->subDays(2)))
                ->orderBy('ends_at')
                ->limit(self::CACHED - $running->count())
                ->get() : collect();

            return $this->present->summaries($running->concat($reached));
        });
    }

    public function forget(): void
    {
        $this->cache->forget(self::KEY);
    }
}
