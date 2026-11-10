<?php

namespace ErnestDefoe\CommunityGoals\Api;

use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Present;
use ErnestDefoe\CommunityGoals\Settings;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;

/** One goal: its top ten helpers, where the viewer stands, and for managers what it hands out. */
class ShowController extends Controller
{
    public function __construct(Present $present, private readonly ConnectionInterface $db)
    {
        parent::__construct($present);
    }

    protected function respond(ServerRequestInterface $request, User $actor): mixed
    {
        return $this->detail($this->goal($request, $actor), $actor);
    }

    /** @return array<string, mixed> */
    public function detail(Goal $goal, User $actor): array
    {
        $data = $this->present->summaries([$goal])[0];
        $data['top'] = $this->present->canSeeHelpers($actor, $goal->metric) ? ($this->present->top([$goal->id], 10)[$goal->id] ?? []) : [];

        $data['mine'] = 0;
        $data['rank'] = null;
        if (! $actor->isGuest()) {
            $data['mine'] = (int) $this->db->table('community_goal_contributors')->where('goal_id', $goal->id)->where('user_id', $actor->id)->value('count');
            if ($data['mine']) {
                $data['rank'] = 1 + $this->db->table('community_goal_contributors')->where('goal_id', $goal->id)->where('count', '>', $data['mine'])->count();
            }
        }

        $canManage = $actor->can(Settings::MANAGE);
        if ($canManage) {
            $data['rewards'] = $goal->rewards ?? (object) [];
            $data['rewardState'] = $goal->reward_state ?? (object) [];
            $data['tagId'] = $goal->tag_id;
            $data['countedAt'] = $goal->counted_at?->toIso8601String();
        }

        $users = $this->present->users(array_column($data['top'], 0), $actor);
        $seen = array_column($users, 'id');
        $data['top'] = array_values(array_filter($data['top'], fn ($row) => in_array($row[0], $seen, true)));

        return [
            'goal' => $data,
            'users' => $users,
            'canManage' => $canManage,
            'now' => Present::now(),
        ];
    }
}
