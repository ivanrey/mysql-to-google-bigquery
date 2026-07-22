<?php
namespace MysqlToGoogleBigQuery\Tests\Services;

use MysqlToGoogleBigQuery\Database\BigQuery;
use MysqlToGoogleBigQuery\Database\Mysql;
use MysqlToGoogleBigQuery\Services\SyncService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class ExecuteUnbufferedTest extends TestCase
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

    /**
     * Partial mock: the real execute() runs, but the batch senders and the
     * table creation (which hit MySQL/BigQuery) are stubbed out.
     */
    private function service(): SyncService&MockObject
    {
        return $this->getMockBuilder(SyncService::class)
            ->setConstructorArgs([$this->bigQuery, $this->mysql])
            ->onlyMethods(['sendBatch', 'sendBatchUnbuffered', 'createTable'])
            ->getMock();
    }

    private function execute(
        SyncService $service,
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
            null,
            [],
            $this->output,
            $noData,
            $unbuffered
        );
    }

    public function testUnbufferedWithoutDeleteTableFailsWithClearError(): void
    {
        $service = $this->service();

        // Guard runs before touching BigQuery at all
        $this->bigQuery->expects($this->never())->method('tableExists');
        $service->expects($this->never())->method('sendBatchUnbuffered');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--delete-table');

        $this->execute($service, unbuffered: true);
    }

    public function testUnbufferedDoesNotPhysicallyDeleteTheTable(): void
    {
        $service = $this->service();

        $this->bigQuery->method('tableExists')->willReturn(true);

        // Atomic WRITE_TRUNCATE replaces the data: no physical delete/recreate
        $this->bigQuery->expects($this->never())->method('deleteTable');
        $service->expects($this->never())->method('createTable');
        $service->expects($this->once())->method('sendBatchUnbuffered');

        $this->execute($service, deleteTable: true, unbuffered: true);
    }

    public function testUnbufferedWarnsThatSchemaIsKept(): void
    {
        $service = $this->service();
        $this->bigQuery->method('tableExists')->willReturn(true);

        $this->execute($service, deleteTable: true, unbuffered: true);

        $display = $this->output->fetch();
        $this->assertStringContainsString('Warning', $display);
        $this->assertStringContainsString('NOT recreated', $display);
        $this->assertStringContainsString('schema is kept', $display);
    }

    public function testUnbufferedCreatesTableWhenMissing(): void
    {
        $service = $this->service();

        $this->bigQuery->method('tableExists')->willReturn(false);
        $this->bigQuery->expects($this->never())->method('deleteTable');

        $service->expects($this->once())->method('createTable');
        $service->expects($this->once())->method('sendBatchUnbuffered');

        $this->execute($service, deleteTable: true, unbuffered: true);
    }

    public function testBufferedDeleteTableStillPhysicallyDeletes(): void
    {
        $service = $this->service();

        $this->bigQuery->method('tableExists')
            ->willReturnOnConsecutiveCalls(true, false);
        $this->bigQuery->method('getCountTableRows')->willReturn(0);
        $this->mysql->method('getCountTableRows')->willReturn(0);

        // Regression: without --un-buffer the old delete+recreate flow stays
        $this->bigQuery->expects($this->once())->method('deleteTable');
        $service->expects($this->once())->method('createTable');

        $this->execute($service, deleteTable: true);
    }

    public function testNoDataReturnsWithoutSyncing(): void
    {
        $service = $this->service();
        $this->bigQuery->method('tableExists')->willReturn(true);

        // Must return (not exit) and never reach any batch sender
        $service->expects($this->never())->method('sendBatch');
        $service->expects($this->never())->method('sendBatchUnbuffered');
        $this->bigQuery->expects($this->never())->method('getCountTableRows');

        $this->execute($service, noData: true);

        $this->assertStringContainsString('No data specified', $this->output->fetch());
    }

    public function testUnbufferedSkipsRowCountQueries(): void
    {
        $service = $this->service();
        $this->bigQuery->method('tableExists')->willReturn(true);

        // Full dump doesn't diff row counts: no metadata queries at all
        $this->bigQuery->expects($this->never())->method('getCountTableRows');
        $this->mysql->expects($this->never())->method('getCountTableRows');

        $this->execute($service, deleteTable: true, unbuffered: true);
    }
}
