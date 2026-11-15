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
use Flarum\Approval\Event\PostWasApproved;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Flarum\Likes\Event\PostWasLiked;
use Flarum\Likes\Event\PostWasUnliked;
use Flarum\Tags\Event\DiscussionWasTagged;
use FoF\MergeDiscussions\Events\DiscussionWasMerged;
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

    // Goals themselves are the JSON:API resource; these four have no resource shape. The GETs
    // sit beside it, not under it: the resource's /community-goals/{id} would shadow them.
    (new Extend\Routes('api'))
        ->get('/community-goals-board', 'community-goals.board', Api\BoardController::class)
        ->get('/community-goals-options', 'community-goals.options', Api\OptionsController::class)
        ->post('/community-goals/{id:\d+}/recount', 'community-goals.recount', Api\RecountController::class)
        ->post('/community-goals/{id:\d+}/progress', 'community-goals.progress', Api\ProgressController::class),

    new Extend\ApiResource(Api\GoalResource::class),

    (new Extend\Notification())
        ->type(GoalReachedBlueprint::class, ['alert']),

    (new Extend\Event())
        ->subscribe(CountActivity::class),

    // What optional extensions change, listened to only while each is on.
    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-likes', fn () => [
            (new Extend\Event())
                ->listen(PostWasLiked::class, CountActivity::class.'@liked')
                ->listen(PostWasUnliked::class, CountActivity::class.'@liked'),
        ])
        ->whenExtensionEnabled('flarum-approval', fn () => [
            (new Extend\Event())->listen(PostWasApproved::class, CountActivity::class.'@approved'),
        ])
        ->whenExtensionEnabled('flarum-tags', fn () => [
            (new Extend\Event())->listen(DiscussionWasTagged::class, CountActivity::class.'@tagged'),
        ])
        ->whenExtensionEnabled('fof-merge-discussions', fn () => [
            (new Extend\Event())->listen(DiscussionWasMerged::class, CountActivity::class.'@merged'),
        ]),

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

    // 🚨 Literal keys (= Settings::PLACEMENT, Settings::NAV), never the constants: reading a
    // constant here autoloads the class during boot, and a worker with a stale autoload map
    // mid-deploy then fatals on every request. Constants inside closures are fine.
    (new Extend\Settings())
        ->default('ernestdefoe-community-goals.placement', 'hero')
        ->default('ernestdefoe-community-goals.nav', true)
        ->serializeToForum('communityGoalsPlacement', 'ernestdefoe-community-goals.placement')
        ->serializeToForum('communityGoalsNav', 'ernestdefoe-community-goals.nav', 'boolval'),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(GoalsData::class),
        ]),
];
