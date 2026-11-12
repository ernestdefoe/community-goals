<?php

namespace ErnestDefoe\CommunityGoals\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\CommunityGoals\Tally;
use Flarum\Discussion\Discussion;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Flarum\Group\Group;
use Flarum\Post\Event\Saving;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Counting never runs inside the transaction that saves the thing being
 * counted (showcase, 2026-11-12: a queued job changed a goal while core was
 * saving a new discussion; MariaDB refused the count's locking read with
 * error 1020 and rolled back core's whole transaction, so the discussion 500'd).
 * The two concurrency tests need MySQL/MariaDB: SQLite has no row locks.
 *
 * The harness runs each test in a transaction it rolls back, which would make
 * core's save a nested one that never commits (and hide the goal from other
 * connections). So the goal is committed and the rest runs as in production;
 * tearDown removes what was written.
 */
class ConcurrencyTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('ernestdefoe-community-goals');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            'group_permission' => [
                ['permission' => 'postWithoutThrottle', 'group_id' => Group::MEMBER_ID],
            ],
        ]);
    }

    private function goal(): int
    {
        $response = $this->send($this->request('POST', '/api/community-goals', [
            'authenticatedAs' => 1,
            'json' => [
                'title' => 'Discussion Drive',
                'metric' => 'discussions',
                'target' => 10,
                'startsAt' => Carbon::now()->subDay()->toIso8601String(),
                'endsAt' => Carbon::now()->addDays(6)->toIso8601String(),
            ],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $id = json_decode((string) $response->getBody(), true)['goal']['id'];
        $this->database()->commit();
        $this->committed = true;
        // The harness runs after-commit callbacks at once (its wrapper never commits); from here on
        // transactions really commit, so they wait for the commit as they do in production.
        $manager = new DatabaseTransactionsManager();
        $this->app()->getContainer()->instance('db.transactions', $manager);
        $this->database()->setTransactionManager($manager);

        return $id;
    }

    private bool $committed = false;

    protected function tearDown(): void
    {
        if ($this->committed) {
            $db = $this->database();
            foreach (['community_goal_entries', 'community_goal_contributors', 'community_goals', 'posts', 'discussion_user', 'discussions', 'access_tokens'] as $table) {
                $db->table($table)->delete();
            }
            $db->table('users')->where('id', '>', 1)->delete();
            $db->table('group_permission')->where('permission', 'postWithoutThrottle')->where('group_id', Group::MEMBER_ID)->delete();
            $db->beginTransaction();
        }
        parent::tearDown();
    }

    private function startDiscussion(): void
    {
        $response = $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'discussions', 'attributes' => ['title' => 'Hello there', 'content' => 'A new discussion']]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(1, Discussion::query()->where('title', 'Hello there')->count());
    }

    private function progress(int $goal): int
    {
        return (int) $this->database()->table('community_goals')->where('id', $goal)->value('progress');
    }

    private function needsRowLocks(): void
    {
        if (! in_array($this->database()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs MySQL or MariaDB (row locks).');
        }
    }

    /** A second connection, as a queued job would have: [dsn, user, password, table]. */
    private function otherConnection(): array
    {
        $config = $this->database()->getConfig();

        return [
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'],
            $config['password'],
            $this->database()->getTablePrefix().'community_goals',
        ];
    }

    #[Test]
    public function a_job_changing_the_goal_while_core_saves_a_discussion_breaks_neither(): void
    {
        $this->needsRowLocks();
        $goal = $this->goal();
        [$dsn, $user, $password, $table] = $this->otherConnection();

        // Inside core's save transaction: read (MariaDB takes the snapshot), then a
        // job on another connection changes the goal and commits.
        $db = $this->database();
        $this->app()->getContainer()->make(Dispatcher::class)->listen(Saving::class, function () use ($db, $goal, $dsn, $user, $password, $table) {
            static $done = false;
            if ($done) {
                return;
            }
            $done = true;
            $db->table('community_goals')->where('id', $goal)->value('progress');
            (new \PDO($dsn, $user, $password))->exec("UPDATE `$table` SET title = 'Discussion Drive!' WHERE id = $goal");
        });

        $this->startDiscussion();
        $this->assertSame(1, $this->progress($goal));
    }

    #[Test]
    public function a_save_waits_out_a_job_holding_the_goal_and_the_count_catches_up(): void
    {
        $this->needsRowLocks();
        $goal = $this->goal();
        [$dsn, $user, $password, $table] = $this->otherConnection();

        // A recount on another process: locks the goal, sets its number, holds it 2 s.
        $script = '$p = new PDO($argv[1], $argv[2], $argv[3]); $p->beginTransaction();'
            .' $p->query("SELECT id FROM `$argv[4]` WHERE id = $argv[5] FOR UPDATE");'
            .' $p->exec("UPDATE `$argv[4]` SET progress = 5 WHERE id = $argv[5]");'
            .' echo "locked\n"; sleep(2); $p->commit();';
        $job = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script, $dsn, $user, $password, $table, (string) $goal], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertSame('locked', trim((string) fgets($pipes[1])), (string) stream_get_contents($pipes[2]));

        $this->startDiscussion();
        proc_close($job);
        $this->assertSame(6, $this->progress($goal));
    }

    #[Test]
    public function a_counting_failure_never_breaks_the_save(): void
    {
        $this->extend(new class implements ExtenderInterface {
            public function extend(Container $container, ?Extension $extension = null): void
            {
                $container->bind(Tally::class, DeadlockingTally::class);
            }
        });
        $goal = $this->goal();

        $this->startDiscussion();
        $this->assertSame(0, $this->progress($goal));
    }
}

/** Fails like a deadlock does: the database rolls back the whole transaction it ran in. */
class DeadlockingTally extends Tally
{
    public function sync(array $goals, string $scope, int $id, bool $gone = false): void
    {
        $pdo = resolve(\Illuminate\Database\ConnectionInterface::class)->getPdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw new QueryException('flarum', 'select 1', [], new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'));
    }
}
