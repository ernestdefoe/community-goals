<?php

namespace ErnestDefoe\CommunityGoals\Tests\unit;

use ErnestDefoe\CommunityGoals\Listener\CountActivity;
use ErnestDefoe\CommunityGoals\Tally;
use Flarum\User\Event\Activated;
use Flarum\User\User;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/** When a count runs relative to the transaction that saved what it counts. */
class CountActivityTest extends TestCase
{
    private SQLiteConnection $db;
    private Dispatcher $events;
    /** @var list<int> */
    private array $counted = [];
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->db = new SQLiteConnection(new \PDO('sqlite::memory:'), '', '', ['name' => 'flarum']);
        $tally = $this->createStub(Tally::class);
        $tally->method('member')->willReturnCallback(function (int $id) {
            if ($id === 13) {
                throw new \RuntimeException('Deadlock found');
            }
            $this->counted[] = $id;
        });
        $log = new class($this->logged) extends AbstractLogger {
            public function __construct(private array &$lines)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };
        $this->events = new Dispatcher();
        (new CountActivity($tally, $this->db, $log))->subscribe($this->events);
    }

    private function activate(int $id): void
    {
        $user = new User();
        $user->id = $id;
        $this->events->dispatch(new Activated($user));
    }

    public function test_it_waits_for_the_commit_and_is_dropped_with_a_rollback(): void
    {
        $this->db->setTransactionManager(new DatabaseTransactionsManager());

        $this->db->beginTransaction();
        $this->activate(2);
        $this->assertSame([], $this->counted, 'counted inside the transaction');
        $this->db->commit();
        $this->assertSame([2], $this->counted);

        $this->db->beginTransaction();
        $this->activate(3);
        $this->db->rollBack();
        $this->assertSame([2], $this->counted, 'counted a save that was rolled back');

        $this->activate(4);
        $this->assertSame([2, 4], $this->counted, 'outside a transaction it counts at once');
    }

    public function test_without_a_transactions_manager_it_counts_at_once_instead_of_throwing(): void
    {
        $this->db->unsetTransactionManager();

        $this->db->beginTransaction();
        $this->activate(2);
        $this->activate(13);
        $this->db->commit();

        $this->assertSame([2], $this->counted);
        $this->assertSame(['[community-goals] counting failed: Deadlock found'], $this->logged);
    }

    public function test_a_failing_count_is_logged_never_thrown(): void
    {
        $this->db->setTransactionManager(new DatabaseTransactionsManager());

        $this->db->beginTransaction();
        $this->activate(13);
        $this->db->commit();

        $this->assertSame([], $this->counted);
        $this->assertSame(['[community-goals] counting failed: Deadlock found'], $this->logged);
    }
}
