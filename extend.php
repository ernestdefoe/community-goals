<?php

/*
 * Community Goals: the whole forum works toward a shared target.
 *
 * An admin sets a goal (count replies, new discussions, likes, new members,
 * posts in a tag, or a number kept by hand) with a target, a window and a
 * reward. Core events move a ledger one post, discussion or member at a
 * time (Tally), so the count is never recounted on a page view; the
 * progress number is cached on the goal row. Reaching it notifies every
 * helper and hands out the rewards (CompleteGoal, queued).
 *
 * The index banner's goals ride in the forum payload from a cache (Board);
 * /goals and the forms are one lazy chunk.
 */

use ErnestDefoe\CommunityGoals\Api;
use ErnestDefoe\CommunityGoals\Board;
use ErnestDefoe\CommunityGoals\Console\RecountCommand;
use ErnestDefoe\CommunityGoals\Console\TickCommand;
use ErnestDefoe\CommunityGoals\Gdpr\GoalsData;
use ErnestDefoe\CommunityGoals\Http\PageContent;
use ErnestDefoe\CommunityGoals\Listener\CountActivity;
use ErnestDefoe\CommunityGoals\Notification\GoalReachedBlueprint;
use ErnestDefoe\CommunityGoals\Present;
use ErnestDefoe\CommunityGoals\Settings;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the page and forms are chunks in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/goals', 'community-goals', PageContent::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/community-goals', 'community-goals.index', Api\ListController::class)
        ->get('/community-goals/board', 'community-goals.board', Api\BoardController::class)
        ->get('/community-goals/options', 'community-goals.options', Api\OptionsController::class)
        ->post('/community-goals', 'community-goals.create', Api\SaveController::class)
        ->get('/community-goals/{id:\d+}', 'community-goals.show', Api\ShowController::class)
        ->patch('/community-goals/{id:\d+}', 'community-goals.update', Api\SaveController::class)
        ->delete('/community-goals/{id:\d+}', 'community-goals.delete', Api\DeleteController::class)
        ->post('/community-goals/{id:\d+}/recount', 'community-goals.recount', Api\RecountController::class)
        ->post('/community-goals/{id:\d+}/progress', 'community-goals.progress', Api\ProgressController::class),

    // Only because a notification's subject needs a resource; no endpoints.
    new Extend\ApiResource(Api\GoalResource::class),

    (new Extend\Notification())
        ->type(GoalReachedBlueprint::class, ['alert']),

    (new Extend\Event())
        ->subscribe(CountActivity::class),

    (new Extend\Console())
        ->command(TickCommand::class)
        ->command(RecountCommand::class)
        ->schedule(TickCommand::class, function (ScheduledEvent $event) {
            $event->everyFiveMinutes()->withoutOverlapping();
        }),

    // The banner's goals come from the cache: no query on a hit, one or two on a miss.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Arr::make('communityGoals')
                ->visible(fn ($forum, Context $context) => Settings::canView($context->getActor()) && resolve('flarum.settings')->get(Settings::PLACEMENT, 'hero') !== 'off')
                ->get(fn ($forum, Context $context) => array_slice(resolve(Present::class)->visible($context->getActor(), resolve(Board::class)->get()), 0, Board::LIMIT)),
            Schema\Boolean::make('communityGoalsCanView')
                ->get(fn ($forum, Context $context) => Settings::canView($context->getActor())),
            Schema\Boolean::make('communityGoalsCanManage')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::MANAGE)),
        ]),

    (new Extend\Settings())
        ->default(Settings::PLACEMENT, 'hero')
        ->default(Settings::NAV, true)
        ->serializeToForum('communityGoalsPlacement', Settings::PLACEMENT)
        ->serializeToForum('communityGoalsNav', Settings::NAV, 'boolval'),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(GoalsData::class),
        ]),
];
