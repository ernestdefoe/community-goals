<?php

namespace ErnestDefoe\CommunityGoals\Reward;

use ErnestDefoe\CommunityGoals\Goal;

interface Reward
{
    /** What the goal form offers (a list of choices), as plain data. */
    public function options(): mixed;

    /**
     * The admin's settings for this reward, cleaned; null drops it.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    public function clean(array $config): ?array;

    /**
     * Hand it out. Returns what was done; an `until` timestamp makes it timed.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function apply(Goal $goal, array $config): array;

    /**
     * Take a timed reward back.
     *
     * @param array<string, mixed> $done
     */
    public function revert(Goal $goal, array $done): void;
}
