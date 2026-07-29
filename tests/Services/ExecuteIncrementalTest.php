<?php
namespace MysqlToGoogleBigQuery\Tests\Services;

class ExecuteIncrementalTest extends SyncServiceTestCase
{
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

        $this->execute($service, orderColumn: 'id');
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

        $this->execute($service, orderColumn: 'id', ignoreColumns: ['created_at']);
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

        $this->execute($service, orderColumn: 'id');

        $this->assertStringContainsString('Already synced', $this->output->fetch());
    }

    public function testCreatedAtIsValidatedBeforeDeletingTheBigQueryTable(): void
    {
        $service = $this->service();

        $this->bigQuery->method('tableExists')->willReturn(true);
        $this->mysql->method('getTableColumns')
            ->willReturn($this->schemaWithColumns('id', 'name'));

        // The guard must run before the destructive step, otherwise the table
        // is dropped and only then the sync refuses to run
        $this->bigQuery->expects($this->never())->method('deleteTable');
        $service->expects($this->never())->method('createTable');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("has no 'created_at' column");

        $this->execute($service, orderColumn: 'id', deleteTable: true);
    }

    public function testIncrementalAbortsWhenTheLookbackWindowIsEmptyButTheTableHasRows(): void
    {
        $service = $this->service();

        $this->bigQuery->method('tableExists')->willReturn(true);
        $this->mysql->method('getTableColumns')
            ->willReturn($this->schemaWithColumns('id', 'created_at'));

        // No rows inside the window -> filtered MAX is NULL, but the table is
        // populated: syncing would re-insert everything on top of it
        $this->mysql->method('getMaxColumnValue')->willReturn('100');
        $this->bigQuery->method('getMaxColumnValue')->willReturn(false);
        $this->bigQuery->method('getCountTableRows')->willReturn(1234);
        $this->bigQuery->method('getCreatedAtLookback')->willReturn('-3 month');

        $service->expects($this->never())->method('sendBatch');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('duplicate');

        $this->execute($service, orderColumn: 'id');
    }

    public function testIncrementalSyncsFromScratchWhenTheTableIsReallyEmpty(): void
    {
        $service = $this->service();

        $this->bigQuery->method('tableExists')->willReturn(true);
        $this->mysql->method('getTableColumns')
            ->willReturn($this->schemaWithColumns('id', 'created_at'));

        $this->mysql->method('getMaxColumnValue')->willReturn('100');
        $this->bigQuery->method('getMaxColumnValue')->willReturn(false);
        $this->bigQuery->method('getCountTableRows')->willReturn(0);
        $this->mysql->method('getCountTableRows')->willReturn(10);

        // Empty destination: the full dump is the correct behaviour here
        $service->expects($this->once())->method('sendBatch');

        $this->execute($service, orderColumn: 'id');

        $this->assertStringContainsString('Syncing 10 rows', $this->output->fetch());
    }

    public function testIncrementalAbortsWhenTheDedupDeleteEmptiesTheWindow(): void
    {
        $service = $this->service();

        $this->bigQuery->method('tableExists')->willReturn(true);
        $this->mysql->method('getTableColumns')
            ->willReturn($this->schemaWithColumns('id', 'created_at'));

        $this->mysql->method('getMaxColumnValue')->willReturn('200');
        // The window held a single order value: after cleaning it the MAX is
        // NULL again, yet the rows outside the window are still there
        $this->bigQuery->method('getMaxColumnValue')
            ->willReturnOnConsecutiveCalls('100', false);
        $this->bigQuery->method('getCountTableRows')->willReturn(500);
        $this->bigQuery->method('getCreatedAtLookback')->willReturn('-3 month');

        $this->bigQuery->expects($this->once())->method('deleteColumnValue');
        $service->expects($this->never())->method('sendBatch');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('duplicate');

        $this->execute($service, orderColumn: 'id');
    }

    public function testUnbufferedDoesNotRequireCreatedAt(): void
    {
        $service = $this->service();

        $this->bigQuery->method('tableExists')->willReturn(true);
        $this->bigQuery->expects($this->never())->method('getMaxColumnValue');
        $service->expects($this->once())->method('sendBatchUnbuffered');

        // Table without created_at, but full dump doesn't use the time filter
        $this->execute($service, deleteTable: true, unbuffered: true);
    }

    public function testNonIncrementalDoesNotRequireCreatedAt(): void
    {
        $service = $this->service();

        $this->bigQuery->method('tableExists')->willReturn(true);
        $this->bigQuery->method('getCountTableRows')->willReturn(0);
        $this->mysql->method('getCountTableRows')->willReturn(0);

        // No order column -> count-based path, created_at never checked
        $this->mysql->expects($this->never())->method('getTableColumns');

        $this->execute($service);

        $this->assertStringContainsString('Already synced', $this->output->fetch());
    }
}
