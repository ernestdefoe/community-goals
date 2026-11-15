<?php

namespace ErnestDefoe\CommunityGoals\Job;

use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Recount;
use Flarum\Queue\AbstractJob;

/** Rebuilds one goal's count in the background (see Recount). */
class RecountGoal extends AbstractJob
{
    /** Recount waits Tally::LOCK_WAIT for a busy goal; the queue tries again later. */
    public int $tries = 10;

    /** @var list<int> */
    public array $backoff = [2, 5, 10, 30, 60];

    public function __construct(public int $goalId)
    {
    }

    public function handle(Recount $recount): void
    {
        if ($goal = Goal::query()->find($this->goalId)) {
            $recount->run($goal);
        }
    }
}
