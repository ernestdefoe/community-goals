<?php

namespace ErnestDefoe\CommunityGoals;

use Carbon\Carbon;
use ErnestDefoe\CommunityGoals\Job\CompleteGoal;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;

/**
 * Moves the numbers as things happen. Something changed at a scope (one
 * post, one discussion, one member): for each open goal it can affect, the
 * ledger rows for that scope are compared with what Counting says they
 * should be now, the difference is written, and progress and contributors
 * move by exactly what was written. Running it twice changes nothing, which
 * is what makes deletes, restores, approvals and duplicate events safe.
 *
 * Every count change is a guarded UPDATE (never read-modify-write), and the
 * moment a goal is reached is claimed with `hit_at IS NULL`, so two posts
 * landing at once can't both complete it.
 */
class Tally
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly OpenGoals $open,
        private readonly Counting $counting,
        private readonly Board $board,
        private readonly Queue $queue,
    ) {
    }

    /** A post was written, hidden, restored, approved, deleted, liked or unliked. */
    public function post(int $postId, int $discussionId, bool $isFirst, bool $likesOnly = false): void
    {
        foreach ($this->open->get($likesOnly ? [Goal::LIKES] : [Goal::REPLIES, Goal::TAG_POSTS, Goal::LIKES]) as $goal) {
            $this->sync($goal, Counting::SCOPE_POST, $postId);
        }
        if ($isFirst && ! $likesOnly) {
            $this->discussion($discussionId, [Goal::DISCUSSIONS]);
        }
    }

    /**
     * A discussion was started, hidden, restored, approved or re-tagged; with
     * $gone, it is being deleted and everything it gave is taken back.
     *
     * @param list<string> $metrics
     */
    public function discussion(int $discussionId, array $metrics = [], bool $gone = false): void
    {
        foreach ($this->open->get($metrics ?: [Goal::REPLIES, Goal::TAG_POSTS, Goal::LIKES, Goal::DISCUSSIONS]) as $goal) {
            $this->sync($goal, Counting::SCOPE_DISCUSSION, $discussionId, $gone);
        }
    }

    /** A member joined or confirmed their email. */
    public function member(int $userId): void
    {
        foreach ($this->open->get([Goal::MEMBERS]) as $goal) {
            $this->sync($goal, Counting::SCOPE_USER, $userId);
        }
    }

    /**
     * A member's account is being deleted: open goals lose what they gave
     * (the next sync would anyway, but their rows would linger); closed goals
     * keep their final number and just forget the name.
     */
    public function forgetUser(int $userId): void
    {
        $byGoal = $this->db->table('community_goal_entries')
            ->join('community_goals', 'community_goals.id', '=', 'community_goal_entries.goal_id')
            ->where('community_goal_entries.user_id', $userId)
            ->whereNull('community_goals.closed_at')
            ->groupBy('community_goal_entries.goal_id')
            // Qualified columns through select(), never raw: raw SQL skips the table prefix.
            ->select('community_goal_entries.goal_id')
            ->selectRaw('count(*) as n')
            ->pluck('n', 'goal_id');

        $this->db->table('community_goal_entries')->where('user_id', $userId)->delete();

        foreach ($byGoal as $goalId => $n) {
            $this->adjust((int) $goalId, [$userId => -(int) $n]);
        }

        $goalIds = $this->db->table('community_goal_contributors')->where('user_id', $userId)->pluck('goal_id')->all();
        $this->db->table('community_goal_contributors')->where('user_id', $userId)->delete();
        foreach ($goalIds as $goalId) {
            $this->recountContributors((int) $goalId);
        }
        $this->board->forget();
    }

    /** Make the ledger at one scope match what should be counted there. */
    public function sync(Goal $goal, string $scope, int $id, bool $gone = false): void
    {
        if (! $this->counting->affects($goal, $scope)) {
            return;
        }

        $column = Counting::ledgerColumn($scope);
        $have = $this->db->table('community_goal_entries')
            ->where('goal_id', $goal->id)
            ->where($column, $id)
            ->get(['subject_id', 'user_id', 'discussion_id']);

        $want = [];
        if (! $gone) {
            $query = $this->counting->query($goal, $scope, $id);
            foreach ($query ? $query->get() : [] as $row) {
                $want[$row->subject_id.':'.$row->user_id] = $row;
            }
        }

        $haveKeys = [];
        foreach ($have as $row) {
            $haveKeys[$row->subject_id.':'.$row->user_id] = $row;
        }

        $deltas = [];

        // Grouped per member so each write's affected-row count is that member's change.
        $add = [];
        foreach (array_diff_key($want, $haveKeys) as $row) {
            $add[(int) $row->user_id][] = [
                'goal_id' => $goal->id,
                'subject_id' => (int) $row->subject_id,
                'user_id' => (int) $row->user_id,
                'discussion_id' => $row->discussion_id === null ? null : (int) $row->discussion_id,
            ];
        }
        foreach ($add as $userId => $rows) {
            if ($n = $this->db->table('community_goal_entries')->insertOrIgnore($rows)) {
                $deltas[$userId] = ($deltas[$userId] ?? 0) + $n;
            }
        }

        $remove = [];
        foreach (array_diff_key($haveKeys, $want) as $row) {
            $remove[(int) $row->user_id][] = (int) $row->subject_id;
        }
        foreach ($remove as $userId => $subjects) {
            $n = $this->db->table('community_goal_entries')
                ->where('goal_id', $goal->id)
                ->where('user_id', $userId)
                ->whereIn('subject_id', $subjects)
                ->delete();
            if ($n) {
                $deltas[$userId] = ($deltas[$userId] ?? 0) - $n;
            }
        }

        if ($deltas) {
            $this->adjust($goal->id, $deltas);
        }
    }

    /**
     * Move a goal's progress and its contributors by what was written.
     *
     * @param array<int, int> $deltas member id => change
     */
    public function adjust(int $goalId, array $deltas, int $extra = 0): void
    {
        $table = fn () => $this->db->table('community_goal_contributors');
        $membersChanged = false;

        foreach ($deltas as $userId => $delta) {
            if ($delta > 0) {
                $membersChanged = $table()->insertOrIgnore(['goal_id' => $goalId, 'user_id' => $userId, 'count' => 0]) > 0 || $membersChanged;
                $table()->where('goal_id', $goalId)->where('user_id', $userId)->increment('count', $delta);
            } elseif ($delta < 0) {
                $table()->where('goal_id', $goalId)->where('user_id', $userId)->where('count', '>=', -$delta)->decrement('count', -$delta);
                $membersChanged = $table()->where('goal_id', $goalId)->where('user_id', $userId)->where('count', '<=', 0)->delete() > 0 || $membersChanged;
            }
        }

        $total = array_sum($deltas) + $extra;
        $goals = fn () => $this->db->table('community_goals')->where('id', $goalId);

        if ($total > 0) {
            $goals()->increment('progress', $total);
        } elseif ($total < 0 && ! $goals()->where('progress', '>=', -$total)->decrement('progress', -$total)) {
            $goals()->update(['progress' => 0]);
        }

        if ($membersChanged) {
            $this->recountContributors($goalId);
        }

        $this->check($goalId);
        $this->board->forget();
    }

    /**
     * Record milestones and claim the moment the goal is reached. Milestones
     * only ever go up: a celebration that happened stays happened, and so
     * does a goal that was reached, even if a post is removed afterwards.
     */
    public function check(int $goalId): void
    {
        $goal = Goal::query()->find($goalId, ['id', 'target', 'progress', 'milestone', 'hit_at', 'closed_at', 'ends_at']);
        if (! $goal || $goal->closed_at) {
            return;
        }

        $milestone = $goal->milestoneFor($goal->progress);
        if ($milestone > $goal->milestone) {
            $this->db->table('community_goals')->where('id', $goalId)->where('milestone', '<', $milestone)->update(['milestone' => $milestone]);
        }

        if ($goal->hit_at === null && $goal->progress >= $goal->target) {
            $claimed = $this->db->table('community_goals')
                ->where('id', $goalId)
                ->whereNull('hit_at')
                ->whereNull('closed_at')
                ->update(['hit_at' => Carbon::now()->min($goal->ends_at)]);

            if ($claimed) {
                $this->queue->push(new CompleteGoal($goalId));
            }
        }
    }

    public function recountContributors(int $goalId): void
    {
        $count = $this->db->table('community_goal_contributors')->where('goal_id', $goalId)->count();
        $this->db->table('community_goals')->where('id', $goalId)->update(['contributors_count' => $count]);
    }
}
