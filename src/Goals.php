<?php

namespace ErnestDefoe\CommunityGoals;

use Carbon\Carbon;
use ErnestDefoe\CommunityGoals\Job\RecountGoal;
use Flarum\Foundation\ValidationException;
use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creating, editing and deleting goals. Everything the form sends is checked
 * here; nothing about counting is ever taken from the browser.
 *
 * Once a goal is reached or over, only its wording can change: its numbers
 * and rewards are history. While it is running, changing what it counts or
 * when rebuilds the count (Recount, queued).
 */
class Goals
{
    public const MAX_DAYS = 366;
    public const MAX_TARGET = 10000000;

    public function __construct(
        private readonly OpenGoals $open,
        private readonly Board $board,
        private readonly Rewards $rewards,
        private readonly Tally $tally,
        private readonly Queue $queue,
        private readonly Counting $counting,
        private readonly Integrations $integrations,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function save(?Goal $goal, array $input, User $actor): Goal
    {
        $creating = $goal === null;
        $goal ??= new Goal();
        $frozen = ! $creating && ($goal->hit_at || $goal->closed_at);

        $this->fillWording($goal, $input, $creating);
        if (! $frozen) {
            $this->fillCounting($goal, $input, $creating, $actor);
            $this->refuseIfAlreadyMet($goal, $input, $creating);
        }

        $recount = $goal->isCounted() && ($creating || $goal->isDirty(['metric', 'tag_id', 'starts_at', 'ends_at']));
        $retarget = ! $creating && $goal->isDirty('target');
        if (! $goal->isCounted() && $goal->isDirty('metric') && ! $creating) {
            // Switched to a number typed in by hand: what was counted no longer applies.
            $goal->progress = 0;
        }

        $goal->save();
        $this->changed();

        if ($recount) {
            $this->queue->push(new RecountGoal($goal->id));
        } elseif ($retarget || ($creating && $goal->progress)) {
            $this->tally->check($goal->id);
        }

        return $goal->refresh();
    }

    /**
     * Title, description, unit and reward text: the only things a reached or
     * closed goal still lets you change.
     *
     * @param array<string, mixed> $input
     */
    private function fillWording(Goal $goal, array $input, bool $creating): void
    {
        // Plain text, escaped wherever it is shown: "<3 replies" stays as typed.
        $text = fn (string $key, int $max) => mb_substr(trim((string) ($input[$key] ?? '')), 0, $max);
        $sent = fn (string $key) => $creating || array_key_exists($key, $input);

        if ($sent('title')) {
            $goal->title = $text('title', 100);
            if ($goal->title === '') {
                $this->fail('title', 'title_required');
            }
        }
        if ($sent('description')) {
            $goal->description = $text('description', 1000) ?: null;
        }
        if ($sent('unit')) {
            $goal->unit = $text('unit', 40) ?: null;
        }
        if ($sent('reward')) {
            $goal->reward = $text('reward', 200) ?: null;
        }
    }

    /**
     * What a running goal counts, how far and when, its rewards, and a new
     * goal's starting numbers.
     *
     * @param array<string, mixed> $input
     */
    private function fillCounting(Goal $goal, array $input, bool $creating, User $actor): void
    {
        if ($creating || array_key_exists('metric', $input)) {
            $goal->metric = $this->metric((string) ($input['metric'] ?? ''));
        }

        if ($goal->metric === Goal::TAG_POSTS) {
            $tagId = (int) ($input['tagId'] ?? $goal->tag_id ?? 0);
            if (! Tag::query()->whereKey($tagId)->exists()) {
                $this->fail('tagId', 'tag_required');
            }
            $goal->tag_id = $tagId;
        } else {
            $goal->tag_id = null;
        }

        if ($creating || array_key_exists('target', $input)) {
            $target = (int) ($input['target'] ?? 0);
            if ($target < 1 || $target > self::MAX_TARGET) {
                $this->fail('target', 'target_invalid');
            }
            $goal->target = $target;
        }

        if ($creating || array_key_exists('startsAt', $input) || array_key_exists('endsAt', $input)) {
            $this->fillWindow($goal, $input, $creating);
        }

        // Rewards change site settings and hand out badges: admins only, even
        // where moderators may set goals (their rewards input is ignored).
        if (($creating || array_key_exists('rewards', $input)) && $actor->isAdmin()) {
            $goal->rewards = $this->rewards->clean($input['rewards'] ?? []) ?: null;
        }

        if ($creating) {
            $goal->progress = $goal->metric === Goal::CUSTOM ? max(0, min(self::MAX_TARGET, (int) ($input['progress'] ?? 0))) : 0;
            $goal->milestone = 0;
            $goal->contributors_count = 0;
            $goal->user_id = $actor->id;
        }
    }

    private function metric(string $metric): string
    {
        if (! in_array($metric, Goal::METRICS, true)
            || ($metric === Goal::LIKES && ! $this->integrations->likes())
            || ($metric === Goal::TAG_POSTS && ! $this->integrations->tags())) {
            $this->fail('metric', 'metric_invalid');
        }

        return $metric;
    }

    /** @param array<string, mixed> $input */
    private function fillWindow(Goal $goal, array $input, bool $creating): void
    {
        $starts = $this->date($input['startsAt'] ?? null) ?? ($creating ? Carbon::now() : $goal->starts_at);
        $ends = $this->date($input['endsAt'] ?? null) ?? ($creating ? null : $goal->ends_at);
        if (! $ends || $ends->lte($starts) || $ends->lte(Carbon::now())) {
            $this->fail('endsAt', 'ends_invalid');
        }
        if ($starts->diffInDays($ends) > self::MAX_DAYS) {
            $this->fail('endsAt', 'too_long');
        }
        $goal->starts_at = $starts;
        $goal->ends_at = $ends;
    }

    /**
     * 🚨 A target already met would complete the goal the moment it is saved,
     * for good (notifications, rewards). Only on purpose: `complete`.
     *
     * @param array<string, mixed> $input
     */
    private function refuseIfAlreadyMet(Goal $goal, array $input, bool $creating): void
    {
        if (! empty($input['complete']) || ! ($creating || $goal->isDirty(['target', 'metric', 'tag_id', 'starts_at', 'ends_at']))) {
            return;
        }
        $count = $goal->isCounted() ? ($this->counting->query($goal)?->count() ?? 0) : $goal->progress;
        if ($count >= $goal->target) {
            $this->fail('complete', 'target_reached', ['count' => $count]);
        }
    }

    /**
     * What the goal form can offer on this forum: metrics, tags and the
     * rewards whose extensions are on (rewards for admins only, as in save()).
     *
     * @return array{likes: bool, tags: ?list<array{id: int, title: string, child: bool}>, rewards: ?array<string, mixed>}
     */
    public function formOptions(User $actor): array
    {
        return [
            'likes' => $this->integrations->likes(),
            'tags' => $this->integrations->tags() ? Tag::query()->orderBy('position')->orderBy('name')->get(['id', 'name', 'parent_id'])
                ->map(fn (Tag $tag) => ['id' => (int) $tag->id, 'title' => (string) $tag->name, 'child' => (bool) $tag->parent_id])->values()->all() : null,
            'rewards' => $actor->isAdmin() ? $this->rewards->options() : null,
        ];
    }

    /** The repair button: rebuild a running goal's count in the background. */
    public function recount(Goal $goal): void
    {
        if ($goal->isCounted() && ! $goal->closed_at) {
            $this->queue->push(new RecountGoal($goal->id));
        }
    }

    /** For a goal counted by hand (custom): add to it, or set it. */
    public function setProgress(Goal $goal, ?int $add, ?int $set): void
    {
        if ($goal->isCounted() || $goal->closed_at) {
            $this->fail('progress', 'progress_locked');
        }

        $change = $set !== null ? max(0, min(self::MAX_TARGET, $set)) - $goal->progress : (int) $add;
        $change = max(-$goal->progress, min(self::MAX_TARGET - $goal->progress, $change));
        if ($change) {
            $this->tally->adjust([], [$goal->id => $change]);
        }
    }

    public function delete(Goal $goal): void
    {
        // A theme or a Festoon look still running for this goal is taken back first.
        if ($state = $this->rewards->expire($goal, true)) {
            $goal->reward_state = $state;
        }
        $goal->delete();
        $this->changed();
    }

    /** Anything that changes which goals are open, or what they show. */
    public function changed(): void
    {
        $this->open->forget();
        $this->board->forget();
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->utc()->startOfMinute();
        } catch (\Throwable) {
            $this->fail('startsAt', 'date_invalid');
        }
    }

    /** @param array<string, mixed> $params */
    private function fail(string $field, string $key, array $params = []): never
    {
        throw new ValidationException([$field => $this->translator->trans('ernestdefoe-community-goals.api.'.$key, $params)]);
    }
}
