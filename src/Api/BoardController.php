<?php

namespace ErnestDefoe\CommunityGoals\Api;

use ErnestDefoe\CommunityGoals\Board;
use ErnestDefoe\CommunityGoals\Present;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/** The index banner's goals again, for a page that has been open a while. Cached; no query on a hit. */
class BoardController extends Controller
{
    public function __construct(Present $present, private readonly Board $board)
    {
        parent::__construct($present);
    }

    protected function respond(ServerRequestInterface $request, User $actor): mixed
    {
        return ['goals' => $this->present->visible($actor, $this->board->get()), 'now' => Present::now()];
    }
}
