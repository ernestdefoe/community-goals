<?php

namespace ErnestDefoe\CommunityGoals\Api;

use ErnestDefoe\CommunityGoals\Integrations;
use Carbon\Carbon;
use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Goals;
use ErnestDefoe\CommunityGoals\Present;
use ErnestDefoe\CommunityGoals\Settings;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * Goals, as the `community-goals` JSON:API type (also a notification's subject).
 *
 * - Index (`filter[when]=open` running or coming up, otherwise history newest first)
 *   and Show, with `include=helpers,tag`. Each goal's top helpers and the
 *   viewer's own count are loaded for the whole page at once (`after`), so the
 *   query count doesn't grow with the goals; Show gets ten helpers and a rank.
 * - Create, Update and Delete for managers go through Goals, which checks
 *   everything the form sends (attributes here only declare what may be sent).
 * - A goal counting a tag the viewer can't see doesn't exist for them;
 *   managers still reach it by id to edit it.
 *
 * @extends AbstractDatabaseResource<Goal>
 */
class GoalResource extends AbstractDatabaseResource
{
    public const PER_PAGE = 10;

    /** @var \WeakMap<Goal, array{top: list<array{0: int, 1: int}>, mine: int, rank: ?int, helpers: list<User>}> */
    private \WeakMap $extras;

    public function __construct(
        private readonly Present $present,
        private readonly Goals $goals,
        private readonly Integrations $integrations,
        private readonly ConnectionInterface $db,
    ) {
        $this->extras = new \WeakMap();
    }

    public function type(): string
    {
        return 'community-goals';
    }

    public function model(): string
    {
        return Goal::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = $context->getActor();
        // Our own list, not a notification list (or anything else) that includes goals.
        $listing = $context->endpoint instanceof Endpoint\Index && $context->collection instanceof self;

        // Everywhere, includes and notification subjects too; managers reach a goal by id to edit it.
        if ($listing || ! $actor->can(Settings::MANAGE)) {
            $query->where(function (Builder $q) use ($actor) {
                $q->where('metric', '!=', Goal::TAG_POSTS);
                // A tag goal whose tag is gone (or Tags is off) shows to nobody.
                if ($this->integrations->tags()) {
                    $q->orWhereIn('tag_id', Tag::query()->whereVisibleTo($actor)->select('tags.id'));
                }
            });
        }

        if ($listing) {
            $now = Carbon::now();
            $current = fn (Builder $q) => $q->whereNull('closed_at')->where('ends_at', '>', $now);
            if ((($context->queryParam('filter') ?? [])['when'] ?? null) === 'open') {
                $query->where($current)->orderBy('starts_at')->orderBy('ends_at')->orderBy('id');
            } else {
                $query->whereNot($current)->orderByDesc('ends_at')->orderByDesc('id');
            }
        }
    }

    public function endpoints(): array
    {
        // A forum closed to guests stays closed: goal names, rewards and helpers included.
        $view = function (Context $context) {
            $context->getActor()->assertCan('viewForum');
            $context->getActor()->assertCan(Settings::VIEW);
        };

        return [
            Endpoint\Index::make()
                ->before($view)
                ->paginate(self::PER_PAGE, 20)
                // filter[when] is read in scope(): Flarum hands other filters to a searcher, which goals don't need.
                ->extractFilter(fn () => [])
                ->meta([Schema\Str::make('now')->get(fn () => Present::now())])
                ->after(function (Context $context, $goals) {
                    $this->preload($goals, $context->getActor(), null);

                    return $goals;
                }),
            Endpoint\Show::make()
                ->before($view)
                ->after(function (Context $context, Goal $goal) {
                    $this->preload([$goal], $context->getActor(), 10);

                    return $goal;
                }),
            Endpoint\Create::make()
                ->before($view)
                ->authenticated()
                ->can(Settings::MANAGE),
            Endpoint\Update::make()
                ->before($view)
                ->authenticated()
                ->can(Settings::MANAGE),
            Endpoint\Delete::make()
                ->before($view)
                ->authenticated()
                ->can(Settings::MANAGE),
        ];
    }

    public function fields(): array
    {
        $manage = fn ($goal, Context $context) => $context->getActor()->can(Settings::MANAGE);
        // Accepted from managers and handed to Goals::save as sent; nothing is set here.
        $input = fn (Schema\Attribute $field) => $field->writable($manage)->set(fn () => null);

        $fields = [
            $input(Schema\Str::make('title')),
            $input(Schema\Str::make('description')->nullable()),
            $input(Schema\Str::make('metric')),
            $input(Schema\Str::make('unit')->nullable()),
            $input(Schema\Integer::make('target')),
            $input(Schema\Integer::make('progress')),
            Schema\Integer::make('milestone'),
            Schema\Str::make('status')->get(fn (Goal $goal) => $goal->status()),
            $input(Schema\Str::make('reward')->nullable()),
            $input(Schema\Str::make('startsAt')->nullable()->get(fn (Goal $goal) => $goal->starts_at->toIso8601String())),
            $input(Schema\Str::make('endsAt')->nullable()->get(fn (Goal $goal) => $goal->ends_at->toIso8601String())),
            Schema\Str::make('hitAt')->nullable()->get(fn (Goal $goal) => $goal->hit_at?->toIso8601String()),
            Schema\Integer::make('contributors')->property('contributors_count'),
            // [user id, count] of the top helpers, filled by preload() on Index and Show.
            Schema\Arr::make('top')->get(fn (Goal $goal) => $this->extras[$goal]['top'] ?? []),
            Schema\Integer::make('mine')->get(fn (Goal $goal) => $this->extras[$goal]['mine'] ?? 0),
            Schema\Integer::make('rank')->nullable()->get(fn (Goal $goal) => $this->extras[$goal]['rank'] ?? null),

            // Managers only: what it hands out, and the form's own fields.
            $input(Schema\Arr::make('rewards')->nullable())->visible($manage)->get(fn (Goal $goal) => $goal->rewards ?? (object) []),
            Schema\Arr::make('rewardState')->visible($manage)->get(fn (Goal $goal) => $goal->reward_state ?? (object) []),
            $input(Schema\Integer::make('tagId')->nullable())->visible($manage)->property('tag_id'),
            Schema\Str::make('countedAt')->nullable()->visible($manage)->get(fn (Goal $goal) => $goal->counted_at?->toIso8601String()),
            // "Save it even though the target is already met" (Goals::save).
            $input(Schema\Boolean::make('complete'))->visible(false),

            Schema\Relationship\ToMany::make('helpers')
                ->type('users')
                ->includable()
                ->get(fn (Goal $goal) => $this->extras[$goal]['helpers'] ?? []),
        ];

        if ($this->integrations->tags()) {
            $fields[] = Schema\Relationship\ToOne::make('tag')->type('tags')->includable();
        }

        return $fields;
    }

    public function create(object $model, BaseContext $context): object
    {
        return $this->goals->save(null, $this->input($context), $context->getActor());
    }

    public function update(object $model, BaseContext $context): object
    {
        return $this->goals->save($model, $this->input($context), $context->getActor());
    }

    public function delete(object $model, BaseContext $context): void
    {
        $this->goals->delete($model);
    }

    /** @return array<string, mixed> */
    private function input(BaseContext $context): array
    {
        return (array) ($context->body()['data']['attributes'] ?? []);
    }

    /**
     * Top helpers (five on a running goal, three on a past one, $limit on
     * Show), the viewer's count and rank, and the helpers they may see, for
     * every goal at once.
     *
     * @param iterable<Goal> $goals
     */
    private function preload(iterable $goals, User $actor, ?int $limit): void
    {
        $goals = [...$goals];
        $shown = array_filter($goals, fn (Goal $g) => $this->present->canSeeHelpers($actor, $g->metric));
        $limitOf = fn (Goal $g) => $limit ?? (in_array($g->status(), ['upcoming', 'live'], true) ? 5 : 3);

        $top = [];
        foreach ([5, 3, 10] as $n) {
            $ids = array_values(array_map(fn (Goal $g) => $g->id, array_filter($shown, fn (Goal $g) => $limitOf($g) === $n)));
            $top += $this->present->top($ids, $n);
        }

        $mine = $actor->isGuest() || ! $goals ? [] : $this->db->table('community_goal_contributors')
            ->where('user_id', $actor->id)
            ->whereIn('goal_id', array_map(fn (Goal $g) => $g->id, $goals))
            ->pluck('count', 'goal_id')->all();

        // Helpers the viewer may not see are left out, not shown as "deleted".
        $userIds = array_unique(array_merge([], ...array_map(fn ($rows) => array_column($rows, 0), array_values($top))));
        /** @var array<int, User> $users */
        $users = $userIds ? User::query()->whereVisibleTo($actor)->whereIn('users.id', $userIds)->get()->keyBy('id')->all() : [];

        foreach ($goals as $goal) {
            $rows = array_values(array_filter($top[$goal->id] ?? [], fn (array $row) => isset($users[$row[0]])));
            $helpers = array_map(fn (array $row): User => $users[$row[0]], $rows);
            $count = (int) ($mine[$goal->id] ?? 0);
            // Only Show ranks the viewer (one count query).
            $rank = $limit && $count ? 1 + $this->db->table('community_goal_contributors')->where('goal_id', $goal->id)->where('count', '>', $count)->count() : null;
            $this->extras[$goal] = ['top' => $rows, 'mine' => $count, 'rank' => $rank, 'helpers' => $helpers];
        }
    }
}
