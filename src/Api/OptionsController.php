<?php

namespace ErnestDefoe\CommunityGoals\Api;

use ErnestDefoe\CommunityGoals\Present;
use ErnestDefoe\CommunityGoals\Rewards;
use Flarum\Extension\ExtensionManager;
use Flarum\Tags\Tag;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/** What the goal form can offer on this forum: metrics, tags and the rewards whose extensions are on. */
class OptionsController extends Controller
{
    public function __construct(Present $present, private readonly Rewards $rewards, private readonly ExtensionManager $extensions)
    {
        parent::__construct($present);
    }

    protected function respond(ServerRequestInterface $request, User $actor): mixed
    {
        $this->manager($actor);
        $tags = $this->extensions->isEnabled('flarum-tags');

        return [
            'likes' => $this->extensions->isEnabled('flarum-likes'),
            'tags' => $tags ? Tag::query()->orderBy('position')->orderBy('name')->get(['id', 'name', 'parent_id'])
                ->map(fn (Tag $tag) => ['id' => $tag->id, 'title' => $tag->name, 'child' => (bool) $tag->parent_id])->all() : null,
            // Rewards change site settings and hand out badges: admins only (Goals::save agrees).
            'rewards' => $actor->isAdmin() ? $this->rewards->options() : null,
        ];
    }
}
