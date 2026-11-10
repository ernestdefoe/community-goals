<?php

namespace ErnestDefoe\CommunityGoals\Api;

use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Goals;
use ErnestDefoe\CommunityGoals\Present;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;

/** POST creates a goal, PATCH /{id} edits one (Goals decides what may still change). */
class SaveController extends Controller
{
    public function __construct(Present $present, private readonly Goals $goals, private readonly ShowController $show)
    {
        parent::__construct($present);
    }

    protected function respond(ServerRequestInterface $request, User $actor): mixed
    {
        $this->manager($actor);
        $id = Arr::get($request->getQueryParams(), 'id');
        $goal = $id ? Goal::query()->findOrFail((int) $id) : null;

        $goal = $this->goals->save($goal, $this->body($request), $actor);

        return new JsonResponse($this->show->detail($goal, $actor), $id ? 200 : 201);
    }
}
