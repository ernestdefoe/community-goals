<?php

namespace ErnestDefoe\CommunityGoals;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 * @property string|null $description
 * @property string $metric
 * @property int|null $tag_id
 * @property string|null $unit
 * @property int $target
 * @property int $progress
 * @property int $milestone
 * @property string|null $reward
 * @property array<string, mixed>|null $rewards
 * @property array<string, mixed>|null $reward_state
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $hit_at
 * @property Carbon|null $rewarded_at
 * @property Carbon|null $completing_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $counted_at
 * @property int $contributors_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $creator
 */
class Goal extends AbstractModel
{
    public const REPLIES = 'replies';
    public const DISCUSSIONS = 'discussions';
    public const LIKES = 'likes';
    public const MEMBERS = 'members';
    public const TAG_POSTS = 'tag_posts';
    public const CUSTOM = 'custom';

    public const METRICS = [self::REPLIES, self::DISCUSSIONS, self::LIKES, self::MEMBERS, self::TAG_POSTS, self::CUSTOM];

    /** The steps that get a celebration; 100 is the goal itself. */
    public const MILESTONES = [25, 50, 75, 100];

    protected $table = 'community_goals';

    public $timestamps = true;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'tag_id' => 'integer',
        'target' => 'integer',
        'progress' => 'integer',
        'milestone' => 'integer',
        'rewards' => 'array',
        'reward_state' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'hit_at' => 'datetime',
        'rewarded_at' => 'datetime',
        'completing_at' => 'datetime',
        'closed_at' => 'datetime',
        'counted_at' => 'datetime',
        'contributors_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** upcoming, live, hit (reached and still running), done (reached, over) or missed. */
    public function status(?Carbon $now = null): string
    {
        $now ??= Carbon::now();

        if ($this->hit_at) {
            return $this->closed_at || $now->gte($this->ends_at) ? 'done' : 'hit';
        }
        if ($this->closed_at || $now->gte($this->ends_at)) {
            return 'missed';
        }

        return $now->lt($this->starts_at) ? 'upcoming' : 'live';
    }

    /** Whether things that happen now can still move the numbers. */
    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /** Counted by the forum (a ledger) rather than typed in by an admin. */
    public function isCounted(): bool
    {
        return $this->metric !== self::CUSTOM;
    }

    /** The highest milestone a count reaches for this target. */
    public function milestoneFor(int $progress): int
    {
        $reached = 0;
        foreach (self::MILESTONES as $step) {
            if ($progress * 100 >= $step * $this->target) {
                $reached = $step;
            }
        }

        return $reached;
    }
}
