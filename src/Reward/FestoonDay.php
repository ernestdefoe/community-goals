<?php

namespace ErnestDefoe\CommunityGoals\Reward;

use Carbon\Carbon;
use ErnestDefoe\CommunityGoals\Goal;
use Ernestdefoe\Festoon\Config;
use Ernestdefoe\Festoon\Look\Catalogue;
use Flarum\Settings\SettingsRepositoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Dresses the forum in one of Festoon's looks for a few days, starting the
 * day the goal is reached: a look of the administrator's own (Festoon's
 * custom looks) copied from the chosen one, named after the goal, at the
 * top priority so it wins over whatever holiday is on. Festoon's custom
 * looks repeat every year, so it is removed again when its days are up.
 */
class FestoonDay implements Reward
{
    /** What is copied from the chosen look: its decorations, never its dates. */
    private const PARTS = ['primary', 'secondary', 'garland', 'ornament', 'corner', 'hat', 'trim', 'effect', 'emoji', 'svg'];

    public function __construct(
        private readonly Config $config,
        private readonly SettingsRepositoryInterface $settings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function options(): mixed
    {
        return array_map(
            fn (string $id) => ['id' => $id, 'title' => $this->translator->trans('ernestdefoe-festoon.lib.looks.'.$id)],
            array_keys(Catalogue::all())
        );
    }

    public function clean(array $config): ?array
    {
        $look = (string) ($config['look'] ?? '');
        if (! isset(Catalogue::all()[$look])) {
            return null;
        }

        return ['look' => $look, 'days' => max(1, min(14, (int) ($config['days'] ?? 1)))];
    }

    public function apply(Goal $goal, array $config): array
    {
        $look = Catalogue::all()[$config['look']] ?? null;
        if (! $look) {
            return ['skipped' => 'look_gone'];
        }

        $first = Carbon::now($this->config->timezone())->startOfDay();
        $last = $first->copy()->addDays((int) $config['days'] - 1);
        $id = 'goal-'.$goal->id;

        $custom = $this->without($id);
        $custom[] = array_intersect_key($look, array_flip(self::PARTS)) + [
            'id' => $id,
            'name' => mb_substr($goal->title, 0, 60),
            'enabled' => true,
            'priority' => 100,
            'from' => $first->format('m-d'),
            'to' => $last->format('m-d'),
        ];
        $this->settings->set(Config::CUSTOM, json_encode($custom));

        return ['id' => $id, 'look' => $config['look'], 'until' => $last->copy()->addDay()->getTimestamp()];
    }

    public function revert(Goal $goal, array $done): void
    {
        $this->settings->set(Config::CUSTOM, json_encode($this->without((string) ($done['id'] ?? ''))));
    }

    /** @return list<mixed> Festoon's custom looks minus ours. */
    private function without(string $id): array
    {
        $custom = json_decode((string) $this->settings->get(Config::CUSTOM, '[]'), true);

        return array_values(array_filter(is_array($custom) ? $custom : [], fn ($def) => ! is_array($def) || ($def['id'] ?? null) !== $id));
    }
}
