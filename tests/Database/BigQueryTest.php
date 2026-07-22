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
        unset($_ENV['BQ_DATASET'], $_ENV['CREATED_AT_LOOKBACK']);
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

    public function testInjectedClientIsUsedInsteadOfBuildingOne(): void
    {
        // getClient() must return the injected client without touching env/key file
        $this->assertSame($this->client, $this->bigQuery->getClient());
    }
}
