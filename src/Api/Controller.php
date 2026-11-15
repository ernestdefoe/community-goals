<?php

namespace ErnestDefoe\CommunityGoals\Api;

use ErnestDefoe\CommunityGoals\Present;
use ErnestDefoe\CommunityGoals\Settings;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\NotAuthenticatedException;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Shared by the plain endpoints (board, options, recount, progress): who is asking. */
abstract class Controller implements RequestHandlerInterface
{
    public function __construct(protected readonly Present $present)
    {
    }

    abstract protected function respond(ServerRequestInterface $request, User $actor): mixed;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        // A forum closed to guests stays closed: goal names, rewards and helpers included.
        $actor->assertCan('viewForum');
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

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }
}
