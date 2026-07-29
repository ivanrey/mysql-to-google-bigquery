<?php
namespace MysqlToGoogleBigQuery\Tests\Services;

use Doctrine\DBAL\Schema\Column;
use MysqlToGoogleBigQuery\Database\BigQuery;
use MysqlToGoogleBigQuery\Database\Mysql;
use MysqlToGoogleBigQuery\Services\SyncService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Shared wiring for the tests that drive SyncService::execute().
 *
 * Keeping the doubles and the execute() wrapper here means a change in the
 * signature of execute(), or in the set of stubbed methods, is a one-file
 * edit instead of the same edit repeated per test class.
 */
abstract class SyncServiceTestCase extends TestCase
{
    protected BigQuery&MockObject $bigQuery;
    protected Mysql&MockObject $mysql;
    protected BufferedOutput $output;

    protected function setUp(): void
    {
        $this->bigQuery = $this->createMock(BigQuery::class);
        $this->mysql = $this->createMock(Mysql::class);
        $this->output = new BufferedOutput();
    }

    /**
     * Partial mock: the real execute() runs, but the batch senders and the
     * table creation (which hit MySQL/BigQuery) are stubbed out.
     */
    protected function service(): SyncService&MockObject
    {
        return $this->getMockBuilder(SyncService::class)
            ->setConstructorArgs([$this->bigQuery, $this->mysql])
            ->onlyMethods(['sendBatch', 'sendBatchUnbuffered', 'createTable'])
            ->getMock();
    }

    /**
     * Run execute() on the "users" table of "mydb". Every knob defaults to
     * off, so each test only names what it is exercising.
     */
    protected function execute(
        SyncService $service,
        ?string $orderColumn = null,
        array $ignoreColumns = [],
        bool $createTable = false,
        bool $deleteTable = false,
        bool $noData = false,
        bool $unbuffered = false
    ): void {
        $service->execute(
            'mydb',
            'users',
            'users',
            $createTable,
            $deleteTable,
            $orderColumn,
            $ignoreColumns,
            $this->output,
            $noData,
            $unbuffered
        );
    }

    /**
     * Schema double: array of Column doubles keyed by (lowercase) column name.
     */
    protected function schemaWithColumns(string ...$names): array
    {
        $columns = [];
        foreach ($names as $name) {
            $columns[$name] = $this->createMock(Column::class);
        }

        return $columns;
    }
}
