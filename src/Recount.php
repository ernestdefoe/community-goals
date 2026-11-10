<?php

namespace ErnestDefoe\CommunityGoals;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Expression;

/**
 * Rebuilds a goal's ledger from the forum's own tables: when a goal is
 * created with a window that already started, when its metric, tag or window
 * is edited, and when an admin asks (the Recount button, or
 * `php flarum community-goals:recount`). The only heavy count there is, and
 * never on a page view.
 *
 * It replaces the ledger in one transaction with two INSERT … SELECTs, sets
 * progress to the ledger's size, and then lets Tally::check record
 * milestones and claim the goal if the count reaches it.
 */
class Recount
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Counting $counting,
        private readonly Tally $tally,
        private readonly Board $board,
    ) {
    }

    public function run(Goal $goal): void
    {
        if (! $goal->isCounted() || $goal->closed_at) {
            return;
        }

        $this->db->transaction(function () use ($goal) {
            // Tally waits on this lock, so nothing is counted twice or lost while the ledger is rebuilt.
            $this->db->table('community_goals')->where('id', $goal->id)->lockForUpdate()->value('id');
            $this->db->table('community_goal_entries')->where('goal_id', $goal->id)->delete();
            $this->db->table('community_goal_contributors')->where('goal_id', $goal->id)->delete();

            $query = $this->counting->query($goal);
            if ($query) {
                // The goal id is an int we hold, never input: safe as a literal column.
                $query->columns = [new Expression($goal->id.' as goal_id'), ...$query->columns];
                $this->db->table('community_goal_entries')->insertOrIgnoreUsing(['goal_id', 'subject_id', 'user_id', 'discussion_id'], $query);
            }

            $this->db->table('community_goal_contributors')->insertUsing(
                ['goal_id', 'user_id', 'count'],
                $this->db->table('community_goal_entries')
                    ->where('goal_id', $goal->id)
                    ->groupBy('goal_id', 'user_id')
                    ->select(['goal_id', 'user_id'])
                    ->selectRaw('count(*)')
            );

            $progress = $this->db->table('community_goal_entries')->where('goal_id', $goal->id)->count();
            $contributors = $this->db->table('community_goal_contributors')->where('goal_id', $goal->id)->count();

            $this->db->table('community_goals')->where('id', $goal->id)->update([
                'progress' => $progress,
                'contributors_count' => $contributors,
                'counted_at' => Carbon::now(),
            ]);
        });

        $this->tally->check($goal->id);
        $this->board->forget();
    }
}
