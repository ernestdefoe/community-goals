<?php

namespace ErnestDefoe\CommunityGoals\Api;

use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Goals;
use ErnestDefoe\CommunityGoals\Present;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/** For a goal counted by hand: {add: n} or {set: n}. */
class ProgressController extends Controller
{
    public function __construct(Present $present, private readonly Goals $goals, private readonly ShowController $show)
    {
        parent::__construct($present);
    }

    protected function respond(ServerRequestInterface $request, User $actor): mixed
    {
        $this->manager($actor);
        $goal = Goal::query()->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
        $body = $this->body($request);

        $this->goals->setProgress(
            $goal,
            isset($body['add']) ? (int) $body['add'] : null,
            isset($body['set']) ? (int) $body['set'] : null,
        );

        return $this->show->detail($goal->refresh(), $actor);
    }
}
