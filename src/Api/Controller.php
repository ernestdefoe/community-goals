<?php

namespace ErnestDefoe\CommunityGoals\Api;

use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Present;
use ErnestDefoe\CommunityGoals\Settings;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\NotAuthenticatedException;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Shared by the goal endpoints: who is asking, and the goal they may see.
 * A goal counting a tag the viewer can't see is a 404, exactly like a goal
 * that doesn't exist.
 */
abstract class Controller implements RequestHandlerInterface
{
    public function __construct(protected readonly Present $present)
    {
    }

    abstract protected function respond(ServerRequestInterface $request, User $actor): mixed;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        $data = $this->respond($request, $actor);

        return $data instanceof ResponseInterface ? $data : new JsonResponse($data);
    }

    protected function manager(User $actor): void
    {
        if ($actor->isGuest()) {
            throw new NotAuthenticatedException();
        }
        $actor->assertCan(Settings::MANAGE);
    }

    protected function goal(ServerRequestInterface $request, User $actor): Goal
    {
        $goal = Goal::query()->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));

        if (! $actor->can(Settings::MANAGE) && ! $this->present->visible($actor, [$this->present->summaries([$goal])[0]])) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        }

        return $goal;
    }

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }
}
