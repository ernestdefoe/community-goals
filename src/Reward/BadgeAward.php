<?php

namespace ErnestDefoe\CommunityGoals\Reward;

use ErnestDefoe\CommunityGoals\Goal;
use FoF\Badges\Badge;
use FoF\Badges\BadgeAwarder;
use FoF\Badges\UserBadge;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gives a FoF Badges badge to everyone who helped (at least `min` times).
 * Only badges without a trigger are offered: FoF Badges' recalculation would
 * take a triggered badge away again from members who don't meet its rule.
 */
class BadgeAward implements Reward
{
    public function __construct(
        private readonly BadgeAwarder $awarder,
        private readonly ConnectionInterface $db,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function options(): mixed
    {
        return Badge::query()->whereNull('trigger_config')->where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'icon', 'icon_color', 'background_color'])
            ->map(fn (Badge $badge) => ['id' => $badge->id, 'title' => $badge->name, 'icon' => $badge->icon, 'color' => $badge->icon_color, 'background' => $badge->background_color])
            ->all();
    }

    public function clean(array $config): ?array
    {
        $id = (int) ($config['id'] ?? 0);
        if (! Badge::query()->whereKey($id)->whereNull('trigger_config')->exists()) {
            return null;
        }

        return ['id' => $id, 'min' => max(1, min(10000, (int) ($config['min'] ?? 1)))];
    }

    public function apply(Goal $goal, array $config): array
    {
        $badge = Badge::query()->find($config['id']);
        if (! $badge) {
            return ['skipped' => 'badge_gone'];
        }

        $reason = mb_substr((string) $this->translator->trans('ernestdefoe-community-goals.lib.badge_reason', ['title' => $goal->title]), 0, 500);
        $awarded = 0;

        User::query()
            ->whereIn('id', $this->db->table('community_goal_contributors')->where('goal_id', $goal->id)->where('count', '>=', $config['min'])->select('user_id'))
            ->chunkById(200, function ($users) use ($badge, $reason, &$awarded) {
                foreach ($users as $user) {
                    [$userBadge, $created] = $this->awarder->award($user, $badge, UserBadge::GRANTED_BY_API);
                    if ($created) {
                        $userBadge->reason = $reason;
                        $userBadge->save();
                        $awarded++;
                    }
                }
            });

        return ['id' => $badge->id, 'awarded' => $awarded];
    }

    public function revert(Goal $goal, array $done): void
    {
        // Not timed: a badge, once earned, is kept.
    }
}
