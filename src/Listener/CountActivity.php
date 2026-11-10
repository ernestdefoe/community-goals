<?php

namespace ErnestDefoe\CommunityGoals\Listener;

use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Tally;
use Flarum\Approval\Event\PostWasApproved;
use Flarum\Discussion\Event as DiscussionEvent;
use FoF\MergeDiscussions\Events\DiscussionWasMerged;
use Flarum\Likes\Event\PostWasLiked;
use Flarum\Likes\Event\PostWasUnliked;
use Flarum\Post\Event as PostEvent;
use Flarum\Post\Post;
use Flarum\Tags\Event\DiscussionWasTagged;
use Flarum\User\Event as UserEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * Every core (and optional extension) event that can change what a goal
 * counts, handed to Tally at the narrowest scope: a post, a discussion, or a
 * member. Tally re-checks that scope from the database, so each handler only
 * needs to say where to look, never what changed.
 *
 * 🚨 Counting must never break the thing being counted: a failure here is
 * logged and the post, like or sign-up goes through. The Recount button
 * repairs a goal that missed something.
 */
class CountActivity
{
    public function __construct(
        private readonly Tally $tally,
        private readonly LoggerInterface $log,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(PostEvent\Posted::class, fn ($e) => $this->post($e->post, [Goal::REPLIES, Goal::TAG_POSTS]));
        foreach ([PostEvent\Hidden::class, PostEvent\Restored::class, PostEvent\Deleted::class, PostWasApproved::class] as $event) {
            $events->listen($event, fn ($e) => $this->post($e->post));
        }
        foreach ([PostWasLiked::class, PostWasUnliked::class] as $event) {
            $events->listen($event, fn ($e) => $this->post($e->post, [Goal::LIKES]));
        }

        // Tags may be saved after the first post, so a new discussion is looked at as a whole too.
        $events->listen(DiscussionEvent\Started::class, fn ($e) => $this->safely(fn () => $this->tally->discussion($e->discussion->id, [Goal::DISCUSSIONS, Goal::TAG_POSTS])));
        // Re-tagged: a tag goal gains or loses it, and a move into (or out of) a
        // staff-only tag changes what a member can read, so every metric looks again.
        // (Changing a TAG's permissions moves nothing by itself: that needs a Recount.)
        $events->listen(DiscussionWasTagged::class, fn ($e) => $this->safely(fn () => $this->tally->discussion($e->discussion->id)));
        // FoF Merge Discussions moves posts into another discussion without post events.
        $events->listen(DiscussionWasMerged::class, fn ($e) => $this->safely(fn () => $this->tally->merged(
            $e->discussion->id,
            $e->mergedDiscussions->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $e->posts->pluck('id')->map(fn ($id) => (int) $id)->all(),
        )));
        foreach ([DiscussionEvent\Hidden::class, DiscussionEvent\Restored::class] as $event) {
            $events->listen($event, fn ($e) => $this->safely(fn () => $this->tally->discussion($e->discussion->id)));
        }
        // Before the delete: its posts go with it, without events of their own.
        $events->listen(DiscussionEvent\Deleting::class, fn ($e) => $this->safely(fn () => $this->tally->discussion($e->discussion->id, [], true)));

        $events->listen(UserEvent\Registered::class, fn ($e) => $e->user->is_email_confirmed ? $this->safely(fn () => $this->tally->member($e->user->id)) : null);
        $events->listen(UserEvent\Activated::class, fn ($e) => $this->safely(fn () => $this->tally->member($e->user->id)));
        $events->listen(UserEvent\Deleting::class, fn ($e) => $this->safely(fn () => $this->tally->forgetUser($e->user->id)));
    }

    /** @param list<string> $metrics */
    private function post(Post $post, array $metrics = [Goal::REPLIES, Goal::TAG_POSTS, Goal::LIKES]): void
    {
        if ($post->id && $post->discussion_id) {
            $this->safely(fn () => $this->tally->post($post->id, $post->discussion_id, (int) $post->number === 1, $metrics));
        }
    }

    private function safely(callable $count): void
    {
        try {
            $count();
        } catch (\Throwable $e) {
            $this->log->warning('[community-goals] counting failed: '.$e->getMessage());
        }
    }
}
