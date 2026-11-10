<?php

namespace ErnestDefoe\CommunityGoals\Job;

use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Recount;
use Flarum\Queue\AbstractJob;

/** Rebuilds one goal's count in the background (see Recount). */
class RecountGoal extends AbstractJob
{
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
