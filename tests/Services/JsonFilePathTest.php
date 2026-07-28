<?php
namespace MysqlToGoogleBigQuery\Tests\Services;

use MysqlToGoogleBigQuery\Config\EnvironmentLoader;
use MysqlToGoogleBigQuery\Database\BigQuery;
use MysqlToGoogleBigQuery\Database\Mysql;
use MysqlToGoogleBigQuery\Services\SyncService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class JsonFilePathTest extends TestCase
{
    private SyncService $service;

    protected function setUp(): void
    {
        $this->service = new SyncService(
            $this->createMock(BigQuery::class),
            $this->createMock(Mysql::class)
        );
    }

    protected function tearDown(): void
    {
        unset($_ENV['CACHE_DIR'], $_ENV[EnvironmentLoader::ENV_DIR]);
    }

    /**
     * Invoke the protected getJsonFilePath() method.
     */
    private function jsonFilePath(string $tableName): string
    {
        $method = new ReflectionMethod(SyncService::class, 'getJsonFilePath');
        $method->setAccessible(true);

        return $method->invoke($this->service, $tableName);
    }

    public function testRelativeCacheDirIsResolvedAgainstTheEnvDirectory(): void
    {
        $_ENV[EnvironmentLoader::ENV_DIR] = '/configs/client-a';
        $_ENV['CACHE_DIR'] = 'cache';

        $this->assertSame('/configs/client-a/cache/users', $this->jsonFilePath('users'));
    }

    public function testAbsoluteCacheDirIsUsedAsIs(): void
    {
        $_ENV[EnvironmentLoader::ENV_DIR] = '/configs/client-a';
        $_ENV['CACHE_DIR'] = '/var/tmp/bq-cache/';

        $this->assertSame('/var/tmp/bq-cache/users', $this->jsonFilePath('users'));
    }

    public function testCacheDirWithoutTrailingSlashStillSeparatesTheTableName(): void
    {
        // The old concatenation produced "/var/tmp/bq-cacheusers"
        $_ENV['CACHE_DIR'] = '/var/tmp/bq-cache';

        $this->assertSame('/var/tmp/bq-cache/users', $this->jsonFilePath('users'));
    }

    public function testWithoutCacheDirItFallsBackToTheProjectCacheDirectory(): void
    {
        $path = $this->jsonFilePath('users');

        $this->assertStringEndsWith('/cache/users', $path);
        $this->assertFileExists(dirname($path));
    }

    public function testBlankCacheDirFallsBackToTheDefault(): void
    {
        // A bare `CACHE_DIR=` line must not write to the filesystem root
        $_ENV['CACHE_DIR'] = '  ';

        $this->assertStringEndsWith('/cache/users', $this->jsonFilePath('users'));
    }
}
