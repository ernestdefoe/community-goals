<?php

namespace ErnestDefoe\CommunityGoals\Api;

use ErnestDefoe\CommunityGoals\Board;
use ErnestDefoe\CommunityGoals\Present;
use ErnestDefoe\CommunityGoals\Settings;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * The forum payload's goals: the index banner comes from the cache (Board),
 * so a hit costs no query and a miss one or two.
 */
class ForumResourceFields
{
    public function __construct(
        private readonly Present $present,
        private readonly Board $board,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Arr::make('communityGoals')
                ->visible(fn ($forum, Context $context) => Settings::canView($context->getActor()) && $this->settings->get(Settings::PLACEMENT) !== 'off')
                ->get(fn ($forum, Context $context) => array_slice($this->present->visible($context->getActor(), $this->board->get()), 0, Board::LIMIT)),
            Schema\Boolean::make('communityGoalsCanView')
                ->get(fn ($forum, Context $context) => Settings::canView($context->getActor())),
            Schema\Boolean::make('communityGoalsCanManage')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::MANAGE)),
        ];
    }
}
