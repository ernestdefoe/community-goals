<?php

namespace ErnestDefoe\CommunityGoals;

use Carbon\Carbon;
use ErnestDefoe\CommunityGoals\Job\CompleteGoal;
use ErnestDefoe\CommunityGoals\Job\SyncScope;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Moves the numbers as things happen. Something changed at a scope (one
 * post, one discussion, one member): for every running goal it can affect,
 * the ledger rows for that scope are compared with what Counting says they
 * should be now, the difference is written, and progress and contributors
 * move by exactly what was written. Running it twice changes nothing, which
 * is what makes deletes, restores, approvals and duplicate events safe.
 *
 * All the goals at a scope are done together, in a fixed number of queries:
 * one lock on their rows (a Recount takes the same lock), one ledger read,
 * one facts query per kind of thing counted (Counting::facts, sorted to each
 * goal in PHP), and batched writes. Ten running goals cost what one does.
 *
 * Every count change is a guarded UPDATE (never read-modify-write), and the
 * moment a goal is reached is claimed with `hit_at IS NULL`, so two posts
 * landing at once can't both complete it.
 */
class Tally
{
    /** Tries per transaction: a lock conflict with a Recount or the queue is retried, not lost. */
    public const ATTEMPTS = 3;

    /** A goal's writer lock is held at most this long. */
    public const LOCK_SECONDS = 30;

    /**
     * How long queued work (SyncScope, Recount) waits for a busy goal before
     * the queue tries again later. A request never waits (see sync()).
     */
    public const LOCK_WAIT = 1;

    /** @var array<int, int> Goals reached inside a sync, finished once it commits. */
    private array $finishing = [];

    private bool $inSync = false;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly OpenGoals $open,
        private readonly Counting $counting,
        private readonly Board $board,
        private readonly Queue $queue,
        private readonly LoggerInterface $log,
        private readonly Cache $cache,
    ) {
    }

    /**
     * A post was written, hidden, restored, approved, deleted, liked or
     * unliked; $metrics narrows which goals can care (a new post has no likes).
     *
     * @param list<string> $metrics
     */
    public function post(int $postId, int $discussionId, bool $isFirst, array $metrics = [Goal::REPLIES, Goal::TAG_POSTS, Goal::LIKES]): void
    {
        $this->sync($this->open->get($metrics), Counting::SCOPE_POST, $postId);
        if ($isFirst && $metrics !== [Goal::LIKES]) {
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
        $this->sync($this->open->get($metrics ?: [Goal::REPLIES, Goal::TAG_POSTS, Goal::LIKES, Goal::DISCUSSIONS]), Counting::SCOPE_DISCUSSION, $discussionId, $gone);
    }

    /**
     * Posts moved into another discussion (FoF Merge Discussions): the ledger
     * follows them, then both sides are looked at again (the merged-away
     * discussions are gone, and so is what they counted as discussions).
     *
     * @param list<int> $from
     * @param list<int> $postIds
     */
    public function merged(int $into, array $from, array $postIds): void
    {
        $goalIds = array_map(fn (Goal $g) => $g->id, $this->open->get([Goal::REPLIES, Goal::TAG_POSTS, Goal::LIKES]));
        if ($postIds && $goalIds) {
            $this->db->table('community_goal_entries')
                ->whereIn('goal_id', $goalIds)
                ->whereIn('subject_id', $postIds)
                ->update(['discussion_id' => $into]);
        }
        $this->discussion($into);
        foreach ($from as $id) {
            $this->discussion($id, [], true);
        }
    }

    /** A member joined or confirmed their email. */
    public function member(int $userId): void
    {
        $this->sync($this->open->get([Goal::MEMBERS]), Counting::SCOPE_USER, $userId);
    }

    /**
     * A member's account is being deleted: open goals lose what they gave
     * (the next sync would anyway, but their rows would linger); closed goals
     * keep their final number and just forget the name.
     */
    public function forgetUser(int $userId): void
    {
        // Runs in flarum/gdpr's queued erasure job, once per member: their entries are counted per goal here.
        $byGoal = $this->db->table('community_goal_entries')
            ->join('community_goals', 'community_goals.id', '=', 'community_goal_entries.goal_id')
            ->where('community_goal_entries.user_id', $userId)
            ->whereNull('community_goals.closed_at')
            ->pluck('community_goal_entries.goal_id')
            ->countBy();

        $this->db->table('community_goal_entries')->where('user_id', $userId)->delete();

        $deltas = [];
        foreach ($byGoal as $goalId => $n) {
            $deltas[(int) $goalId][$userId] = -(int) $n;
        }
        if ($deltas) {
            $this->adjust($deltas);
        }

        $goalIds = $this->db->table('community_goal_contributors')->where('user_id', $userId)->pluck('goal_id')->all();
        $this->db->table('community_goal_contributors')->where('user_id', $userId)->delete();
        foreach ($goalIds as $goalId) {
            $this->recountContributors((int) $goalId);
        }
        $this->board->forget();
    }

    /**
     * Make the ledger at one scope match what should be counted there, for
     * every goal given that the scope can affect.
     *
     * Called from a request it never waits: if another writer holds one of
     * the goals, the same sync is queued (SyncScope) and the request goes on.
     * The queued job waits up to LOCK_WAIT, so the count stays exact.
     *
     * @param list<Goal> $goals
     */
    public function sync(array $goals, string $scope, int $id, bool $gone = false, int $wait = 0): void
    {
        $goals = array_values(array_filter($goals, fn (Goal $goal) => $this->counting->affects($goal, $scope)));
        if (! $goals) {
            return;
        }
        $goalIds = array_map(fn (Goal $g) => $g->id, $goals);

        $this->inSync = true;
        try {
            // Retried on a lock conflict (deadlock, MariaDB 1020): each attempt re-reads the ledger.
            $this->serially(
                $goalIds,
                fn () => $this->db->transaction(fn () => $this->syncLocked($goals, $scope, $id, $gone), self::ATTEMPTS),
                $wait,
            );
        } catch (LockTimeoutException $busy) {
            if ($wait) {
                throw $busy;
            }
            $this->queue->push(new SyncScope($goalIds, $scope, $id, $gone));
        } finally {
            $this->inSync = false;
        }
        $this->finish();
    }

    /**
     * One writer per goal at a time, on every database: the row lock in
     * syncLocked (and Recount's) is a no-op on SQLite. Cache locks, shared by
     * every process on the forum's cache, taken in id order so two writers
     * can't wait on each other.
     *
     * Waits up to $wait seconds for each goal (0: not at all), then throws
     * LockTimeoutException.
     *
     * @template T
     * @param list<int> $goalIds
     * @param callable(): T $run
     * @return T
     */
    public function serially(array $goalIds, callable $run, int $wait = self::LOCK_WAIT): mixed
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            return $run();
        }

        $goalIds = array_values(array_unique($goalIds));
        sort($goalIds);
        $held = [];
        try {
            foreach ($goalIds as $goalId) {
                $lock = $store->lock('community-goals.goal.'.$goalId, self::LOCK_SECONDS);
                if ($wait) {
                    $lock->block($wait);
                } elseif (! $lock->get()) {
                    throw new LockTimeoutException();
                }
                $held[] = $lock;
            }

            return $run();
        } finally {
            foreach (array_reverse($held) as $lock) {
                $lock->release();
            }
        }
    }

    /** @param list<Goal> $goals */
    private function syncLocked(array $goals, string $scope, int $id, bool $gone): void
    {
        // One writer per goal at a time (a Recount holds the same lock), so counts can't drift.
        $open = $this->db->table('community_goals')
            ->whereIn('id', array_map(fn (Goal $g) => $g->id, $goals))
            ->whereNull('closed_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $goals = array_values(array_filter($goals, fn (Goal $g) => in_array($g->id, $open, true)));
        if (! $goals) {
            return;
        }

        $have = [];
        foreach ($this->db->table('community_goal_entries')
            ->whereIn('goal_id', $open)
            ->where(Counting::ledgerColumn($scope), $id)
            ->get(['goal_id', 'subject_id', 'user_id']) as $row) {
            $have[(int) $row->goal_id][$row->subject_id.':'.$row->user_id] = $row;
        }
        $want = $gone ? [] : $this->wanted($goals, $scope, $id);

        $deltas = $this->insertMissing($goals, $open, $scope, $id, $have, $want);
        $deltas = $this->removeStale($goals, $have, $want, $deltas);

        $deltas = array_filter(array_map(fn ($users) => array_filter($users), $deltas));
        if ($deltas) {
            $this->adjust($deltas);
        }
    }

    /**
     * What the ledger should hold at this scope: one facts query per family,
     * sorted to each goal in PHP by the same rules Recount runs as SQL.
     *
     * @param list<Goal> $goals
     * @return array<int, array<string, object>> goal id => "subject:user" => fact
     */
    private function wanted(array $goals, string $scope, int $id): array
    {
        $byFamily = [];
        foreach ($goals as $goal) {
            $byFamily[Counting::family($goal->metric)][] = $goal;
        }

        $want = [];
        foreach ($byFamily as $family => $members) {
            $rows = $this->counting->facts($family, $scope, $id);
            if (! $rows) {
                continue;
            }
            $tags = $family === 'posts' && array_filter($members, fn (Goal $g) => $g->metric === Goal::TAG_POSTS) ? $this->counting->tagsOf($rows) : [];
            foreach ($members as $goal) {
                foreach ($rows as $row) {
                    if ($this->counting->accepts($goal, $row, $tags)) {
                        $want[$goal->id][$row->subject_id.':'.$row->user_id] = $row;
                    }
                }
            }
        }

        return $want;
    }

    /**
     * Write the entries the ledger lacks; returns what each member gained.
     *
     * @param list<Goal> $goals
     * @param list<int> $open
     * @param array<int, array<string, object>> $have
     * @param array<int, array<string, object>> $want
     * @return array<int, array<int, int>>
     */
    private function insertMissing(array $goals, array $open, string $scope, int $id, array $have, array $want): array
    {
        $add = [];
        foreach ($goals as $goal) {
            foreach (array_diff_key($want[$goal->id] ?? [], $have[$goal->id] ?? []) as $row) {
                $add[] = [
                    'goal_id' => $goal->id,
                    'subject_id' => (int) $row->subject_id,
                    'user_id' => (int) $row->user_id,
                    'discussion_id' => $row->discussion_id === null ? null : (int) $row->discussion_id,
                ];
            }
        }
        if (! $add) {
            return [];
        }

        $deltas = [];
        $inserted = $this->db->table('community_goal_entries')->insertOrIgnore($add);
        if ($inserted === count($add)) {
            foreach ($add as $row) {
                $deltas[$row['goal_id']][$row['user_id']] = ($deltas[$row['goal_id']][$row['user_id']] ?? 0) + 1;
            }

            return $deltas;
        }

        // Some rows were already there (only possible if a writer skipped the lock): read what this run really added.
        foreach ($add as $row) {
            $deltas[$row['goal_id']][$row['user_id']] ??= 0;
        }
        $after = $this->db->table('community_goal_entries')->whereIn('goal_id', $open)->where(Counting::ledgerColumn($scope), $id)
            ->get(['goal_id', 'user_id'])
            ->countBy(fn ($row) => $row->goal_id.':'.$row->user_id);
        foreach ($after as $key => $n) {
            [$goalId, $userId] = array_map('intval', explode(':', (string) $key));
            $before = count(array_filter($have[$goalId] ?? [], fn ($h) => (int) $h->user_id === $userId));
            if (isset($deltas[$goalId][$userId])) {
                $deltas[$goalId][$userId] = $n - $before;
            }
        }

        return $deltas;
    }

    /**
     * Delete the entries that no longer count; returns $deltas with what each member lost.
     *
     * @param list<Goal> $goals
     * @param array<int, array<string, object>> $have
     * @param array<int, array<string, object>> $want
     * @param array<int, array<int, int>> $deltas
     * @return array<int, array<int, int>>
     */
    private function removeStale(array $goals, array $have, array $want, array $deltas): array
    {
        foreach ($goals as $goal) {
            $remove = [];
            foreach (array_diff_key($have[$goal->id] ?? [], $want[$goal->id] ?? []) as $row) {
                $remove[(int) $row->user_id][] = (int) $row->subject_id;
            }
            foreach ($remove as $userId => $subjects) {
                $n = $this->db->table('community_goal_entries')
                    ->where('goal_id', $goal->id)
                    ->where('user_id', $userId)
                    ->whereIn('subject_id', $subjects)
                    ->delete();
                if ($n) {
                    $deltas[$goal->id][$userId] = ($deltas[$goal->id][$userId] ?? 0) - $n;
                }
            }
        }

        return $deltas;
    }

    /**
     * Move goals' progress and contributors by what was written: one query
     * per kind of change, however many goals.
     *
     * @param array<int, array<int, int>> $deltas goal id => member id => change
     * @param array<int, int> $extra goal id => change with no member (a number kept by hand)
     */
    public function adjust(array $deltas, array $extra = []): void
    {
        $goalIds = array_values(array_unique([...array_keys($deltas), ...array_keys($extra)]));
        if (! $goalIds) {
            return;
        }

        [$joined, $left] = $this->moveContributors($deltas);

        $goalsTable = fn () => $this->db->table('community_goals');
        foreach ($this->groupBy($joined) as $n => $ids) {
            $goalsTable()->whereIn('id', $ids)->increment('contributors_count', $n);
        }
        foreach ($this->groupBy($left) as $n => $ids) {
            $goalsTable()->whereIn('id', $ids)->where('contributors_count', '>=', $n)->decrement('contributors_count', $n);
        }

        $totals = [];
        foreach ($goalIds as $goalId) {
            $totals[$goalId] = array_sum($deltas[$goalId] ?? []) + ($extra[$goalId] ?? 0);
        }
        foreach ($this->groupBy(array_filter($totals)) as $total => $ids) {
            if ($total > 0) {
                $goalsTable()->whereIn('id', $ids)->increment('progress', $total);
            } else {
                // Floored at zero (an unsigned column): those short of it first, then the rest.
                $goalsTable()->whereIn('id', $ids)->where('progress', '<', -$total)->update(['progress' => 0]);
                $goalsTable()->whereIn('id', $ids)->where('progress', '>=', -$total)->decrement('progress', -$total);
            }
        }

        $this->check($goalIds);
        $this->board->forget();
    }

    /**
     * Each member's count on each goal, one query per kind of change.
     *
     * @param array<int, array<int, int>> $deltas
     * @return array{0: array<int, int>, 1: array<int, int>} goal id => members who joined, and who left
     */
    private function moveContributors(array $deltas): array
    {
        $table = fn () => $this->db->table('community_goal_contributors');
        $userIds = array_values(array_unique(array_merge(...array_map('array_keys', array_values($deltas ?: [[]])))));

        $existing = [];
        if ($userIds) {
            foreach ($table()->whereIn('goal_id', array_keys($deltas))->whereIn('user_id', $userIds)->get(['goal_id', 'user_id', 'count']) as $row) {
                $existing[(int) $row->goal_id][(int) $row->user_id] = (int) $row->count;
            }
        }

        $newRows = [];
        $up = [];       // "user:delta" => goal ids
        $joined = [];   // goal id => members who now count
        $left = [];     // goal id => members who no longer do
        foreach ($deltas as $goalId => $users) {
            foreach ($users as $userId => $delta) {
                $had = $existing[$goalId][$userId] ?? null;
                if ($delta > 0) {
                    if ($had === null) {
                        $newRows[] = ['goal_id' => $goalId, 'user_id' => $userId, 'count' => 0];
                        $joined[$goalId] = ($joined[$goalId] ?? 0) + 1;
                    }
                    $up["$userId:$delta"][] = $goalId;
                } elseif ($had !== null) {
                    $table()->where('goal_id', $goalId)->where('user_id', $userId)->where('count', '>=', -$delta)->decrement('count', -$delta);
                    if ($had + $delta <= 0) {
                        $table()->where('goal_id', $goalId)->where('user_id', $userId)->where('count', '<=', 0)->delete();
                        $left[$goalId] = ($left[$goalId] ?? 0) + 1;
                    }
                }
            }
        }
        if ($newRows) {
            $table()->insertOrIgnore($newRows);
        }
        foreach ($up as $key => $goals) {
            [$userId, $delta] = array_map('intval', explode(':', $key));
            $table()->where('user_id', $userId)->whereIn('goal_id', $goals)->increment('count', $delta);
        }

        return [$joined, $left];
    }

    /**
     * Record milestones and claim the moment a goal is reached. Milestones
     * only ever go up: a celebration that happened stays happened, and so
     * does a goal that was reached, even if a post is removed afterwards.
     *
     * @param int|list<int> $goalIds
     */
    public function check(int|array $goalIds): void
    {
        $goals = Goal::query()->whereIn('id', (array) $goalIds)->whereNull('closed_at')
            ->get(['id', 'target', 'progress', 'milestone', 'hit_at', 'closed_at', 'ends_at']);

        foreach ($goals as $goal) {
            $milestone = $goal->milestoneFor($goal->progress);
            if ($milestone > $goal->milestone) {
                $this->db->table('community_goals')->where('id', $goal->id)->where('milestone', '<', $milestone)->update(['milestone' => $milestone]);
            }

            if ($goal->hit_at === null && $goal->progress >= $goal->target) {
                $claimed = $this->db->table('community_goals')
                    ->where('id', $goal->id)
                    ->whereNull('hit_at')
                    ->whereNull('closed_at')
                    ->update(['hit_at' => Carbon::now()->min($goal->ends_at)]);

                if ($claimed) {
                    // Keyed: an attempt that was rolled back and retried claims it again. CompleteGoal checks hit_at.
                    $this->finishing[$goal->id] = $goal->id;
                }
            }
        }

        if (! $this->inSync) {
            $this->finish();
        }
    }

    public function recountContributors(int $goalId): void
    {
        $count = $this->db->table('community_goal_contributors')->where('goal_id', $goalId)->count();
        $this->db->table('community_goals')->where('id', $goalId)->update(['contributors_count' => $count]);
    }

    /**
     * Queue CompleteGoal for goals just reached, once their count is committed,
     * and never able to break the request that tipped them over: on a sync
     * queue the job runs right here, and if it throws, the tick finishes it.
     */
    private function finish(): void
    {
        while ($goalId = array_shift($this->finishing)) {
            try {
                $this->queue->push(new CompleteGoal($goalId));
            } catch (\Throwable $e) {
                $this->log->warning('[community-goals] finishing goal '.$goalId.' failed, the tick will retry: '.$e->getMessage());
            }
        }
    }

    /**
     * @param array<int, int> $values id => value
     * @return array<int, list<int>> value => ids
     */
    private function groupBy(array $values): array
    {
        $out = [];
        foreach ($values as $id => $value) {
            $out[$value][] = $id;
        }

        return $out;
    }
}
