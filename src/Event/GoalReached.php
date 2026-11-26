<?php

namespace ErnestDefoe\CommunityGoals\Event;

use ErnestDefoe\CommunityGoals\Goal;

/**
 * A goal was reached and finished: its helpers were told and its rewards
 * handed out. Dispatched exactly once per goal, from Job\CompleteGoal, by
 * the run that claims `rewarded_at` (a guarded update only one run can win).
 * Never on later saves, and never for goals finished before this event existed.
 *
 * Public API for other extensions: the class name and `$goal` are stable.
 */
class GoalReached
{
    public function __construct(public readonly Goal $goal)
    {
    }
}
