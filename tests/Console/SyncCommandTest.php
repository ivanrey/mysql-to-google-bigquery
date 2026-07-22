<?php
namespace MysqlToGoogleBigQuery\Tests\Console;

use MysqlToGoogleBigQuery\Console\Commands\SyncCommand;
use MysqlToGoogleBigQuery\Services\SyncService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

class SyncCommandTest extends TestCase
{
    /**
     * Build an Application wired like bin/console (no try/catch of our own:
     * Symfony Console handles errors), running the given service double.
     */
    private function applicationTester(SyncService $service): ApplicationTester
    {
        $application = new Application();
        $application->add(new SyncCommand($service));
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }

    public function testSuccessfulSyncExitsWithZero(): void
    {
        $service = $this->createMock(SyncService::class);
        $service->expects($this->once())->method('execute');

        $tester = $this->applicationTester($service);
        $exitCode = $tester->run([
            'command' => 'sync',
            'table-name' => 'users',
            '--database-name' => 'mydb',
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function testFailedSyncReportsErrorOnceAndExitsNonZero(): void
    {
        $service = $this->createMock(SyncService::class);
        $service->method('execute')
            ->willThrowException(new \Exception('BigQuery replied with errors: boom'));

        $tester = $this->applicationTester($service);
        $exitCode = $tester->run([
            'command' => 'sync',
            'table-name' => 'users',
            '--database-name' => 'mydb',
        ]);

        $this->assertNotSame(0, $exitCode);

        $display = $tester->getDisplay(true);

        // Reported once, readable — not our old echo of the raw trace on top
        // of Symfony's own report (the duplicate the debug try/catch caused)
        $this->assertSame(1, substr_count($display, 'BigQuery replied with errors: boom'));
        $this->assertStringNotContainsString('#0', $display, 'raw getTraceAsString() output leaked');
    }

    public function testCommandLineOptionsOverrideEnvDefaults(): void
    {
        $_ENV['DB_DATABASE_NAME'] = 'env_db';

        try {
            $service = $this->createMock(SyncService::class);
            $service->expects($this->once())
                ->method('execute')
                ->with(
                    'cli_db',            // --database-name wins over env
                    'users',
                    'bq_users',          // --bigquery-table-name wins over table-name
                    $this->anything(),
                    $this->anything(),
                    $this->anything(),
                    $this->anything(),
                    $this->anything()
                );

            $tester = $this->applicationTester($service);
            $exitCode = $tester->run([
                'command' => 'sync',
                'table-name' => 'users',
                '--database-name' => 'cli_db',
                '--bigquery-table-name' => 'bq_users',
            ]);

            $this->assertSame(0, $exitCode);
        } finally {
            unset($_ENV['DB_DATABASE_NAME']);
        }
    }

    public function testDatabaseNameFallsBackToEnv(): void
    {
        $_ENV['DB_DATABASE_NAME'] = 'env_db';

        try {
            $service = $this->createMock(SyncService::class);
            $service->expects($this->once())
                ->method('execute')
                ->with('env_db', $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything());

            $tester = $this->applicationTester($service);
            $tester->run([
                'command' => 'sync',
                'table-name' => 'users',
            ]);
        } finally {
            unset($_ENV['DB_DATABASE_NAME']);
        }
    }
}
