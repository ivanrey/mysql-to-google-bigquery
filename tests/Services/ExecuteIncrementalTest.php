<?php
namespace MysqlToGoogleBigQuery\Tests\Services;

use Doctrine\DBAL\Schema\Column;
use MysqlToGoogleBigQuery\Database\BigQuery;
use MysqlToGoogleBigQuery\Database\Mysql;
use MysqlToGoogleBigQuery\Services\SyncService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class ExecuteIncrementalTest extends TestCase
{
    private BigQuery&MockObject $bigQuery;
    private Mysql&MockObject $mysql;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->bigQuery = $this->createMock(BigQuery::class);
        $this->mysql = $this->createMock(Mysql::class);
        $this->output = new BufferedOutput();
    }

    private function service(): SyncService&MockObject
    {
        return $this->getMockBuilder(SyncService::class)
            ->setConstructorArgs([$this->bigQuery, $this->mysql])
            ->onlyMethods(['sendBatch', 'sendBatchUnbuffered', 'createTable'])
            ->getMock();
    }

    /**
     * Schema mock: array of Column doubles keyed by (lowercase) column name.
     */
    private function schemaWithColumns(string ...$names): array
    {
        $columns = [];
        foreach ($names as $name) {
            $columns[$name] = $this->createMock(Column::class);
        }

        return $columns;
    }

    private function execute(
        SyncService $service,
        ?string $orderColumn = 'id',
        array $ignoreColumns = [],
        bool $deleteTable = false,
        bool $unbuffered = false
    ): void {
        $service->execute(
            'mydb',
            'users',
            'users',
            false,
            $deleteTable,
            $orderColumn,
            $ignoreColumns,
            $this->output,
            false,
            $unbuffered
        );
    }

    public function testIncrementalFailsEarlyWhenCreatedAtColumnIsMissing(): void
    {
        $service = $this->service();

        $this->bigQuery->method('tableExists')->willReturn(true);
        $this->mysql->method('getTableColumns')
            ->willReturn($this->schemaWithColumns('id', 'name'));

        // Fail before running any BigQuery query with the broken filter
        $this->bigQuery->expects($this->never())->method('getMaxColumnValue');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("has no 'created_at' column");

        $this->execute($service);
    }

    public function testIncrementalFailsEarlyWhenCreatedAtIsIgnored(): void
    {
        $service = $this->service();

        $this->bigQuery->method('tableExists')->willReturn(true);

        // Ignored column check runs even before introspecting MySQL
        $this->mysql->expects($this->never())->method('getTableColumns');
        $this->bigQuery->expects($this->never())->method('getMaxColumnValue');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('--ignore-column');

        $this->execute($service, ignoreColumns: ['created_at']);
    }

    public function testIncrementalProceedsWhenCreatedAtExists(): void
    {
        $service = $this->service();

        $this->bigQuery->method('tableExists')->willReturn(true);
        $this->mysql->method('getTableColumns')
            ->willReturn($this->schemaWithColumns('id', 'name', 'created_at'));

        // Same max on both sides -> already synced, clean early return
        $this->mysql->method('getMaxColumnValue')->willReturn('100');
        $this->bigQuery->expects($this->once())->method('getMaxColumnValue')->willReturn('100');

        $this->execute($service);

        $this->assertStringContainsString('Already synced', $this->output->fetch());
    }

    public function testUnbufferedDoesNotRequireCreatedAt(): void
    {
        $service = $this->service();

        $this->bigQuery->method('tableExists')->willReturn(true);
        $this->bigQuery->expects($this->never())->method('getMaxColumnValue');
        $service->expects($this->once())->method('sendBatchUnbuffered');

        // Table without created_at, but full dump doesn't use the time filter
        $this->execute($service, orderColumn: null, deleteTable: true, unbuffered: true);
    }

    public function testNonIncrementalDoesNotRequireCreatedAt(): void
    {
        $service = $this->service();

        $this->bigQuery->method('tableExists')->willReturn(true);
        $this->bigQuery->method('getCountTableRows')->willReturn(0);
        $this->mysql->method('getCountTableRows')->willReturn(0);

        // No order column -> count-based path, created_at never checked
        $this->mysql->expects($this->never())->method('getTableColumns');

        $this->execute($service, orderColumn: null);

        $this->assertStringContainsString('Already synced', $this->output->fetch());
    }
}
