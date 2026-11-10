<?php

namespace ErnestDefoe\CommunityGoals\Console;

use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Recount;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputArgument;

/** Rebuilds the count of one open goal, or of all of them, from the forum's tables. */
class RecountCommand extends AbstractCommand
{
    public function __construct(private readonly Recount $recount)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('community-goals:recount')
            ->setDescription('Recount open community goals from scratch.')
            ->addArgument('id', InputArgument::OPTIONAL, 'Only this goal');
    }

    protected function fire(): int
    {
        $query = Goal::query()->whereNull('closed_at')->where('metric', '!=', Goal::CUSTOM);
        if ($id = $this->input->getArgument('id')) {
            $query->whereKey((int) $id);
        }

        foreach ($query->get() as $goal) {
            $this->recount->run($goal);
            $this->info($goal->id.' '.$goal->title.': '.Goal::query()->whereKey($goal->id)->value('progress'));
        }

        return 0;
    }
}
