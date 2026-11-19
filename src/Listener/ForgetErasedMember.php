<?php

namespace ErnestDefoe\CommunityGoals\Listener;

use ErnestDefoe\CommunityGoals\Tally;
use Flarum\Gdpr\Events\Erasing;

/**
 * A member's erasure (deletion or anonymization): their part comes out of
 * running goals. flarum/gdpr dispatches Erasing in its queued erasure job
 * right before it runs every data type, GoalsData included. It builds those
 * types itself with a fixed argument list, so the counting work (which needs
 * Tally) happens here, where the container can inject it.
 */
class ForgetErasedMember
{
    public function __construct(private readonly Tally $tally)
    {
    }

    public function handle(Erasing $event): void
    {
        $this->tally->forgetUser((int) $event->user->user_id);
    }
}
