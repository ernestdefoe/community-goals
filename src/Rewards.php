<?php

namespace ErnestDefoe\CommunityGoals;

use Carbon\Carbon;
use ErnestDefoe\CommunityGoals\Reward\Announcement;
use ErnestDefoe\CommunityGoals\Reward\BadgeAward;
use ErnestDefoe\CommunityGoals\Reward\FestoonDay;
use ErnestDefoe\CommunityGoals\Reward\WardrobeTheme;
use Flarum\Extension\ExtensionManager;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * The automatic rewards a goal can carry, each from an extension that may
 * not be there: a Wardrobe theme made the forum's default, a Festoon look
 * worn for some days, a FoF Badges badge for everyone who helped, and an
 * announcement discussion.
 *
 * 🚨 Each reward class names another extension's classes, so it is only
 * ever constructed after isEnabled() says that extension is on. A reward
 * whose extension has gone since the goal was set is skipped and noted, and
 * one reward failing never stops the others.
 *
 * Stored on the goal: `rewards` (what to do, cleaned by clean()) and
 * `reward_state` (what was done, so a timed reward can be taken back by
 * expire() and a deleted goal can undo what is still running).
 */
class Rewards
{
    /** @var array<string, array{0: string, 1: class-string}> kind => [extension id, class] */
    public const KINDS = [
        'theme' => ['ernestdefoe-wardrobe', WardrobeTheme::class],
        'festoon' => ['ernestdefoe-festoon', FestoonDay::class],
        'badge' => ['fof-badges', BadgeAward::class],
        'announce' => ['', Announcement::class],
    ];

    public function __construct(
        private readonly ExtensionManager $extensions,
        private readonly Container $container,
        private readonly LoggerInterface $log,
    ) {
    }

    /** @return Reward\Reward|null */
    public function kind(string $kind): ?Reward\Reward
    {
        [$extension, $class] = self::KINDS[$kind] ?? ['', null];
        if (! $class || ($extension && ! $this->extensions->isEnabled($extension))) {
            return null;
        }

        return $this->container->make($class);
    }

    /**
     * What the goal form can offer, per available kind.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $out = [];
        foreach (array_keys(self::KINDS) as $kind) {
            if ($reward = $this->kind($kind)) {
                $out[$kind] = $reward->options();
            }
        }

        return $out;
    }

    /**
     * Keep only rewards that are available and well-formed.
     *
     * @return array<string, array<string, mixed>>
     */
    public function clean(mixed $input): array
    {
        $out = [];
        foreach (is_array($input) ? $input : [] as $kind => $config) {
            if (is_string($kind) && is_array($config) && ($reward = $this->kind($kind)) && ($clean = $reward->clean($config)) !== null) {
                $out[$kind] = $clean;
            }
        }

        return $out;
    }

    /** Hand out every reward the goal carries. Returns what was done. @return array<string, mixed> */
    public function apply(Goal $goal): array
    {
        $state = $goal->reward_state ?? [];

        foreach ($goal->rewards ?? [] as $kind => $config) {
            if (isset($state[$kind])) {
                continue; // Already done: a retried job hands nothing out twice.
            }
            try {
                $reward = $this->kind((string) $kind);
                $state[$kind] = $reward ? $reward->apply($goal, (array) $config) : ['skipped' => 'unavailable'];
            } catch (\Throwable $e) {
                $this->log->warning('[community-goals] goal '.$goal->id.' reward '.$kind.' failed: '.$e->getMessage());
                $state[$kind] = ['failed' => mb_substr($e->getMessage(), 0, 200)];
            }
        }

        return $state;
    }

    /**
     * Take back timed rewards whose time is up (or all of them, $all, when
     * the goal is deleted). Returns the new state, or null if nothing changed.
     *
     * @return array<string, mixed>|null
     */
    public function expire(Goal $goal, bool $all = false): ?array
    {
        $state = $goal->reward_state ?? [];
        $changed = false;
        $now = Carbon::now()->getTimestamp();

        foreach ($state as $kind => $done) {
            $until = $done['until'] ?? null;
            if (! is_array($done) || ! empty($done['ended']) || ! $until || (! $all && $until > $now)) {
                continue;
            }
            try {
                $this->kind((string) $kind)?->revert($goal, $done);
            } catch (\Throwable $e) {
                $this->log->warning('[community-goals] goal '.$goal->id.' reward '.$kind.' could not be taken back: '.$e->getMessage());
            }
            // Marked ended either way: a reward whose extension is gone has nothing left to undo.
            $state[$kind]['ended'] = true;
            $changed = true;
        }

        return $changed ? $state : null;
    }
}
