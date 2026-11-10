<?php

namespace ErnestDefoe\CommunityGoals\Api;

use ErnestDefoe\CommunityGoals\Goal;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;

/**
 * The `community-goals` JSON:API type, with no endpoints of its own: the
 * forum reads goals through the plain-JSON controllers. It exists because a
 * notification's subject must have a registered resource, or every
 * notification list 500s.
 *
 * @extends AbstractDatabaseResource<Goal>
 */
class GoalResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'community-goals';
    }

    public function model(): string
    {
        return Goal::class;
    }

    public function endpoints(): array
    {
        return [];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('title'),
        ];
    }
}
