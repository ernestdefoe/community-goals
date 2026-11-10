<?php

namespace ErnestDefoe\CommunityGoals\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Tally;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class GoalsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-likes', 'ernestdefoe-community-goals');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'dana', 'email' => 'dana@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'sam', 'email' => 'sam@machine.local', 'is_email_confirmed' => 1],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'General', 'created_at' => Carbon::now()->subDays(3), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1],
                ['id' => 2, 'title' => 'Private', 'created_at' => Carbon::now()->subDays(3), 'user_id' => 2, 'first_post_id' => 2, 'comment_count' => 1, 'last_post_number' => 1, 'is_private' => true],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Hello</p></t>', 'created_at' => Carbon::now()->subDays(3), 'number' => 1],
                ['id' => 2, 'discussion_id' => 2, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Secret</p></t>', 'created_at' => Carbon::now()->subDays(3), 'number' => 1, 'is_private' => true],
            ],
            'group_permission' => [
                ['permission' => 'postWithoutThrottle', 'group_id' => Group::MEMBER_ID],
                ['permission' => 'discussion.likePosts', 'group_id' => Group::MEMBER_ID],
            ],
        ]);
    }

    /** @return array{int, array<string, mixed>|null} */
    private function api(string $method, string $path, ?int $as, array $body = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        $response = $this->send($this->request($method, '/api/community-goals'.$path, $options));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function goal(array $overrides = []): int
    {
        [$status, $body] = $this->api('POST', '', 1, $overrides + [
            'title' => 'Reply Rally',
            'metric' => 'replies',
            'target' => 4,
            'startsAt' => Carbon::now()->subDay()->toIso8601String(),
            'endsAt' => Carbon::now()->addDays(6)->toIso8601String(),
            'reward' => 'A party',
        ]);
        $this->assertSame(201, $status, json_encode($body));

        return $body['goal']['id'];
    }

    private function reply(int $as, int $discussion = 1): int
    {
        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'posts', 'attributes' => ['content' => 'Counting this one'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $discussion]]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) json_decode((string) $response->getBody(), true)['data']['id'];
    }

    private function patchPost(int $id, array $attributes, int $as = 1): void
    {
        $response = $this->send($this->request('PATCH', "/api/posts/$id", [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'posts', 'id' => (string) $id, 'attributes' => $attributes]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    private function fresh(int $id): Goal
    {
        return Goal::query()->findOrFail($id);
    }

    #[Test]
    public function replies_count_and_hiding_restoring_deleting_is_idempotent(): void
    {
        $id = $this->goal();

        $a = $this->reply(3);
        $this->reply(3);
        $this->reply(4);
        $goal = $this->fresh($id);
        $this->assertSame(3, $goal->progress);
        $this->assertSame(2, $goal->contributors_count);
        $this->assertSame(75, $goal->milestone);

        // Hidden: taken off. Hidden again (a duplicate event): nothing more.
        $this->patchPost($a, ['isHidden' => true]);
        $this->assertSame(2, $this->fresh($id)->progress);
        $this->app()->getContainer()->make(Tally::class)->post($a, 1, false);
        $this->assertSame(2, $this->fresh($id)->progress);

        // Restored: counted again, once.
        $this->patchPost($a, ['isHidden' => false]);
        $this->app()->getContainer()->make(Tally::class)->post($a, 1, false);
        $this->assertSame(3, $this->fresh($id)->progress);

        // Deleted for good: gone, and the milestone that was celebrated stays.
        $this->send($this->request('DELETE', "/api/posts/$a", ['authenticatedAs' => 1]));
        $goal = $this->fresh($id);
        $this->assertSame(2, $goal->progress);
        $this->assertSame(75, $goal->milestone);
        $this->assertSame(1, (int) $this->database()->table('community_goal_contributors')->where('goal_id', $id)->where('user_id', 3)->value('count'));
    }

    #[Test]
    public function first_posts_private_posts_and_hidden_discussions_do_not_count(): void
    {
        $id = $this->goal();

        $this->reply(3);
        // A reply in a private discussion (as Byobu would write it).
        $this->database()->table('posts')->insert(['id' => 50, 'discussion_id' => 2, 'user_id' => 3, 'type' => 'comment', 'content' => '<t><p>Psst</p></t>', 'created_at' => Carbon::now(), 'number' => 2, 'is_private' => true]);
        $this->app()->getContainer()->make(Tally::class)->post(50, 2, false);
        $this->assertSame(1, $this->fresh($id)->progress);

        $this->send($this->request('PATCH', '/api/discussions/1', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'discussions', 'id' => '1', 'attributes' => ['isHidden' => true]]],
        ]));
        $this->assertSame(0, $this->fresh($id)->progress);

        $this->send($this->request('PATCH', '/api/discussions/1', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'discussions', 'id' => '1', 'attributes' => ['isHidden' => false]]],
        ]));
        $this->assertSame(1, $this->fresh($id)->progress);
    }

    #[Test]
    public function a_goal_set_after_the_fact_counts_what_already_happened(): void
    {
        $this->reply(3);
        $this->reply(4);
        Post::query()->whereKey(1)->update(['created_at' => Carbon::now()->subHours(2)]);

        $id = $this->goal(['metric' => 'replies']);
        $goal = $this->fresh($id);
        $this->assertSame(2, $goal->progress);
        $this->assertNotNull($goal->counted_at);

        // A window that starts later leaves them out.
        $later = $this->goal(['startsAt' => Carbon::now()->addMinutes(5)->toIso8601String()]);
        $this->assertSame(0, $this->fresh($later)->progress);
    }

    #[Test]
    public function likes_count_once_and_never_your_own(): void
    {
        $id = $this->goal(['metric' => 'likes', 'target' => 10]);
        $post = $this->reply(3);

        $this->patchPost($post, ['isLiked' => true], 4);
        $this->patchPost($post, ['isLiked' => true], 2);
        $this->assertSame(2, $this->fresh($id)->progress);

        $this->patchPost($post, ['isLiked' => false], 4);
        $this->assertSame(1, $this->fresh($id)->progress);

        // A post's author liking it doesn't count (if the forum even allows it).
        $this->database()->table('post_likes')->insert(['post_id' => $post, 'user_id' => 3, 'created_at' => Carbon::now()]);
        $this->app()->getContainer()->make(Tally::class)->post($post, 1, false, [Goal::LIKES]);
        $this->assertSame(1, $this->fresh($id)->progress);
    }

    #[Test]
    public function reaching_the_goal_notifies_helpers_once_and_announces_it(): void
    {
        $id = $this->goal(['target' => 2, 'rewards' => ['announce' => ['title' => '', 'body' => 'We hit {progress} of {target}!']]]);

        $this->reply(3);
        $this->reply(4);
        $this->reply(4);

        $goal = $this->fresh($id);
        $this->assertNotNull($goal->hit_at);
        $this->assertNotNull($goal->rewarded_at);
        $this->assertSame(100, $goal->milestone);
        $this->assertSame(3, $goal->progress, 'A reached goal keeps counting until it ends.');

        $notified = $this->database()->table('notifications')->where('type', 'communityGoalReached')->pluck('user_id')->sort()->values()->all();
        $this->assertSame([3, 4], array_map('intval', $notified));

        $discussionId = $goal->reward_state['announce']['discussion'] ?? null;
        $this->assertNotNull($discussionId, json_encode($goal->reward_state));
        $this->assertStringContainsString('We hit 2 of 2!', (string) Post::query()->where('discussion_id', $discussionId)->value('content'));

        // Removing a post afterwards doesn't take the goal back.
        $this->assertNotNull($this->fresh($id)->hit_at);
    }

    #[Test]
    public function closed_goals_are_frozen(): void
    {
        $id = $this->goal();
        $this->reply(3);
        Goal::query()->whereKey($id)->update(['ends_at' => Carbon::now()->subMinute()]);
        $this->app()->getContainer()->make(\ErnestDefoe\CommunityGoals\Goals::class)->changed();

        $this->app()->getContainer()->make(\ErnestDefoe\CommunityGoals\Console\TickCommand::class)
            ->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\BufferedOutput());
        $goal = $this->fresh($id);
        $this->assertNotNull($goal->closed_at);
        $this->assertSame('missed', $goal->status());

        $this->reply(4);
        $this->assertSame(1, $this->fresh($id)->progress);

        [$status, $body] = $this->api('PATCH', "/$id", 1, ['title' => 'Renamed', 'target' => 1]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('Renamed', $body['goal']['title']);
        $this->assertSame(4, $body['goal']['target']);
    }

    #[Test]
    public function a_hand_kept_goal_moves_only_by_hand(): void
    {
        $id = $this->goal(['metric' => 'custom', 'target' => 10, 'progress' => 3, 'unit' => 'pages']);
        $this->reply(3);
        $this->assertSame(3, $this->fresh($id)->progress);

        $this->assertSame(200, $this->api('POST', "/$id/progress", 1, ['add' => 4])[0]);
        $this->assertSame(7, $this->fresh($id)->progress);
        $this->assertSame(200, $this->api('POST', "/$id/progress", 1, ['set' => 10])[0]);
        $this->assertNotNull($this->fresh($id)->hit_at);

        $counted = $this->goal();
        $this->assertSame(422, $this->api('POST', "/$counted/progress", 1, ['add' => 4])[0]);
    }

    #[Test]
    public function only_managers_set_goals_and_everyone_sees_them(): void
    {
        $this->assertContains($this->api('POST', '', null, ['title' => 'x'])[0], [400, 401]);
        $this->assertSame(403, $this->api('POST', '', 3, ['title' => 'x', 'metric' => 'replies', 'target' => 5])[0]);
        $this->assertSame(403, $this->api('GET', '/options', 3)[0]);

        $id = $this->goal();
        $this->assertSame(403, $this->api('PATCH', "/$id", 3, ['title' => 'Mine now'])[0]);
        $this->assertSame(403, $this->api('DELETE', "/$id", 3)[0]);
        $this->assertSame(403, $this->api('POST', "/$id/recount", 3)[0]);

        [$status, $list] = $this->api('GET', '', null);
        $this->assertSame(200, $status);
        $this->assertSame('Reply Rally', $list['open'][0]['title']);
        $this->assertFalse($list['canManage']);

        [$status, $show] = $this->api('GET', "/$id", 3);
        $this->assertSame(200, $status);
        $this->assertArrayNotHasKey('rewards', $show['goal'], 'Reward settings are for managers.');

        $forum = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true);
        $this->assertSame('Reply Rally', $forum['data']['attributes']['communityGoals'][0]['title']);
    }

    #[Test]
    public function bad_input_is_refused(): void
    {
        foreach ([
            ['title' => '', 'metric' => 'replies', 'target' => 5],
            ['title' => 'x', 'metric' => 'posts_everywhere', 'target' => 5],
            ['title' => 'x', 'metric' => 'replies', 'target' => 0],
            ['title' => 'x', 'metric' => 'replies', 'target' => 5, 'endsAt' => Carbon::now()->subDay()->toIso8601String()],
            ['title' => 'x', 'metric' => 'replies', 'target' => 5, 'endsAt' => Carbon::now()->addYears(2)->toIso8601String()],
            ['title' => 'x', 'metric' => 'tag_posts', 'target' => 5, 'endsAt' => Carbon::now()->addDay()->toIso8601String()],
        ] as $body) {
            $this->assertSame(422, $this->api('POST', '', 1, $body)[0], json_encode($body));
        }

        // Rewards from extensions that aren't on are dropped, not stored.
        $id = $this->goal(['rewards' => ['theme' => ['id' => 'x'], 'badge' => ['id' => 1], 'nonsense' => true]]);
        $this->assertNull($this->fresh($id)->rewards);
    }

    #[Test]
    public function new_members_count_when_confirmed_and_deleted_members_are_forgotten(): void
    {
        $id = $this->goal(['metric' => 'members', 'target' => 5]);
        $before = $this->fresh($id)->progress; // the test install's own admin joined today

        $this->database()->table('users')->insert(['id' => 5, 'username' => 'newbie', 'email' => 'n@machine.local', 'password' => '', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now()]);
        $this->app()->getContainer()->make(Tally::class)->member(5);
        $this->app()->getContainer()->make(Tally::class)->member(5);
        $this->assertSame($before + 1, $this->fresh($id)->progress);

        // Who joined is only shown to those who may see the member list (guests can't, by default).
        [, $show] = $this->api('GET', "/$id", null);
        $this->assertSame([], $show['goal']['top']);
        [, $show] = $this->api('GET', "/$id", 1);
        $this->assertContains(5, array_column($show['goal']['top'], 0));

        $this->send($this->request('DELETE', '/api/users/5', ['authenticatedAs' => 1]));
        $goal = $this->fresh($id);
        $this->assertSame($before, $goal->progress);
        $this->assertSame($before, $goal->contributors_count);
    }

    #[Test]
    public function deleting_a_discussion_takes_its_replies_back(): void
    {
        $id = $this->goal();
        $this->reply(3);
        $this->reply(4);
        $this->assertSame(2, $this->fresh($id)->progress);

        $this->send($this->request('DELETE', '/api/discussions/1', ['authenticatedAs' => 1]));
        $goal = $this->fresh($id);
        $this->assertSame(0, $goal->progress);
        $this->assertSame(0, $goal->contributors_count);
    }
}
