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
 *
 * Every step is recorded as it is done, so the job can be run again at any
 * point and only finishes what is left: helpers are told once (`_notified`
 * in reward_state, and NotificationSyncer never duplicates anyway), each
 * reward is saved to reward_state the moment it's handed out, and
 * `rewarded_at` is set LAST. One run at a time holds a lease
 * (`completing_at`); a lease older than LEASE_MINUTES is a run that died,
 * and the tick takes it over. So a throw or a killed worker delays the
 * rewards, never loses them.
 */
class CompleteGoal extends AbstractJob
{
    /** Notifications go to at most this many helpers (the most active first). */
    public const MAX_NOTIFIED = 5000;

    public const LEASE_MINUTES = 15;

    public function __construct(public int $goalId)
    {
    }

    public function handle(ConnectionInterface $db, NotificationSyncer $notifications, Rewards $rewards, Board $board): void
    {
        $now = Carbon::now();
        $claimed = $db->table('community_goals')
            ->where('id', $this->goalId)
            ->whereNotNull('hit_at')
            ->whereNull('rewarded_at')
            ->where(fn ($q) => $q->whereNull('completing_at')->orWhere('completing_at', '<', $now->copy()->subMinutes(self::LEASE_MINUTES)))
            ->update(['completing_at' => $now]);
        if (! $claimed) {
            return;
        }

        $goal = Goal::query()->find($this->goalId);
        if (! $goal) {
            return;
        }

        $state = $goal->reward_state ?? [];
        if (empty($state['_notified'])) {
            // Ids first: MySQL refuses LIMIT inside an IN subquery.
            $ids = $db->table('community_goal_contributors')->where('goal_id', $goal->id)
                ->orderByDesc('count')->limit(self::MAX_NOTIFIED)->pluck('user_id')->all();
            $helpers = $ids ? User::query()->whereIn('id', $ids)->get()->all() : [];
            if ($helpers) {
                $notifications->sync(new GoalReachedBlueprint($goal), $helpers);
            }
            $state['_notified'] = true;
            $goal->reward_state = $state;
            $goal->save();
        }

        $rewards->apply($goal, function (array $state) use ($goal) {
            $goal->reward_state = $state;
            $goal->save();
        });

        $db->table('community_goals')->where('id', $goal->id)->update(['rewarded_at' => Carbon::now(), 'completing_at' => null]);
        $board->forget();
    }
}
