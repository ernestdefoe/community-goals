<?php

namespace ErnestDefoe\CommunityGoals\Api;

use Carbon\Carbon;
use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Present;
use ErnestDefoe\CommunityGoals\Settings;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /goals: everything running or coming up, with each goal's top helpers,
 * and the history, newest first, a page at a time. A fixed number of
 * queries for any number of goals.
 */
class ListController extends Controller
{
    public const PAST_PER_PAGE = 10;

    public function __construct(Present $present, private readonly ConnectionInterface $db)
    {
        parent::__construct($present);
    }

    protected function respond(ServerRequestInterface $request, User $actor): mixed
    {
        $page = max(1, (int) Arr::get($request->getQueryParams(), 'page', 1));
        $now = Carbon::now();
        $current = fn ($q) => $q->whereNull('closed_at')->where('ends_at', '>', $now);

        $open = $page === 1
            ? Goal::query()->where($current)->orderBy('starts_at')->orderBy('ends_at')->limit(20)->get()
            : collect();
        $past = Goal::query()->whereNot($current)->orderByDesc('ends_at')->orderByDesc('id')
            ->offset(($page - 1) * self::PAST_PER_PAGE)->limit(self::PAST_PER_PAGE + 1)->get();
        $more = $past->count() > self::PAST_PER_PAGE;

        $open = $this->present->visible($actor, $this->present->summaries($open));
        $past = $this->present->visible($actor, $this->present->summaries($past->take(self::PAST_PER_PAGE)));

        $shown = fn (array $goals) => array_column(array_filter($goals, fn ($g) => $this->present->canSeeHelpers($actor, $g['metric'])), 'id');
        $top = $this->present->top($shown($open), 5) + $this->present->top($shown($past), 3);
        $mine = $actor->isGuest() ? [] : $this->db->table('community_goal_contributors')
            ->where('user_id', $actor->id)
            ->whereIn('goal_id', [...array_column($open, 'id'), ...array_column($past, 'id')])
            ->pluck('count', 'goal_id')->all();

        $add = function (array $goal) use ($top, $mine) {
            $goal['top'] = $top[$goal['id']] ?? [];
            $goal['mine'] = (int) ($mine[$goal['id']] ?? 0);

            return $goal;
        };
        $open = array_map($add, $open);
        $past = array_map($add, $past);

        $userIds = [];
        foreach ([...$open, ...$past] as $goal) {
            foreach ($goal['top'] as [$userId]) {
                $userIds[] = $userId;
            }
        }

        // Helpers the viewer may not see are left out of the lists, not shown as "deleted".
        $users = $this->present->users($userIds, $actor);
        $seen = array_column($users, 'id');
        $keep = function (array $goal) use ($seen) {
            $goal['top'] = array_values(array_filter($goal['top'], fn ($row) => in_array($row[0], $seen, true)));

            return $goal;
        };

        return [
            'open' => array_map($keep, $open),
            'past' => array_map($keep, $past),
            'more' => $more,
            'users' => $users,
            'canManage' => $actor->can(Settings::MANAGE),
            'now' => Present::now(),
        ];
    }
}
