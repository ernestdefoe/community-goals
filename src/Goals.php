<?php

namespace ErnestDefoe\CommunityGoals;

use Carbon\Carbon;
use ErnestDefoe\CommunityGoals\Job\RecountGoal;
use Flarum\Extension\ExtensionManager;
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
        private readonly ExtensionManager $extensions,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function save(?Goal $goal, array $input, User $actor): Goal
    {
        $creating = $goal === null;
        $goal ??= new Goal();
        $frozen = ! $creating && ($goal->hit_at || $goal->closed_at);

        $text = fn (string $key, int $max) => mb_substr(trim(strip_tags((string) ($input[$key] ?? ''))), 0, $max);

        if ($creating || array_key_exists('title', $input)) {
            $goal->title = $text('title', 100);
            if ($goal->title === '') {
                $this->fail('title', 'title_required');
            }
        }
        if ($creating || array_key_exists('description', $input)) {
            $goal->description = $text('description', 1000) ?: null;
        }
        if ($creating || array_key_exists('unit', $input)) {
            $goal->unit = $text('unit', 40) ?: null;
        }
        if ($creating || array_key_exists('reward', $input)) {
            $goal->reward = $text('reward', 200) ?: null;
        }

        if (! $frozen) {
            if ($creating || array_key_exists('metric', $input)) {
                $metric = (string) ($input['metric'] ?? '');
                if (! in_array($metric, Goal::METRICS, true)
                    || ($metric === Goal::LIKES && ! $this->extensions->isEnabled('flarum-likes'))
                    || ($metric === Goal::TAG_POSTS && ! $this->extensions->isEnabled('flarum-tags'))) {
                    $this->fail('metric', 'metric_invalid');
                }
                $goal->metric = $metric;
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

            if ($creating || array_key_exists('rewards', $input)) {
                $goal->rewards = $this->rewards->clean($input['rewards'] ?? []) ?: null;
            }

            if ($creating) {
                $goal->progress = 0;
                $goal->milestone = 0;
                $goal->contributors_count = 0;
                $goal->user_id = $actor->id;
                if ($goal->metric === Goal::CUSTOM) {
                    $goal->progress = max(0, min(self::MAX_TARGET, (int) ($input['progress'] ?? 0)));
                }
            }
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

    /** For a goal counted by hand (custom): add to it, or set it. */
    public function setProgress(Goal $goal, ?int $add, ?int $set): void
    {
        if ($goal->isCounted() || $goal->closed_at) {
            $this->fail('progress', 'progress_locked');
        }

        $change = $set !== null ? max(0, min(self::MAX_TARGET, $set)) - $goal->progress : (int) $add;
        $change = max(-$goal->progress, min(self::MAX_TARGET - $goal->progress, $change));
        if ($change) {
            $this->tally->adjust($goal->id, [], $change);
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

    private function fail(string $field, string $key): never
    {
        throw new ValidationException([$field => $this->translator->trans('ernestdefoe-community-goals.api.'.$key)]);
    }
}
