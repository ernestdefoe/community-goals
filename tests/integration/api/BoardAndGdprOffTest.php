<?php

namespace ErnestDefoe\CommunityGoals\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\CommunityGoals\Board;
use ErnestDefoe\CommunityGoals\Goal;
use Flarum\Gdpr\Events\Erasing;
use Flarum\Gdpr\Models\ErasureRequest;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Attributes\Test;

/**
 * The banner's goals (two capped queries) against the single CASE-ordered
 * query they replaced, and an erasure while flarum/gdpr is OFF.
 */
class BoardAndGdprOffTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        // flarum/gdpr is installed (a dev dependency) but deliberately NOT enabled here.
        $this->extension('ernestdefoe-community-goals');
        $this->prepareDatabase([User::class => [$this->normalUser()]]);
    }

    private function goal(string $title, array $row): int
    {
        return $this->database()->table('community_goals')->insertGetId($row + [
            'title' => $title,
            'metric' => 'custom',
            'target' => 100,
            'progress' => 1,
            'starts_at' => Carbon::now()->subDays(10),
            'ends_at' => Carbon::now()->addDays(5),
            'contributors_count' => 0,
            'user_id' => 1,
        ]);
    }

    /** The query Board ran up to 1.0.4: one ORDER BY a CASE on hit_at, then ends_at, capped. */
    private function oldBoardIds(): array
    {
        $now = Carbon::now();

        return Goal::query()
            ->where('starts_at', '<=', $now)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('closed_at')->where('ends_at', '>', $now))
                ->orWhere('hit_at', '>=', $now->copy()->subDays(2)))
            ->orderByRaw('CASE WHEN hit_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('ends_at')
            ->limit(Board::CACHED)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function boardIds(): array
    {
        $board = $this->app()->getContainer()->make(Board::class);
        $board->forget();

        return array_map(fn ($goal) => (int) $goal['id'], $board->get());
    }

    #[Test]
    public function the_board_shows_the_same_goals_in_the_same_order_as_the_case_query(): void
    {
        $now = Carbon::now();
        $running3 = $this->goal('running, ends in 3 days', ['ends_at' => $now->copy()->addDays(3)]);
        $running1 = $this->goal('running, ends tomorrow', ['ends_at' => $now->copy()->addDay()]);
        $reachedOpen = $this->goal('reached 5 days ago, still open', ['hit_at' => $now->copy()->subDays(5), 'ends_at' => $now->copy()->addDays(2)]);
        $reachedClosed = $this->goal('reached yesterday, closed', ['hit_at' => $now->copy()->subDay(), 'closed_at' => $now->copy()->subHours(2), 'ends_at' => $now->copy()->subHours(2)]);
        $this->goal('reached 5 days ago, closed', ['hit_at' => $now->copy()->subDays(5), 'closed_at' => $now->copy()->subDays(4), 'ends_at' => $now->copy()->subDays(4)]);
        $this->goal('upcoming', ['starts_at' => $now->copy()->addDay(), 'ends_at' => $now->copy()->addDays(9)]);
        $this->goal('missed and closed', ['closed_at' => $now->copy()->subDay(), 'ends_at' => $now->copy()->subDay()]);
        $this->goal('ended, not closed yet', ['ends_at' => $now->copy()->subMinute()]);

        $expected = [$running1, $running3, $reachedClosed, $reachedOpen];
        $this->assertSame($expected, $this->oldBoardIds(), 'the reference query');
        $this->assertSame($expected, $this->boardIds());

        // Over the cap: running goals fill it first, in both, and reached ones drop off the end.
        for ($i = 1; $i <= Board::CACHED; $i++) {
            $this->goal("running $i", ['ends_at' => $now->copy()->addDays(10 + $i)]);
        }
        $this->assertSame($this->oldBoardIds(), $this->boardIds());
        $this->assertNotContains($reachedOpen, $this->boardIds());

        // 9 running goals leave room for one reached goal: the second query is cut to 1.
        $this->database()->table('community_goals')->whereIn('title', ['running 8', 'running 9', 'running 10'])->delete();
        $ids = $this->boardIds();
        $this->assertSame($this->oldBoardIds(), $ids);
        $this->assertCount(Board::CACHED, $ids);
        $this->assertSame($reachedClosed, end($ids), 'the reached goal ending soonest takes the last place');
        $this->assertNotContains($reachedOpen, $ids);
    }

    #[Test]
    public function an_erasure_with_gdpr_off_registers_nothing_and_changes_nothing(): void
    {
        $id = $this->goal('hand kept', ['progress' => 4]);
        $this->database()->table('community_goal_contributors')->insert(['goal_id' => $id, 'user_id' => 2, 'count' => 4]);

        $events = $this->app()->getContainer()->make(Dispatcher::class);
        $this->assertFalse($events->hasListeners(Erasing::class), 'no Erasing listener while flarum/gdpr is off');

        $request = new ErasureRequest();
        $request->user_id = 2;
        $events->dispatch(new Erasing($request));

        $this->assertSame(4, (int) Goal::query()->findOrFail($id)->progress);
        $this->assertSame(1, $this->database()->table('community_goal_contributors')->where('goal_id', $id)->count());

        // The forum boots and serves normally without it.
        $response = $this->send($this->request('GET', '/api'));
        $this->assertSame(200, $response->getStatusCode());
    }
}
