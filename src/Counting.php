<?php

namespace ErnestDefoe\CommunityGoals;

use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;

/**
 * What a goal counts, as one query per metric that selects the ledger's rows
 * (subject_id, user_id, discussion_id). Tally runs it narrowed to one post,
 * discussion or member when something happens; Recount runs it for the whole
 * window.
 *
 * 🚨 Only what an ordinary signed-in member can read counts: the queries go
 * through Flarum's own visibility scopes as a member with no extra groups.
 * So hidden and unapproved posts, private discussions and staff-only tags
 * never count, whichever extension makes them so, and a post that is hidden
 * and later restored counts again (the ledger makes both idempotent). The
 * thing has to happen inside the goal's window: a post or discussion is
 * dated by when it was written, a like by when it was given, a member by
 * when they joined. Nobody gets credit for liking their own post.
 */
class Counting
{
    public const SCOPE_POST = 'post';
    public const SCOPE_DISCUSSION = 'discussion';
    public const SCOPE_USER = 'user';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ExtensionManager $extensions,
    ) {
    }

    /** A signed-in member with no groups beyond Members: who the counted things must be visible to. */
    public static function reader(): User
    {
        $reader = new User();
        $reader->is_email_confirmed = true;
        $reader->setRelation('groups', new Collection());

        return $reader;
    }

    /** The ledger column a scope narrows: posts and members are subjects, a discussion is its own column. */
    public static function ledgerColumn(string $scope): string
    {
        return $scope === self::SCOPE_DISCUSSION ? 'discussion_id' : 'subject_id';
    }

    /** Whether the metric can be affected by something happening at this scope. */
    public function affects(Goal $goal, string $scope): bool
    {
        return match ($goal->metric) {
            Goal::REPLIES, Goal::TAG_POSTS, Goal::LIKES => $scope !== self::SCOPE_USER,
            Goal::DISCUSSIONS => $scope === self::SCOPE_DISCUSSION,
            Goal::MEMBERS => $scope === self::SCOPE_USER,
            default => false,
        };
    }

    /**
     * The rows the goal should have, narrowed to a scope (or the whole
     * window when $scope is null). Null when the metric's extension is off.
     */
    public function query(Goal $goal, ?string $scope = null, ?int $id = null): ?Builder
    {
        $window = [$goal->starts_at, $goal->ends_at];

        switch ($goal->metric) {
            case Goal::REPLIES:
            case Goal::TAG_POSTS:
                $query = $this->posts()->whereBetween('posts.created_at', $window);
                if ($goal->metric === Goal::REPLIES) {
                    $query->where('posts.number', '>', 1);
                } else {
                    if (! $goal->tag_id || ! $this->extensions->isEnabled('flarum-tags')) {
                        return null;
                    }
                    $query->whereIn('posts.discussion_id', fn (Builder $q) => $q->select('discussion_id')->from('discussion_tag')->where('tag_id', $goal->tag_id));
                }
                $query->select(['posts.id as subject_id', 'posts.user_id as user_id', 'posts.discussion_id as discussion_id']);
                break;

            case Goal::LIKES:
                if (! $this->extensions->isEnabled('flarum-likes')) {
                    return null;
                }
                $query = $this->posts()
                    ->join('post_likes', 'post_likes.post_id', '=', 'posts.id')
                    ->whereBetween('post_likes.created_at', $window)
                    ->whereColumn('post_likes.user_id', '!=', 'posts.user_id')
                    ->select(['posts.id as subject_id', 'post_likes.user_id as user_id', 'posts.discussion_id as discussion_id']);
                break;

            case Goal::DISCUSSIONS:
                $query = Discussion::query()->whereVisibleTo(self::reader())->toBase()
                    ->whereNull('discussions.hidden_at')
                    ->whereNotNull('discussions.user_id')
                    ->whereBetween('discussions.created_at', $window)
                    ->select(['discussions.id as subject_id', 'discussions.user_id as user_id', 'discussions.id as discussion_id']);
                break;

            case Goal::MEMBERS:
                $query = $this->db->table('users')
                    ->where('users.is_email_confirmed', true)
                    ->whereBetween('users.joined_at', $window)
                    ->select(['users.id as subject_id', 'users.id as user_id', new Expression('NULL as discussion_id')]);
                break;

            default:
                return null;
        }

        if ($scope !== null) {
            $column = match ([$goal->metric, $scope]) {
                [Goal::DISCUSSIONS, self::SCOPE_DISCUSSION] => 'discussions.id',
                [Goal::MEMBERS, self::SCOPE_USER] => 'users.id',
                default => $scope === self::SCOPE_DISCUSSION ? 'posts.discussion_id' : 'posts.id',
            };
            $query->where($column, $id);
        }

        return $query;
    }

    /** Comment posts by members that a member can read. */
    private function posts(): Builder
    {
        return Post::query()->whereVisibleTo(self::reader())->toBase()
            ->where('posts.type', 'comment')
            ->whereNull('posts.hidden_at')
            ->whereNotNull('posts.user_id');
    }
}
