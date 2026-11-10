<?php

namespace ErnestDefoe\CommunityGoals\Api;

use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Job\RecountGoal;
use ErnestDefoe\CommunityGoals\Present;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;

/** The admin's repair button: rebuild an open goal's count in the background. */
class RecountController extends Controller
{
    public function __construct(Present $present, private readonly Queue $queue)
    {
        parent::__construct($present);
    }

    protected function respond(ServerRequestInterface $request, User $actor): mixed
    {
        $this->manager($actor);
        $goal = Goal::query()->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
        if ($goal->isCounted() && ! $goal->closed_at) {
            $this->queue->push(new RecountGoal($goal->id));
        }

        return new JsonResponse(['queued' => true], 202);
    }
}
