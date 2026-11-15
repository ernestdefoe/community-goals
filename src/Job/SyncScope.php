<?php

namespace ErnestDefoe\CommunityGoals\Job;

use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Tally;
use Flarum\Queue\AbstractJob;

/**
 * A count a request couldn't make because another writer held the goal
 * (Tally::sync never waits in a request). The same scope is synced here,
 * waiting up to Tally::LOCK_WAIT; still busy, the queue tries again later.
 * The ledger makes it safe to run late or twice.
 */
class SyncScope extends AbstractJob
{
    public int $tries = 10;

    /** @var list<int> */
    public array $backoff = [2, 5, 10, 30, 60];

    /** @param list<int> $goalIds */
    public function __construct(
        public array $goalIds,
        public string $scope,
        public int $id,
        public bool $gone,
    ) {
    }

    public function handle(Tally $tally): void
    {
        // Goals closed or deleted since are left out: a closed goal's number is final.
        $goals = Goal::query()->whereIn('id', $this->goalIds)->whereNull('closed_at')->get()->all();
        if ($goals) {
            $tally->sync($goals, $this->scope, $this->id, $this->gone, Tally::LOCK_WAIT);
        }
    }
}
