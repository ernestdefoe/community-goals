<?php

namespace ErnestDefoe\CommunityGoals\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Goals as a JSON:API resource: what a guest or plain member can reach
 * (directly, by id, or through include=), and that a page costs the same
 * number of queries for two goals or twenty.
 */
class ResourceTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use CallsGoalsApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('flarum-tags', 'ernestdefoe-community-goals');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'dana', 'email' => 'dana@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'staffer', 'email' => 'staff@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [['user_id' => 4, 'group_id' => Group::MODERATOR_ID]],
            'tags' => [
                ['id' => 1, 'name' => 'General', 'slug' => 'general', 'position' => 0, 'is_restricted' => false],
                ['id' => 2, 'name' => 'Staff Room', 'slug' => 'staff', 'position' => 1, 'is_restricted' => true],
            ],
            'group_permission' => [
                ['permission' => 'tag2.viewForum', 'group_id' => Group::MODERATOR_ID],
            ],
        ]);
    }

    /** @param array<string, mixed> $row */
    private function insertGoal(array $row): int
    {
        return $this->database()->table('community_goals')->insertGetId($row + [
            'title' => 'Reply Rally',
            'metric' => 'replies',
            'target' => 100,
            'progress' => 3,
            'reward' => 'A party',
            'rewards' => json_encode(['announce' => ['title' => 'Secret plan', 'body' => '']]),
            'starts_at' => Carbon::now()->subDay(),
            'ends_at' => Carbon::now()->addDays(6),
            'contributors_count' => 2,
            'user_id' => 1,
        ]);
    }

    #[Test]
    public function a_goal_on_a_hidden_tag_never_reaches_a_guest_or_member(): void
    {
        $public = $this->insertGoal([]);
        $hidden = $this->insertGoal(['title' => 'Staff Room push', 'metric' => 'tag_posts', 'tag_id' => 2]);
        $this->database()->table('community_goal_contributors')->insert([
            ['goal_id' => $public, 'user_id' => 2, 'count' => 2],
            ['goal_id' => $hidden, 'user_id' => 4, 'count' => 5],
        ]);

        foreach ([null, 2] as $as) {
            [$status, $list] = $this->api('GET', '?filter[when]=open&include=tag,helpers', $as);
            $this->assertSame(200, $status, json_encode($list));
            $this->assertSame([(string) $public], array_column($list['data'], 'id'));
            $body = json_encode($list);
            $this->assertStringNotContainsString('Staff Room', $body);
            $this->assertStringNotContainsString('Secret plan', $body, 'Reward settings are for managers.');
            foreach (['rewards', 'rewardState', 'tagId', 'countedAt', 'complete'] as $field) {
                $this->assertArrayNotHasKey($field, $list['data'][0]['attributes']);
            }

            $this->assertSame(404, $this->api('GET', "/$hidden?include=tag,helpers", $as)[0]);
            [, $show] = $this->api('GET', "/$public?include=tag,helpers", $as);
            $this->assertStringNotContainsString('Secret plan', json_encode($show));
        }

        // Someone who can see the tag sees the goal, its tag and its helpers.
        [, $list] = $this->api('GET', '?filter[when]=open&include=tag,helpers', 4);
        $this->assertContains((string) $hidden, array_column($list['data'], 'id'));
        $this->assertContains('Staff Room', array_column(array_column($list['included'], 'attributes'), 'name'));
        // Managers (here the admin) get the form's fields.
        [, $show] = $this->api('GET', "/$public", 1);
        $this->assertSame('Secret plan', $show['goal']['rewards']['announce']['title']);
    }

    #[Test]
    public function helpers_come_as_an_include_and_only_those_the_viewer_may_see(): void
    {
        $goal = $this->insertGoal([]);
        $this->database()->table('community_goal_contributors')->insert([
            ['goal_id' => $goal, 'user_id' => 2, 'count' => 5],
            ['goal_id' => $goal, 'user_id' => 3, 'count' => 2],
        ]);

        [$status, $show] = $this->api('GET', "/$goal?include=helpers", 3);
        $this->assertSame(200, $status, json_encode($show));
        $this->assertSame([[2, 5], [3, 2]], $show['goal']['top']);
        $this->assertSame(2, $show['goal']['mine']);
        $this->assertSame(2, $show['goal']['rank']);
        $this->assertSame(['2', '3'], array_column($show['document']['data']['relationships']['helpers']['data'], 'id'));
        $this->assertEqualsCanonicalizing(['normal', 'dana'], array_column(array_column($show['document']['included'], 'attributes'), 'username'));
    }

    #[Test]
    public function a_page_costs_the_same_queries_for_two_goals_or_twenty(): void
    {
        $measure = function (?int $as): int {
            // Warm first: the first request of a test pays one-off queries (settings, permissions).
            $this->api('GET', '?filter[when]=open&include=tag,helpers&page[limit]=20', $as);
            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            [$status] = $this->api('GET', '?filter[when]=open&include=tag,helpers&page[limit]=20', $as);
            $db->disableQueryLog();
            $this->assertSame(200, $status);

            return count($db->getQueryLog());
        };
        $add = function (int $n) {
            for ($i = 0; $i < $n; $i++) {
                $id = $this->insertGoal($i % 2 ? ['metric' => 'tag_posts', 'tag_id' => 1] : []);
                $this->database()->table('community_goal_contributors')->insert([
                    ['goal_id' => $id, 'user_id' => 2, 'count' => 3],
                    ['goal_id' => $id, 'user_id' => 3, 'count' => 1],
                ]);
            }
        };

        // One of each kind first (a tag goal brings its tag include), then eighteen more.
        $add(2);
        $two = [$measure(null), $measure(3), $measure(1)];
        $add(18);
        $twenty = [$measure(null), $measure(3), $measure(1)];

        $this->assertSame($two, $twenty, 'guest, member and admin: queries for 2 goals vs 20');
    }
}
