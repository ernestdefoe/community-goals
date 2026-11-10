<?php

namespace ErnestDefoe\CommunityGoals\Listener;

use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Tally;
use Flarum\Approval\Event\PostWasApproved;
use Flarum\Discussion\Event as DiscussionEvent;
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
        foreach ([PostEvent\Posted::class, PostEvent\Hidden::class, PostEvent\Restored::class, PostEvent\Deleted::class, PostWasApproved::class] as $event) {
            $events->listen($event, fn ($e) => $this->post($e->post));
        }
        foreach ([PostWasLiked::class, PostWasUnliked::class] as $event) {
            $events->listen($event, fn ($e) => $this->post($e->post, true));
        }

        // Tags may be saved after the first post, so a new discussion is looked at as a whole too.
        $events->listen(DiscussionEvent\Started::class, fn ($e) => $this->safely(fn () => $this->tally->discussion($e->discussion->id, [Goal::DISCUSSIONS, Goal::TAG_POSTS])));
        $events->listen(DiscussionWasTagged::class, fn ($e) => $this->safely(fn () => $this->tally->discussion($e->discussion->id, [Goal::TAG_POSTS])));
        foreach ([DiscussionEvent\Hidden::class, DiscussionEvent\Restored::class] as $event) {
            $events->listen($event, fn ($e) => $this->safely(fn () => $this->tally->discussion($e->discussion->id)));
        }
        // Before the delete: its posts go with it, without events of their own.
        $events->listen(DiscussionEvent\Deleting::class, fn ($e) => $this->safely(fn () => $this->tally->discussion($e->discussion->id, [], true)));

        $events->listen(UserEvent\Registered::class, fn ($e) => $e->user->is_email_confirmed ? $this->safely(fn () => $this->tally->member($e->user->id)) : null);
        $events->listen(UserEvent\Activated::class, fn ($e) => $this->safely(fn () => $this->tally->member($e->user->id)));
        $events->listen(UserEvent\Deleting::class, fn ($e) => $this->safely(fn () => $this->tally->forgetUser($e->user->id)));
    }

    private function post(Post $post, bool $likesOnly = false): void
    {
        if ($post->id && $post->discussion_id) {
            $this->safely(fn () => $this->tally->post($post->id, $post->discussion_id, (int) $post->number === 1, $likesOnly));
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
