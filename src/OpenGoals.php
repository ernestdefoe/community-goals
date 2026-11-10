<?php

namespace ErnestDefoe\CommunityGoals;

use Illuminate\Contracts\Cache\Repository;

/**
 * The goals that are still counting, kept in the cache so a new post on a
 * forum with no goal running costs one cache read and no query. Forgotten
 * whenever a goal is saved, closed or deleted (Goals::changed).
 *
 * 🚨 No memo on the instance: queue workers live for hours, and a memo would
 * keep counting a goal that was closed or edited since.
 */
class OpenGoals
{
    public const KEY = 'community-goals.open';

    public function __construct(private readonly Repository $cache)
    {
    }

    /**
     * Counted goals running right now, optionally only these metrics. A goal
     * that hasn't started or has ended is left alone even before the tick
     * closes it: things happening now can't be inside its window, and an
     * ended goal's number stays as it was at the end (no scheduler needed).
     *
     * @param list<string> $metrics
     * @return list<Goal>
     */
    public function get(array $metrics = []): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $rows = $this->cache->rememberForever(self::KEY, fn () => Goal::query()
            ->whereNull('closed_at')
            ->where('metric', '!=', Goal::CUSTOM)
            ->get(['id', 'metric', 'tag_id', 'target', 'starts_at', 'ends_at'])
            ->map(fn (Goal $goal) => [
                // Plain strings and ints: a cache store gives back exactly what it was handed.
                'id' => $goal->id,
                'metric' => $goal->metric,
                'tag_id' => $goal->tag_id,
                'target' => $goal->target,
                'starts_at' => $goal->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $goal->ends_at->format('Y-m-d H:i:s'),
            ])
            ->all());

        $goals = [];
        foreach ((array) $rows as $row) {
            if ($row['starts_at'] <= $now && $now < $row['ends_at'] && (! $metrics || in_array($row['metric'], $metrics, true))) {
                $goals[] = (new Goal())->newFromBuilder($row);
            }
        }

        return $goals;
    }

    public function forget(): void
    {
        $this->cache->forget(self::KEY);
    }
}
