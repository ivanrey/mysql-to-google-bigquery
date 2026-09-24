<?php
namespace MysqlToGoogleBigQuery\Tests\Database;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\BigQuery\Dataset;
use Google\Cloud\BigQuery\Job;
use Google\Cloud\BigQuery\LoadJobConfiguration;
use Google\Cloud\BigQuery\QueryJobConfiguration;
use Google\Cloud\BigQuery\QueryResults;
use Google\Cloud\BigQuery\Table;
use MysqlToGoogleBigQuery\Config\EnvironmentLoader;
use MysqlToGoogleBigQuery\Database\BigQuery;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BigQueryTest extends TestCase
{
    private BigQueryClient&MockObject $client;
    private BigQuery $bigQuery;
    private string $keyFileDir;

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
            $_ENV['BQ_KEY_FILE'],
            $_ENV[EnvironmentLoader::ENV_DIR],
            $_ENV['CREATED_AT_LOOKBACK'],
            $_ENV['CREATED_AT_LOOKBACK_USERS'],
            $_ENV['CREATED_AT_LOOKBACK_USER_LOGS'],
            $_ENV['PARTITION_TYPE'],
            $_ENV['PARTITION_TYPE_USERS'],
            $_ENV['PARTITION_TYPE_USER_LOGS']
        );

        if (isset($this->keyFileDir)) {
            unlink($this->keyFileDir . '/service-account-key.json');
            rmdir($this->keyFileDir);
            unset($this->keyFileDir);
        }
    }

    /**
     * Create a throwaway key file and return the directory holding it
     */
    private function createKeyFile(string $contents = '{}'): string
    {
        $this->keyFileDir = sys_get_temp_dir() . '/bq-key-' . uniqid();
        mkdir($this->keyFileDir, 0777, true);
        file_put_contents($this->keyFileDir . '/service-account-key.json', $contents);

        return $this->keyFileDir;
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

    /**
     * Column double whose Doctrine type has the given name
     */
    private function columnOfType(string $typeName): Column
    {
        $type = $this->createMock(Type::class);
        $type->method('getName')->willReturn($typeName);

        $column = $this->createMock(Column::class);
        $column->method('getType')->willReturn($type);

        return $column;
    }

    /**
     * Stub the dataset and capture the options createTable() sends
     */
    private function expectCreateTable(?array &$capturedOptions): void
    {
        $dataset = $this->createMock(Dataset::class);
        $dataset->expects($this->once())
            ->method('createTable')
            ->willReturnCallback(function (string $name, array $options) use (&$capturedOptions) {
                $capturedOptions = $options;
                return $this->createMock(Table::class);
            });

        $this->client->method('dataset')->with('my_dataset')->willReturn($dataset);
    }

    public function testPartitionTypeDefaultsToMonth(): void
    {
        $this->assertSame('MONTH', $this->bigQuery->getPartitionType('users'));
    }

    public function testPartitionTypeUsesGlobalEnvVariable(): void
    {
        $_ENV['PARTITION_TYPE'] = 'DAY';

        $this->assertSame('DAY', $this->bigQuery->getPartitionType('users'));
    }

    public function testPerTablePartitionTypeWinsOverGlobal(): void
    {
        $_ENV['PARTITION_TYPE'] = 'DAY';
        $_ENV['PARTITION_TYPE_USER_LOGS'] = 'YEAR';

        // Same table name normalization as CREATED_AT_LOOKBACK_<TABLE>
        $this->assertSame('YEAR', $this->bigQuery->getPartitionType('user-logs'));
        $this->assertSame('DAY', $this->bigQuery->getPartitionType('orders'));
    }

    public function testCommandLinePartitionTypeWinsOverEnv(): void
    {
        $_ENV['PARTITION_TYPE'] = 'DAY';
        $_ENV['PARTITION_TYPE_USERS'] = 'YEAR';

        $this->assertSame('MONTH', $this->bigQuery->getPartitionType('users', 'MONTH'));
    }

    public function testBlankCommandLinePartitionTypeFallsBackToEnv(): void
    {
        $_ENV['PARTITION_TYPE'] = 'DAY';

        $this->assertSame('DAY', $this->bigQuery->getPartitionType('users', '  '));
    }

    public function testEmptyPartitionTypeFallsBackToDefault(): void
    {
        $_ENV['PARTITION_TYPE_USERS'] = '';
        $_ENV['PARTITION_TYPE'] = ' ';

        $this->assertSame('MONTH', $this->bigQuery->getPartitionType('users'));
    }

    public function testPartitionTypeIsCaseInsensitiveAndTrimmed(): void
    {
        $_ENV['PARTITION_TYPE'] = '  day ';

        $this->assertSame('DAY', $this->bigQuery->getPartitionType('users'));
    }

    public function testPartitionTypeNoneDisablesPartitioning(): void
    {
        $_ENV['PARTITION_TYPE_USERS'] = 'none';

        $this->assertNull($this->bigQuery->getPartitionType('users'));
    }

    public function testInvalidPartitionTypeNamesTheOffendingVariable(): void
    {
        $_ENV['PARTITION_TYPE_USERS'] = 'WEEK';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid partition type "WEEK" in PARTITION_TYPE_USERS');

        $this->bigQuery->getPartitionType('users');
    }

    public function testInvalidCommandLinePartitionTypeNamesTheOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('in --partition-type');

        $this->bigQuery->getPartitionType('users', 'HOUR');
    }

    public function testPartitionColumnIsCreatedAtWhenItIsADatetime(): void
    {
        $columns = [
            'id' => $this->columnOfType(Types::INTEGER),
            'created_at' => $this->columnOfType('bigquerydatetime'),
        ];

        $this->assertSame('created_at', $this->bigQuery->getPartitionColumn($columns));
    }

    public function testPartitionColumnAcceptsADate(): void
    {
        $columns = ['created_at' => $this->columnOfType('bigquerydate')];

        $this->assertSame('created_at', $this->bigQuery->getPartitionColumn($columns));
    }

    public function testNoPartitionColumnWithoutCreatedAt(): void
    {
        $columns = ['id' => $this->columnOfType(Types::INTEGER)];

        $this->assertNull($this->bigQuery->getPartitionColumn($columns));
    }

    public function testNoPartitionColumnWhenCreatedAtIsIgnored(): void
    {
        // Ignored means all NULLs in BigQuery: every row in one partition
        $columns = ['created_at' => $this->columnOfType('bigquerydatetime')];

        $this->assertNull($this->bigQuery->getPartitionColumn($columns, ['created_at']));
    }

    public function testNoPartitionColumnWhenCreatedAtIsNotADate(): void
    {
        // A VARCHAR created_at becomes a STRING, which BigQuery can't partition by
        $columns = ['created_at' => $this->columnOfType(Types::STRING)];

        $this->assertNull($this->bigQuery->getPartitionColumn($columns));
    }

    public function testCreateTablePartitionsByCreatedAt(): void
    {
        $this->expectCreateTable($options);

        $this->bigQuery->createTable('users', [
            'id' => $this->columnOfType(Types::INTEGER),
            'created_at' => $this->columnOfType('bigquerydatetime'),
        ], 'MONTH');

        $this->assertSame(['type' => 'MONTH', 'field' => 'created_at'], $options['timePartitioning']);
        $this->assertSame([
            ['name' => 'id', 'type' => 'INTEGER'],
            ['name' => 'created_at', 'type' => 'DATETIME'],
        ], $options['schema']['fields']);
    }

    public function testCreateTableWithoutPartitionTypeIsUnpartitioned(): void
    {
        $this->expectCreateTable($options);

        $this->bigQuery->createTable('users', ['id' => $this->columnOfType(Types::INTEGER)]);

        $this->assertArrayNotHasKey('timePartitioning', $options);
    }

    public function testCreateTableMapsMysqlTypes(): void
    {
        $this->expectCreateTable($options);

        $this->bigQuery->createTable('users', [
            'a' => $this->columnOfType('bigquerydate'),
            'b' => $this->columnOfType(Types::BIGINT),
            'c' => $this->columnOfType(Types::BOOLEAN),
            'd' => $this->columnOfType(Types::DATE_MUTABLE),
            'e' => $this->columnOfType(Types::DECIMAL),
            'f' => $this->columnOfType(Types::TIME_MUTABLE),
            'g' => $this->columnOfType(Types::JSON),
        ]);

        $this->assertSame(
            ['DATE', 'INTEGER', 'BOOLEAN', 'DATETIME', 'FLOAT', 'TIME', 'STRING'],
            array_column($options['schema']['fields'], 'type')
        );
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

    public function testKeyFilePathResolvesRelativeToTheLoadedEnvDirectory(): void
    {
        $envDir = $this->createKeyFile();
        $_ENV[EnvironmentLoader::ENV_DIR] = $envDir;
        $_ENV['BQ_KEY_FILE'] = 'service-account-key.json';

        // The key sits next to its .env, so the sync works from any cwd
        $this->assertSame($envDir . '/service-account-key.json', $this->bigQuery->getKeyFilePath());
    }

    public function testKeyFilePathKeepsAbsolutePaths(): void
    {
        $envDir = $this->createKeyFile();
        $_ENV[EnvironmentLoader::ENV_DIR] = '/somewhere/else';
        $_ENV['BQ_KEY_FILE'] = $envDir . '/service-account-key.json';

        $this->assertSame($envDir . '/service-account-key.json', $this->bigQuery->getKeyFilePath());
    }

    public function testWithoutKeyFileTheClientFallsBackToApplicationDefaultCredentials(): void
    {
        // No BQ_KEY_FILE: nothing to load, the host identity authenticates
        $this->assertNull($this->bigQuery->getKeyFile());
    }

    public function testBlankKeyFileAlsoMeansApplicationDefaultCredentials(): void
    {
        $_ENV['BQ_KEY_FILE'] = '   ';

        $this->assertNull($this->bigQuery->getKeyFile());
    }

    public function testKeyFileCanArriveAsAResolvedSecretInsteadOfAPath(): void
    {
        // BQ_KEY_FILE=sm://… already replaced by its payload: the key stays in
        // memory and is never written to disk
        $_ENV['BQ_KEY_FILE'] = '{"type":"service_account","client_email":"sync@example.com"}';

        $this->assertSame(
            ['type' => 'service_account', 'client_email' => 'sync@example.com'],
            $this->bigQuery->getKeyFile()
        );
    }

    public function testKeyFileIsStillReadFromDiskWhenItIsAPath(): void
    {
        $envDir = $this->createKeyFile('{"type":"service_account"}');
        $_ENV[EnvironmentLoader::ENV_DIR] = $envDir;
        $_ENV['BQ_KEY_FILE'] = 'service-account-key.json';

        $this->assertSame(['type' => 'service_account'], $this->bigQuery->getKeyFile());
    }

    public function testUnreadableKeyFileIsReportedAsAReadError(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root reads any file, permissions cannot be exercised');
        }

        // Exists but cannot be read, like a key file the cron user has no
        // access to: "invalid JSON" would point at the wrong problem
        $envDir = $this->createKeyFile();
        chmod($envDir . '/service-account-key.json', 0000);

        $_ENV[EnvironmentLoader::ENV_DIR] = $envDir;
        $_ENV['BQ_KEY_FILE'] = 'service-account-key.json';

        try {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('Could not read');

            $this->bigQuery->getKeyFile();
        } finally {
            chmod($envDir . '/service-account-key.json', 0644);
        }
    }

    public function testInvalidKeyContentsFailWithoutEchoingTheCredential(): void
    {
        $_ENV['BQ_KEY_FILE'] = '{not really json';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('is not valid JSON');
        $this->expectExceptionMessageMatches('/^((?!not really json).)*$/s');

        $this->bigQuery->getKeyFile();
    }

    public function testMissingKeyFileErrorNamesTheResolvedPath(): void
    {
        $_ENV[EnvironmentLoader::ENV_DIR] = '/configs/client-a';
        $_ENV['BQ_KEY_FILE'] = 'service-account-key.json';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('/configs/client-a/service-account-key.json');

        $this->bigQuery->getKeyFilePath();
    }
}
