<?php

namespace ErnestDefoe\CommunityGoals\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\CommunityGoals\Gdpr\GoalsData;
use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Tally;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Http\UrlGenerator;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The paths no ordinary post reaches: a member's GDPR export and erasure, and
 * a ledger row that appears between Tally's read and its write.
 */
class LedgerEdgesTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use CallsGoalsApi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-community-goals');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'dana', 'email' => 'dana@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'sam', 'email' => 'sam@machine.local', 'is_email_confirmed' => 1],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'General', 'created_at' => Carbon::now()->subDays(3), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Hello</p></t>', 'created_at' => Carbon::now()->subDays(3), 'number' => 1],
            ],
            'group_permission' => [
                ['permission' => 'postWithoutThrottle', 'group_id' => Group::MEMBER_ID],
            ],
        ]);
    }

    private function goal(int $as = 1): int
    {
        [$status, $body] = $this->api('POST', '', $as, [
            'title' => 'Reply Rally',
            'metric' => 'replies',
            'target' => 50,
            'startsAt' => Carbon::now()->subDay()->toIso8601String(),
            'endsAt' => Carbon::now()->addDays(6)->toIso8601String(),
        ]);
        $this->assertSame(201, $status, json_encode($body));

        return $body['goal']['id'];
    }

    private function reply(int $as): int
    {
        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'posts', 'attributes' => ['content' => 'Counting this one'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) json_decode((string) $response->getBody(), true)['data']['id'];
    }

    /** Built exactly as flarum/gdpr's Exporter and ErasureJob build every data type. */
    private function gdpr(int $userId): GoalsData
    {
        $c = $this->app()->getContainer();

        return new GoalsData(
            User::query()->findOrFail($userId),
            null,
            $c->make(Factory::class),
            $c->make(SettingsRepositoryInterface::class),
            $c->make(UrlGenerator::class),
            $c->make(TranslatorInterface::class),
        );
    }

    #[Test]
    public function a_members_export_lists_their_part_and_erasure_takes_it_out(): void
    {
        $id = $this->goal();
        $this->reply(3);
        $this->reply(3);
        $this->reply(4);
        $this->assertSame(3, Goal::query()->findOrFail($id)->progress);

        $export = $this->gdpr(3)->export();
        $rows = json_decode($export[0]['community-goals/contributions.json'], true);
        $this->assertCount(1, $rows);
        $this->assertSame($id, (int) $rows[0]['goal']);
        $this->assertSame(2, (int) $rows[0]['count']);

        $this->gdpr(3)->delete();
        $goal = Goal::query()->findOrFail($id);
        $this->assertSame(1, $goal->progress);
        $this->assertSame(1, $goal->contributors_count);
        $this->assertSame(0, $this->database()->table('community_goal_entries')->where('user_id', 3)->count());
        $this->assertSame(1, $goal->user_id, 'erasing a helper leaves the goal\'s creator alone');

        // Erasing the creator keeps the goal, without their name.
        $this->gdpr(1)->delete();
        $this->assertNull(Goal::query()->findOrFail($id)->user_id);
    }

    #[Test]
    public function a_row_written_behind_tallys_back_is_counted_once(): void
    {
        $id = $this->goal();
        $this->reply(3);
        $post = $this->reply(3);
        $this->database()->table('community_goal_entries')->where('subject_id', $post)->delete();
        $this->database()->table('community_goals')->where('id', $id)->update(['progress' => 1]);
        $this->database()->table('community_goal_contributors')->where('goal_id', $id)->where('user_id', 3)->update(['count' => 1]);

        // Right after Tally reads the ledger, another writer (one that skipped the lock) adds the row.
        $armed = true;
        $this->database()->listen(function (QueryExecuted $query) use (&$armed, $id, $post) {
            if ($armed && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'community_goal_entries') && in_array($post, $query->bindings, true)) {
                $armed = false;
                $this->database()->table('community_goal_entries')->insert(['goal_id' => $id, 'subject_id' => $post, 'user_id' => 3, 'discussion_id' => 1]);
            }
        });

        $this->app()->getContainer()->make(Tally::class)->post($post, 1, false);

        $this->assertFalse($armed, 'the ledger read was seen');
        $goal = Goal::query()->findOrFail($id);
        $this->assertSame(2, $goal->progress);
        $this->assertSame(2, (int) $this->database()->table('community_goal_contributors')->where('goal_id', $id)->where('user_id', 3)->value('count'));
        $this->assertSame(2, $this->database()->table('community_goal_entries')->where('goal_id', $id)->count());
    }
}
