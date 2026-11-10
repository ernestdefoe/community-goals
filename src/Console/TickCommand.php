<?php

namespace ErnestDefoe\CommunityGoals\Console;

use Carbon\Carbon;
use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Goals;
use ErnestDefoe\CommunityGoals\Job\CompleteGoal;
use ErnestDefoe\CommunityGoals\Rewards;
use ErnestDefoe\CommunityGoals\Tally;
use Flarum\Console\AbstractCommand;
use Illuminate\Contracts\Container\Container;

/**
 * Every five minutes (the scheduler):
 *  1. closes goals whose window is over, after a last check that records a
 *     goal reached by its final count. Closed goals never move again.
 *  2. finishes any reached goal whose rewards the queue hasn't handed out
 *     (no worker, a worker that died, a step that threw); CompleteGoal
 *     takes a lease and only does what is left, so running it here as
 *     well is harmless.
 *  3. takes back timed rewards whose days are up.
 */
class TickCommand extends AbstractCommand
{
    public function __construct(
        private readonly Tally $tally,
        private readonly Goals $goals,
        private readonly Rewards $rewards,
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('community-goals:tick')
            ->setDescription('Close community goals that have ended and take back timed rewards.');
    }

    protected function fire(): int
    {
        $now = Carbon::now();

        $ended = Goal::query()->whereNull('closed_at')->where('ends_at', '<=', $now)->pluck('id');
        foreach ($ended as $id) {
            $this->tally->check((int) $id);
            Goal::query()->whereKey($id)->whereNull('closed_at')->update(['closed_at' => $now]);
        }
        if (count($ended)) {
            $this->goals->changed();
        }

        // Reached but not finished, and nobody working on it (or a run that died: its lease is stale).
        $waiting = Goal::query()->whereNotNull('hit_at')->whereNull('rewarded_at')
            ->where(fn ($q) => $q->whereNull('completing_at')->orWhere('completing_at', '<', $now->copy()->subMinutes(CompleteGoal::LEASE_MINUTES)))
            ->pluck('id');
        foreach ($waiting as $id) {
            $this->container->call([new CompleteGoal((int) $id), 'handle']);
        }

        $timed = Goal::query()->whereNotNull('rewarded_at')->where('rewarded_at', '>=', $now->copy()->subDays(75))->whereNotNull('reward_state')->get();
        foreach ($timed as $goal) {
            if (($state = $this->rewards->expire($goal)) !== null) {
                $goal->reward_state = $state;
                $goal->save();
            }
        }

        $this->info(count($ended).' closed, '.count($waiting).' finished.');

        return 0;
    }
}
