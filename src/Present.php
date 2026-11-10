<?php

namespace ErnestDefoe\CommunityGoals;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\SlugManager;
use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Goals as the browser gets them: plain arrays, a page of them in a fixed
 * number of queries (tags in one, contributors in one, members in one).
 */
class Present
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ExtensionManager $extensions,
        private readonly SlugManager $slugs,
    ) {
    }

    /**
     * @param iterable<Goal> $goals
     * @return list<array<string, mixed>>
     */
    public function summaries(iterable $goals): array
    {
        $goals = Collection::make($goals);
        $tags = $this->tags($goals->pluck('tag_id')->filter()->unique()->all());

        return $goals->map(fn (Goal $goal) => $this->summary($goal, $goal->tag_id ? ($tags[$goal->tag_id] ?? null) : null))->values()->all();
    }

    /**
     * @param array<string, mixed>|null $tag
     * @return array<string, mixed>
     */
    public function summary(Goal $goal, ?array $tag = null): array
    {
        return [
            'id' => $goal->id,
            'title' => $goal->title,
            'description' => $goal->description,
            'metric' => $goal->metric,
            'tag' => $tag,
            'unit' => $goal->unit,
            'target' => $goal->target,
            'progress' => $goal->progress,
            'milestone' => $goal->milestone,
            'status' => $goal->status(),
            'reward' => $goal->reward,
            'startsAt' => $goal->starts_at->toIso8601String(),
            'endsAt' => $goal->ends_at->toIso8601String(),
            'hitAt' => $goal->hit_at?->toIso8601String(),
            'contributors' => $goal->contributors_count,
        ];
    }

    /**
     * Tags by id, only while Tags is on: a goal whose tag is gone or hidden
     * from the viewer is left out by visible().
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function tags(array $ids): array
    {
        if (! $ids || ! $this->extensions->isEnabled('flarum-tags')) {
            return [];
        }

        return Tag::query()->whereIn('id', $ids)->get(['id', 'name', 'slug', 'color', 'icon'])
            ->mapWithKeys(fn (Tag $tag) => [$tag->id => ['id' => $tag->id, 'name' => $tag->name, 'slug' => $tag->slug, 'color' => $tag->color, 'icon' => $tag->icon]])
            ->all();
    }

    /**
     * Leave out goals counting a tag the viewer can't see: their names and
     * contributors would say who posts there. One query, only when a goal has
     * a tag at all.
     *
     * @param list<array<string, mixed>> $summaries
     * @return list<array<string, mixed>>
     */
    public function visible(User $actor, array $summaries): array
    {
        $tagIds = array_values(array_unique(array_filter(array_map(fn ($s) => $s['tag']['id'] ?? null, $summaries))));
        $visibleTags = $tagIds ? Tag::query()->whereVisibleTo($actor)->whereIn('id', $tagIds)->pluck('id')->all() : [];

        // A tag goal whose tag is gone (or Tags is off) shows to nobody.
        return array_values(array_filter($summaries, fn ($s) => $s['tag']
            ? in_array($s['tag']['id'], $visibleTags, true)
            : $s['metric'] !== Goal::TAG_POSTS));
    }

    /**
     * Whether the viewer may see who helped: a "new members" goal's helpers
     * are the new members themselves, so they're shown only to those who may
     * see the member list.
     */
    public function canSeeHelpers(User $actor, string $metric): bool
    {
        return $metric !== Goal::MEMBERS || $actor->can('searchUsers');
    }

    /**
     * The top members of several goals in one query (a UNION of one small
     * indexed read per goal).
     *
     * @param list<int> $goalIds
     * @return array<int, list<array{0: int, 1: int}>> goal id => [[user id, count], …]
     */
    public function top(array $goalIds, int $limit): array
    {
        if (! $goalIds) {
            return [];
        }

        $query = null;
        foreach ($goalIds as $goalId) {
            $part = $this->db->table('community_goal_contributors')
                ->where('goal_id', $goalId)
                ->orderByDesc('count')
                ->orderBy('user_id')
                ->limit($limit)
                ->select(['goal_id', 'user_id', 'count']);
            $query = $query ? $query->unionAll($part) : $part;
        }

        $out = [];
        foreach ($query->get() as $row) {
            $out[(int) $row->goal_id][] = [(int) $row->user_id, (int) $row->count];
        }

        return $out;
    }

    /**
     * Members as one compact list the browser pushes into its store.
     *
     * Only members the viewer may see (a forum can hide its member list
     * or particular members).
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function users(array $ids, User $actor): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (! $ids) {
            return [];
        }

        $slugger = $this->slugs->forResource(User::class);

        return User::query()->whereVisibleTo($actor)->whereIn('users.id', $ids)->get()->map(fn (User $user) => [
            'id' => $user->id,
            'username' => $user->username,
            'displayName' => $user->display_name,
            'slug' => $slugger->toSlug($user),
            'avatarUrl' => $user->avatar_url,
        ])->values()->all();
    }

    public static function now(): string
    {
        return Carbon::now()->toIso8601String();
    }
}
