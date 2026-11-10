<?php

namespace ErnestDefoe\CommunityGoals\Notification;

use ErnestDefoe\CommunityGoals\Goal;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * To everyone who helped: "We reached Reply Rally together!" One blueprint
 * for all of them (so one sync, one insert), which is why the data carries
 * nothing about the recipient. The subject is the goal's creator, a User,
 * which has an API resource; the goal rides in the data.
 */
class GoalReachedBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public Goal $goal)
    {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->goal->creator;
    }

    public function getData(): mixed
    {
        return [
            'goal' => $this->goal->id,
            'title' => $this->goal->title,
            'target' => $this->goal->target,
            'metric' => $this->goal->metric,
            'reward' => $this->goal->reward,
        ];
    }

    public static function getType(): string
    {
        return 'communityGoalReached';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
