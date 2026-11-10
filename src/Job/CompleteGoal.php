<?php

namespace ErnestDefoe\CommunityGoals\Job;

use Carbon\Carbon;
use ErnestDefoe\CommunityGoals\Board;
use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Notification\GoalReachedBlueprint;
use ErnestDefoe\CommunityGoals\Rewards;
use Flarum\Notification\NotificationSyncer;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * A goal was reached: tell everyone who helped and hand out the rewards.
 * Queued, so the post that tipped it over isn't held up. Claimed once with
 * `rewarded_at IS NULL`, and each reward is recorded as it is done, so a
 * retried job neither notifies twice nor rewards twice.
 */
class CompleteGoal extends AbstractJob
{
    /** Notifications go to at most this many helpers (the most active first). */
    public const MAX_NOTIFIED = 5000;

    public function __construct(public int $goalId)
    {
    }

    public function handle(ConnectionInterface $db, NotificationSyncer $notifications, Rewards $rewards, Board $board): void
    {
        $claimed = $db->table('community_goals')->where('id', $this->goalId)->whereNotNull('hit_at')->whereNull('rewarded_at')
            ->update(['rewarded_at' => Carbon::now()]);
        if (! $claimed) {
            return;
        }

        $goal = Goal::query()->with('creator')->find($this->goalId);
        if (! $goal) {
            return;
        }

        // Ids first: MySQL refuses LIMIT inside an IN subquery.
        $ids = $db->table('community_goal_contributors')->where('goal_id', $goal->id)
            ->orderByDesc('count')->limit(self::MAX_NOTIFIED)->pluck('user_id')->all();
        $helpers = $ids ? User::query()->whereIn('id', $ids)->get()->all() : [];
        if ($helpers) {
            $notifications->sync(new GoalReachedBlueprint($goal), $helpers);
        }

        $goal->reward_state = $rewards->apply($goal);
        $goal->save();
        $board->forget();
    }
}
