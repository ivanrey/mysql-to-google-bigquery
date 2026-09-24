<?php
namespace MysqlToGoogleBigQuery\Tests\Services;

use MysqlToGoogleBigQuery\Services\SyncService;
use PHPUnit\Framework\MockObject\MockObject;

class ExecutePartitioningTest extends SyncServiceTestCase
{
    /**
     * Real createTable(), on doubled BigQuery/Mysql: the table is missing,
     * so execute() creates it (--no-data keeps the run schema-only)
     */
    private function creatingService(): SyncService&MockObject
    {
        $this->bigQuery->method('tableExists')->willReturn(false);
        $this->mysql->method('getTableColumns')
            ->willReturn($this->schemaWithColumns('id', 'created_at'));

        return $this->service(['sendBatch', 'sendBatchUnbuffered']);
    }

    public function testTableIsCreatedPartitionedByCreatedAt(): void
    {
        $service = $this->creatingService();

        $this->bigQuery->method('getPartitionType')->willReturn('MONTH');
        $this->bigQuery->method('getPartitionColumn')->willReturn('created_at');

        $this->bigQuery->expects($this->once())
            ->method('createTable')
            ->with('users', $this->anything(), 'MONTH');

        $this->execute($service, createTable: true, noData: true);

        $this->assertStringContainsString('Partitioning by "created_at" (MONTH)', $this->output->fetch());
    }

    public function testIgnoredColumnsReachThePartitionColumnCheck(): void
    {
        $service = $this->creatingService();

        $this->bigQuery->method('getPartitionType')->willReturn('DAY');

        // created_at in IGNORE_COLUMNS would be all NULLs: BigQuery decides
        // with the ignored list at hand
        $this->bigQuery->expects($this->once())
            ->method('getPartitionColumn')
            ->with($this->anything(), ['password'])
            ->willReturn('created_at');

        $this->execute($service, ignoreColumns: ['password'], createTable: true, noData: true);
    }

    public function testTableWithoutUsableCreatedAtIsCreatedUnpartitioned(): void
    {
        $service = $this->creatingService();

        $this->bigQuery->method('getPartitionType')->willReturn('MONTH');
        $this->bigQuery->method('getPartitionColumn')->willReturn(null);

        // Not an error: the table is still created, just without partitioning
        $this->bigQuery->expects($this->once())
            ->method('createTable')
            ->with('users', $this->anything(), null);

        $this->execute($service, createTable: true, noData: true);

        $this->assertStringContainsString('created without partitioning', $this->output->fetch());
    }

    public function testPartitionTypeNoneCreatesAnUnpartitionedTable(): void
    {
        $service = $this->creatingService();

        // NONE resolves to null: no column check, no warning
        $this->bigQuery->method('getPartitionType')->willReturn(null);
        $this->bigQuery->expects($this->never())->method('getPartitionColumn');
        $this->bigQuery->expects($this->once())
            ->method('createTable')
            ->with('users', $this->anything(), null);

        $this->execute($service, createTable: true, noData: true);

        $this->assertStringNotContainsString('partitioning', $this->output->fetch());
    }

    public function testCommandLineValueReachesTheResolver(): void
    {
        $service = $this->creatingService();

        $this->bigQuery->expects($this->once())
            ->method('getPartitionType')
            ->with('users', 'day')
            ->willReturn('DAY');

        $this->execute($service, createTable: true, noData: true, partitionType: 'day');
    }

    public function testInvalidPartitionTypeFailsBeforeDeletingTheTable(): void
    {
        $service = $this->service();

        $this->bigQuery->method('tableExists')->willReturn(true);
        $this->bigQuery->method('getPartitionType')
            ->willThrowException(new \InvalidArgumentException('Invalid partition type "WEEK"'));

        // A typo must not cost the table its data
        $this->bigQuery->expects($this->never())->method('deleteTable');
        $service->expects($this->never())->method('createTable');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid partition type');

        $this->execute($service, deleteTable: true, partitionType: 'WEEK');
    }

    public function testPartitionOptionOnAnExistingTableWarnsItIsNotApplied(): void
    {
        $service = $this->service();

        $this->bigQuery->method('tableExists')->willReturn(true);
        $this->bigQuery->method('getPartitionType')->willReturn('DAY');
        $service->expects($this->never())->method('createTable');

        $this->execute($service, noData: true, partitionType: 'DAY');

        $this->assertStringContainsString(
            '--partition-type only applies when the table is created',
            $this->output->fetch()
        );
    }

    public function testExistingTableWithoutPartitionOptionDoesNotWarn(): void
    {
        $service = $this->service();

        // PARTITION_TYPE from the .env applies to every run: warning on each
        // cron run of an existing table would only be noise
        $this->bigQuery->method('tableExists')->willReturn(true);
        $this->bigQuery->method('getPartitionType')->willReturn('MONTH');

        $this->execute($service, noData: true);

        $this->assertStringNotContainsString('--partition-type', $this->output->fetch());
    }
}
