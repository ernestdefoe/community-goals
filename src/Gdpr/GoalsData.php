<?php

namespace ErnestDefoe\CommunityGoals\Gdpr;

use ErnestDefoe\CommunityGoals\Tally;
use Flarum\Gdpr\Data\Type;

/**
 * A member's export: how much they added to each goal. Erasing takes their
 * part out of running goals (finished goals keep their final number) and
 * forgets their name on every goal; goals they created stay, without them.
 */
class GoalsData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-community-goals.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-community-goals.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $rows = resolve('db')->table('community_goal_contributors')
            ->join('community_goals', 'community_goals.id', '=', 'community_goal_contributors.goal_id')
            ->where('community_goal_contributors.user_id', $this->user->id)
            ->get(['community_goals.id as goal', 'community_goals.title', 'community_goals.metric', 'community_goal_contributors.count'])
            ->toArray();

        return [['community-goals/contributions.json' => $this->encodeForExport($rows)]];
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        resolve(Tally::class)->forgetUser($this->user->id);
        resolve('db')->table('community_goals')->where('user_id', $this->user->id)->update(['user_id' => null]);
    }
}
