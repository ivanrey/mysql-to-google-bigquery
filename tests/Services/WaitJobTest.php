<?php
namespace MysqlToGoogleBigQuery\Tests\Services;

use Google\Cloud\BigQuery\Job;
use MysqlToGoogleBigQuery\Database\BigQuery;
use MysqlToGoogleBigQuery\Database\Mysql;
use MysqlToGoogleBigQuery\Services\SyncService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class WaitJobTest extends TestCase
{
    private SyncService $service;

    protected function setUp(): void
    {
        $this->service = new SyncService(
            $this->createMock(BigQuery::class),
            $this->createMock(Mysql::class)
        );
    }

    private function waitJob(Job $job): void
    {
        $method = new ReflectionMethod(SyncService::class, 'waitJob');
        $method->setAccessible(true);
        $method->invoke($this->service, $job);
    }

    public function testWaitsForCompletionUsingNativePolling(): void
    {
        $job = $this->createMock(Job::class);

        // The native waitUntilComplete() carries the job location internally,
        // replacing the old manual reload() loop + location workaround
        $job->expects($this->once())->method('waitUntilComplete');
        $job->method('info')->willReturn(['status' => ['state' => 'DONE']]);

        $this->waitJob($job);
    }

    public function testThrowsWhenJobFinishesWithErrors(): void
    {
        $job = $this->createMock(Job::class);
        $job->method('waitUntilComplete');
        $job->method('info')->willReturn([
            'status' => [
                'state' => 'DONE',
                'errors' => [
                    ['message' => 'Invalid schema'],
                    ['message' => 'Row too large'],
                ],
            ],
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid schema');

        $this->waitJob($job);
    }

    public function testNoExceptionWhenErrorsKeyIsEmpty(): void
    {
        $job = $this->createMock(Job::class);
        $job->method('waitUntilComplete');
        $job->method('info')->willReturn([
            'status' => ['state' => 'DONE', 'errors' => []],
        ]);

        $this->waitJob($job);
        $this->addToAssertionCount(1);
    }
}
