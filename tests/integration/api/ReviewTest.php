<?php

namespace ErnestDefoe\CommunityGoals\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\CommunityGoals\Console\TickCommand;
use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Job\CompleteGoal;
use ErnestDefoe\CommunityGoals\Reward\Announcement;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** The Command Center's review round 1: each finding, proved fixed. */
class ReviewTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'dana', 'email' => 'dana@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'boss', 'email' => 'boss@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => 4, 'group_id' => Group::MODERATOR_ID],
                ['user_id' => 5, 'group_id' => Group::ADMINISTRATOR_ID],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'General', 'created_at' => Carbon::now()->subDays(3), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Hello</p></t>', 'created_at' => Carbon::now()->subDays(3), 'number' => 1],
            ],
            'group_permission' => [
                ['permission' => 'postWithoutThrottle', 'group_id' => Group::MEMBER_ID],
                ['permission' => 'communityGoals.manage', 'group_id' => Group::MODERATOR_ID],
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

    private function create(array $body, int $as = 1, int $expect = 201): ?int
    {
        [$status, $json] = $this->api('POST', '', $as, $body + [
            'title' => 'Reply Rally',
            'metric' => 'replies',
            'target' => 10,
            'startsAt' => Carbon::now()->subDay()->toIso8601String(),
            'endsAt' => Carbon::now()->addDays(6)->toIso8601String(),
        ]);
        $this->assertSame($expect, $status, json_encode($json));

        return $json['goal']['id'] ?? null;
    }

    private function reply(int $as): void
    {
        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'posts', 'attributes' => ['content' => 'One more'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    private function tick(): void
    {
        $this->app()->getContainer()->make(TickCommand::class)->run(new ArrayInput([]), new BufferedOutput());
    }

    private function discussions(): int
    {
        return $this->database()->table('discussions')->count();
    }

    #[Test]
    public function finishing_a_goal_survives_a_dead_run_and_never_repeats_a_step(): void
    {
        $this->extension('ernestdefoe-community-goals');
        $id = $this->create(['target' => 50, 'rewards' => ['announce' => ['title' => '', 'body' => 'Done!']]]);
        $this->reply(2);
        $this->reply(3);

        // Reached, but the run that took it died after telling helpers (lease still fresh).
        Goal::query()->whereKey($id)->update(['hit_at' => Carbon::now(), 'completing_at' => Carbon::now(), 'reward_state' => json_encode(['_notified' => true])]);
        $before = $this->discussions();

        $this->app()->getContainer()->call([new CompleteGoal($id), 'handle']);
        $this->tick();
        $this->assertNull(Goal::query()->find($id)->rewarded_at, 'A fresh lease belongs to a run still working.');

        // The lease goes stale: the tick takes over and does only what is left.
        Goal::query()->whereKey($id)->update(['completing_at' => Carbon::now()->subMinutes(CompleteGoal::LEASE_MINUTES + 1)]);
        $this->tick();
        $goal = Goal::query()->find($id);
        $this->assertNotNull($goal->rewarded_at);
        $this->assertNull($goal->completing_at);
        $this->assertSame(0, $this->database()->table('notifications')->where('type', 'communityGoalReached')->count(), 'Helpers were already told.');
        $this->assertSame($before + 1, $this->discussions());

        // Run again: nothing more.
        $this->app()->getContainer()->call([new CompleteGoal($id), 'handle']);
        $this->tick();
        $this->assertSame($before + 1, $this->discussions());
    }

    #[Test]
    public function a_target_already_met_is_refused_unless_asked_for(): void
    {
        $this->extension('ernestdefoe-community-goals');
        $this->reply(2);
        $this->reply(3);

        // Counted: the window already holds 2 replies.
        $this->create(['target' => 2], 1, 422);
        [, $json] = $this->api('POST', '', 1, ['title' => 'x', 'metric' => 'replies', 'target' => 1, 'endsAt' => Carbon::now()->addDay()->toIso8601String(), 'startsAt' => Carbon::now()->subDay()->toIso8601String()]);
        $this->assertSame('/data/attributes/complete', $json['errors'][0]['source']['pointer']);

        $id = $this->create(['target' => 3]);
        [$status] = $this->api('PATCH', "/$id", 1, ['target' => 2]);
        $this->assertSame(422, $status);
        $this->assertNull(Goal::query()->find($id)->hit_at);

        [$status] = $this->api('PATCH', "/$id", 1, ['target' => 2, 'complete' => true]);
        $this->assertSame(200, $status);
        $this->assertNotNull(Goal::query()->find($id)->hit_at);

        // Kept by hand: a starting number at the target.
        $this->create(['metric' => 'custom', 'target' => 5, 'progress' => 5], 1, 422);
        $custom = $this->create(['metric' => 'custom', 'target' => 5, 'progress' => 5, 'complete' => true]);
        $this->assertNotNull(Goal::query()->find($custom)->hit_at);
    }

    #[Test]
    public function reached_alerts_outlive_the_admin_who_set_the_goal(): void
    {
        $this->extension('ernestdefoe-community-goals');
        $id = $this->create(['target' => 1], 5);
        $this->reply(3);
        $this->assertNotNull(Goal::query()->find($id)->rewarded_at);

        User::query()->find(5)->delete();

        $response = $this->send($this->request('GET', '/api/notifications', ['authenticatedAs' => 3]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $json = json_decode((string) $response->getBody(), true);
        $types = array_column(array_column($json['data'], 'attributes'), 'contentType');
        $this->assertContains('communityGoalReached', $types);
        $this->assertSame('community-goals', $json['data'][0]['relationships']['subject']['data']['type']);
    }

    #[Test]
    public function a_forum_closed_to_guests_shows_them_no_goals(): void
    {
        $this->extension('ernestdefoe-community-goals');
        $this->create([]);
        $this->database()->table('group_permission')->where('permission', 'viewForum')->where('group_id', Group::GUEST_ID)->delete();

        $this->assertSame(403, $this->api('GET', '', null)[0]);
        $this->assertSame(403, $this->api('GET', '/board', null)[0]);
        $forum = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true);
        $this->assertArrayNotHasKey('communityGoals', $forum['data']['attributes']);
        $this->assertFalse($forum['data']['attributes']['communityGoalsCanView']);

        $this->assertSame(200, $this->api('GET', '', 3)[0]);
    }

    #[Test]
    public function moving_a_discussion_into_a_staff_tag_takes_its_replies_off(): void
    {
        $this->extension('flarum-tags', 'ernestdefoe-community-goals');
        $this->prepareDatabase([
            'tags' => [
                ['id' => 1, 'name' => 'General', 'slug' => 'general', 'position' => 0, 'is_restricted' => false],
                ['id' => 2, 'name' => 'Staff', 'slug' => 'staff', 'position' => 1, 'is_restricted' => true],
            ],
            'discussion_tag' => [['discussion_id' => 1, 'tag_id' => 1]],
        ]);
        $id = $this->create([]);
        $this->reply(3);
        $this->assertSame(1, Goal::query()->find($id)->progress);

        $response = $this->send($this->request('PATCH', '/api/discussions/1', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'discussions', 'id' => '1', 'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '2']]]]]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(0, Goal::query()->find($id)->progress);
    }

    #[Test]
    public function only_admins_set_rewards(): void
    {
        $this->extension('ernestdefoe-community-goals');
        $id = $this->create(['rewards' => ['announce' => ['title' => 'Mine', 'body' => '']]], 4);
        $this->assertNull(Goal::query()->find($id)->rewards);
        $this->assertNull($this->api('GET', '/options', 4)[1]['rewards']);

        $id = $this->create(['rewards' => ['announce' => ['title' => 'Ours', 'body' => '']]], 1);
        $this->assertSame('Ours', Goal::query()->find($id)->rewards['announce']['title']);
    }

    #[Test]
    public function a_new_members_announcement_names_nobody(): void
    {
        $this->extension('ernestdefoe-community-goals');
        $this->app();
        $goal = new Goal();
        $goal->forceFill(['user_id' => 1, 'title' => 'Welcome wagon', 'metric' => 'members', 'target' => 1, 'progress' => 1, 'contributors_count' => 1, 'starts_at' => Carbon::now()->subDay(), 'ends_at' => Carbon::now()->addDay()]);
        $goal->save();
        $this->database()->table('community_goal_contributors')->insert(['goal_id' => $goal->id, 'user_id' => 3, 'count' => 1]);

        $done = $this->app()->getContainer()->make(Announcement::class)->apply($goal->fresh(), ['tag' => null, 'title' => '', 'body' => '']);
        $content = (string) Post::query()->where('discussion_id', $done['discussion'])->value('content');
        $this->assertStringNotContainsString('dana', $content);
        $this->assertStringNotContainsString('Top contributors', $content);
    }

    #[Test]
    public function titles_keep_what_was_typed(): void
    {
        $this->extension('ernestdefoe-community-goals');
        $id = $this->create(['title' => '<3 replies for Sam']);
        $this->assertSame('<3 replies for Sam', Goal::query()->find($id)->title);
    }
}
