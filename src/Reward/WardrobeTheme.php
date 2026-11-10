<?php

namespace ErnestDefoe\CommunityGoals\Reward;

use Carbon\Carbon;
use ErnestDefoe\CommunityGoals\Goal;
use Ernestdefoe\Wardrobe\ThemeRegistry;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Makes one of Wardrobe's themes the forum's default, for some days or for
 * good. Members who picked a theme of their own keep it. Only themes the
 * forum already offers can be chosen, so nothing has to be built when the
 * goal is reached. Taken back only if the default is still ours: an admin
 * who changed it since wins.
 */
class WardrobeTheme implements Reward
{
    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function options(): mixed
    {
        return $this->themes->listing();
    }

    public function clean(array $config): ?array
    {
        $id = (string) ($config['id'] ?? '');
        if (! in_array($id, $this->themes->choices(), true)) {
            return null;
        }

        return ['id' => $id, 'days' => max(0, min(60, (int) ($config['days'] ?? 0)))];
    }

    public function apply(Goal $goal, array $config): array
    {
        if (! in_array($config['id'], $this->themes->choices(), true)) {
            return ['skipped' => 'theme_gone'];
        }

        $previous = $this->settings->get(ThemeRegistry::DEFAULT);
        $this->settings->set(ThemeRegistry::DEFAULT, $config['id']);

        return [
            'id' => $config['id'],
            'previous' => $previous,
            'until' => $config['days'] ? Carbon::now()->addDays((int) $config['days'])->getTimestamp() : null,
        ];
    }

    public function revert(Goal $goal, array $done): void
    {
        if ($this->settings->get(ThemeRegistry::DEFAULT) === ($done['id'] ?? null)) {
            $done['previous'] === null
                ? $this->settings->delete(ThemeRegistry::DEFAULT)
                : $this->settings->set(ThemeRegistry::DEFAULT, $done['previous']);
        }
    }
}
