<?php

namespace ErnestDefoe\CommunityGoals;

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

    /** @return list<array<string, mixed>> Summaries, tag-restricted ones included (filtered per viewer by Present::visible). */
    public function get(): array
    {
        return (array) $this->cache->remember(self::KEY, 60, function () {
            $now = Carbon::now();
            $goals = Goal::query()
                ->where('starts_at', '<=', $now)
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->whereNull('closed_at')->where('ends_at', '>', $now))
                    ->orWhere('hit_at', '>=', $now->copy()->subDays(2)))
                ->orderByRaw('CASE WHEN hit_at IS NULL THEN 0 ELSE 1 END')
                ->orderBy('ends_at')
                ->limit(self::CACHED)
                ->get();

            return $this->present->summaries($goals);
        });
    }

    public function forget(): void
    {
        $this->cache->forget(self::KEY);
    }
}
