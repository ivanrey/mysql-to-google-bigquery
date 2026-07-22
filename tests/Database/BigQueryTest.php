<?php
namespace MysqlToGoogleBigQuery\Tests\Database;

use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\BigQuery\Dataset;
use Google\Cloud\BigQuery\Job;
use Google\Cloud\BigQuery\LoadJobConfiguration;
use Google\Cloud\BigQuery\QueryJobConfiguration;
use Google\Cloud\BigQuery\QueryResults;
use Google\Cloud\BigQuery\Table;
use MysqlToGoogleBigQuery\Database\BigQuery;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BigQueryTest extends TestCase
{
    private BigQueryClient&MockObject $client;
    private BigQuery $bigQuery;

    protected function setUp(): void
    {
        $_ENV['BQ_DATASET'] = 'my_dataset';

        $this->client = $this->createMock(BigQueryClient::class);
        $this->bigQuery = new BigQuery($this->client);
    }

    protected function tearDown(): void
    {
        unset(
            $_ENV['BQ_DATASET'],
            $_ENV['CREATED_AT_LOOKBACK'],
            $_ENV['CREATED_AT_LOOKBACK_USERS'],
            $_ENV['CREATED_AT_LOOKBACK_USER_LOGS']
        );
    }

    /**
     * Stub client->query() to return a config mock, capturing the SQL
     */
    private function expectQuery(?string &$capturedSql): QueryJobConfiguration&MockObject
    {
        $config = $this->createMock(QueryJobConfiguration::class);
        $config->method('useQueryCache')->willReturnSelf();

        $this->client->method('query')
            ->willReturnCallback(function (string $sql) use (&$capturedSql, $config) {
                $capturedSql = $sql;
                return $config;
            });

        return $config;
    }

    private function queryResultsWithRows(array $rows): QueryResults&MockObject
    {
        $results = $this->createMock(QueryResults::class);
        $results->method('rows')->willReturn(new \ArrayIterator($rows));

        return $results;
    }

    public function testGetMaxColumnValueReturnsMaxFromFirstRow(): void
    {
        $this->expectQuery($sql);
        $this->client->method('runQuery')
            ->willReturn($this->queryResultsWithRows([['columnMax' => '2026-07-01 10:00:00']]));

        $result = $this->bigQuery->getMaxColumnValue('users', 'created_at');

        $this->assertSame('2026-07-01 10:00:00', $result);
        $this->assertStringContainsString('SELECT MAX(`created_at`) AS columnMax', $sql);
        $this->assertStringContainsString('FROM `my_dataset.users`', $sql);
    }

    public function testGetMaxColumnValueReturnsFalseOnEmptyResult(): void
    {
        $this->expectQuery($sql);
        $this->client->method('runQuery')->willReturn($this->queryResultsWithRows([]));

        $this->assertFalse($this->bigQuery->getMaxColumnValue('users', 'created_at'));
    }

    public function testGetMaxColumnValueHonorsCreatedAtLookback(): void
    {
        $_ENV['CREATED_AT_LOOKBACK'] = '-2 days';

        $this->expectQuery($sql);
        $this->client->method('runQuery')->willReturn($this->queryResultsWithRows([]));

        $this->bigQuery->getMaxColumnValue('users', 'created_at');

        $expectedDate = date('Y-m-d', strtotime('-2 days'));
        $this->assertStringContainsString("created_at >= '$expectedDate'", $sql);
    }

    public function testLookbackDefaultsToThreeMonths(): void
    {
        $this->assertSame('-3 month', $this->bigQuery->getCreatedAtLookback('users'));
    }

    public function testLookbackUsesGlobalEnvVariable(): void
    {
        $_ENV['CREATED_AT_LOOKBACK'] = '-15 days';

        $this->assertSame('-15 days', $this->bigQuery->getCreatedAtLookback('users'));
    }

    public function testPerTableLookbackWinsOverGlobal(): void
    {
        $_ENV['CREATED_AT_LOOKBACK'] = '-15 days';
        $_ENV['CREATED_AT_LOOKBACK_USERS'] = '-2 days';

        $this->assertSame('-2 days', $this->bigQuery->getCreatedAtLookback('users'));
        // Other tables still get the global value
        $this->assertSame('-15 days', $this->bigQuery->getCreatedAtLookback('orders'));
    }

    public function testPerTableLookbackNormalizesTableName(): void
    {
        // Lowercase and hyphens in the table name map to _ in the env var
        $_ENV['CREATED_AT_LOOKBACK_USER_LOGS'] = '-1 day';

        $this->assertSame('-1 day', $this->bigQuery->getCreatedAtLookback('user-logs'));
        $this->assertSame('-1 day', $this->bigQuery->getCreatedAtLookback('User_Logs'));
    }

    public function testInvalidLookbackFailsWithClearError(): void
    {
        $_ENV['CREATED_AT_LOOKBACK'] = 'not-a-date';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CREATED_AT_LOOKBACK');

        $this->bigQuery->getCreatedAtLookback('users');
    }

    public function testEmptyGlobalLookbackFallsBackToDefault(): void
    {
        // A bare `CREATED_AT_LOOKBACK=` line loads '' into $_ENV; it must not
        // abort the sync, it must degrade to the default
        $_ENV['CREATED_AT_LOOKBACK'] = '';

        $this->assertSame('-3 month', $this->bigQuery->getCreatedAtLookback('users'));
    }

    public function testWhitespaceOnlyLookbackFallsBackToDefault(): void
    {
        $_ENV['CREATED_AT_LOOKBACK'] = '   ';

        $this->assertSame('-3 month', $this->bigQuery->getCreatedAtLookback('users'));
    }

    public function testEmptyPerTableLookbackFallsBackToGlobal(): void
    {
        // A blank per-table override must not shadow a valid global value
        $_ENV['CREATED_AT_LOOKBACK_USERS'] = '';
        $_ENV['CREATED_AT_LOOKBACK'] = '-5 days';

        $this->assertSame('-5 days', $this->bigQuery->getCreatedAtLookback('users'));
    }

    public function testLookbackValueIsTrimmed(): void
    {
        $_ENV['CREATED_AT_LOOKBACK'] = '  -5 days  ';

        $this->assertSame('-5 days', $this->bigQuery->getCreatedAtLookback('users'));
    }

    public function testFutureLookbackIsRejected(): void
    {
        // '8 days' (missing the '-') resolves to a future date; left unchecked
        // it would match no rows and trigger a full re-dump -> duplicates
        $_ENV['CREATED_AT_LOOKBACK'] = '8 days';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('future');

        $this->bigQuery->getCreatedAtLookback('users');
    }

    public function testInvalidPerTableLookbackNamesTheOffendingVariable(): void
    {
        $_ENV['CREATED_AT_LOOKBACK_USERS'] = '???';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CREATED_AT_LOOKBACK_USERS');

        $this->bigQuery->getCreatedAtLookback('users');
    }

    public function testDeleteColumnValueHonorsCreatedAtLookback(): void
    {
        $_ENV['CREATED_AT_LOOKBACK'] = '-5 days';

        $this->expectQuery($sql);
        $this->client->method('runQuery')->willReturn($this->queryResultsWithRows([]));

        $this->bigQuery->deleteColumnValue('users', 'id', '42');

        // The delete must look back the same window as getMaxColumnValue,
        // otherwise duplicates outside its window would survive
        $expectedDate = date('Y-m-d', strtotime('-5 days'));
        $this->assertStringContainsString("created_at >= '$expectedDate'", $sql);
    }

    public function testDeleteColumnValueQuotesNonNumericValues(): void
    {
        $this->expectQuery($sql);
        $this->client->method('runQuery')->willReturn($this->queryResultsWithRows([]));

        $this->bigQuery->deleteColumnValue('users', 'updated_at', '2026-07-01');

        $this->assertStringContainsString('`updated_at` = "2026-07-01"', $sql);
        $this->assertStringContainsString('DELETE FROM `my_dataset.users`', $sql);
    }

    public function testDeleteColumnValueDoesNotQuoteNumericValues(): void
    {
        $this->expectQuery($sql);
        $this->client->method('runQuery')->willReturn($this->queryResultsWithRows([]));

        $this->bigQuery->deleteColumnValue('users', 'id', '42');

        $this->assertStringContainsString('`id` = 42', $sql);
        $this->assertStringNotContainsString('"42"', $sql);
    }

    public function testGetTablesMetadataUsesStandardSqlBackticksAndNoCache(): void
    {
        $config = $this->expectQuery($sql);

        // __TABLES__ must be wrapped in backticks (Standard SQL) and skip the cache
        $config->expects($this->once())
            ->method('useQueryCache')
            ->with(false)
            ->willReturnSelf();

        $this->client->method('runQuery')->willReturn($this->queryResultsWithRows([
            ['table_id' => 'users', 'row_count' => 10],
            ['table_id' => 'orders', 'row_count' => 20],
        ]));

        $metadata = $this->bigQuery->getTablesMetadata();

        $this->assertStringContainsString('FROM `my_dataset.__TABLES__`', $sql);
        $this->assertSame(10, $metadata['users']['row_count']);
        $this->assertSame(20, $metadata['orders']['row_count']);
    }

    public function testGetCountTableRowsReturnsFalseForUnknownTable(): void
    {
        $this->expectQuery($sql);
        $this->client->method('runQuery')->willReturn($this->queryResultsWithRows([]));

        $this->assertFalse($this->bigQuery->getCountTableRows('missing_table'));
    }

    public function testLoadFromJsonStartsAsyncLoadJobWithNewlineDelimitedJson(): void
    {
        $file = fopen('php://memory', 'r');

        $loadConfig = $this->createMock(LoadJobConfiguration::class);
        $loadConfig->expects($this->once())
            ->method('sourceFormat')
            ->with('NEWLINE_DELIMITED_JSON')
            ->willReturnSelf();

        $table = $this->createMock(Table::class);
        $table->expects($this->once())
            ->method('load')
            ->with($file)
            ->willReturn($loadConfig);

        $dataset = $this->createMock(Dataset::class);
        $dataset->method('table')->with('users')->willReturn($table);

        $this->client->method('dataset')->with('my_dataset')->willReturn($dataset);

        $job = $this->createMock(Job::class);

        // The job must be started async (startJob), not waited on (runJob)
        $this->client->expects($this->once())
            ->method('startJob')
            ->with($loadConfig)
            ->willReturn($job);

        $this->assertSame($job, $this->bigQuery->loadFromJson($file, 'users'));
    }

    public function testLoadFromJsonWithTruncateSetsWriteTruncateDisposition(): void
    {
        $file = fopen('php://memory', 'r');

        $loadConfig = $this->createMock(LoadJobConfiguration::class);
        $loadConfig->method('sourceFormat')->willReturnSelf();

        // Unbuffered full dump: data replaced atomically on job commit
        $loadConfig->expects($this->once())
            ->method('writeDisposition')
            ->with('WRITE_TRUNCATE')
            ->willReturnSelf();

        $table = $this->createMock(Table::class);
        $table->method('load')->willReturn($loadConfig);

        $dataset = $this->createMock(Dataset::class);
        $dataset->method('table')->willReturn($table);
        $this->client->method('dataset')->willReturn($dataset);
        $this->client->method('startJob')->willReturn($this->createMock(Job::class));

        $this->bigQuery->loadFromJson($file, 'users', true);
    }

    public function testLoadFromJsonDefaultsToAppendWithoutTruncate(): void
    {
        $file = fopen('php://memory', 'r');

        $loadConfig = $this->createMock(LoadJobConfiguration::class);
        $loadConfig->method('sourceFormat')->willReturnSelf();

        // Incremental/buffered mode keeps BigQuery's default (append)
        $loadConfig->expects($this->never())->method('writeDisposition');

        $table = $this->createMock(Table::class);
        $table->method('load')->willReturn($loadConfig);

        $dataset = $this->createMock(Dataset::class);
        $dataset->method('table')->willReturn($table);
        $this->client->method('dataset')->willReturn($dataset);
        $this->client->method('startJob')->willReturn($this->createMock(Job::class));

        $this->bigQuery->loadFromJson($file, 'users');
    }

    public function testInjectedClientIsUsedInsteadOfBuildingOne(): void
    {
        // getClient() must return the injected client without touching env/key file
        $this->assertSame($this->client, $this->bigQuery->getClient());
    }
}
