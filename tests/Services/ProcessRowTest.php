<?php
namespace MysqlToGoogleBigQuery\Tests\Services;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use MysqlToGoogleBigQuery\Database\BigQuery;
use MysqlToGoogleBigQuery\Database\Mysql;
use MysqlToGoogleBigQuery\Services\SyncService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProcessRowTest extends TestCase
{
    private SyncService $service;
    private AbstractPlatform $platform;

    protected function setUp(): void
    {
        $this->service = new SyncService(
            $this->createMock(BigQuery::class),
            $this->createMock(Mysql::class)
        );
        $this->platform = $this->createMock(AbstractPlatform::class);
    }

    /**
     * Invoke the protected processRow() method.
     */
    private function processRow(array $columns, array $ignoreColumns, array $row): array
    {
        $method = new ReflectionMethod(SyncService::class, 'processRow');
        $method->setAccessible(true);

        return $method->invoke($this->service, $columns, $this->platform, $ignoreColumns, $row);
    }

    /**
     * Build a Column mock whose getType() returns a Type with the given name
     * and (optionally) a convertToPhpValue() behavior.
     */
    private function columnOfType(string $typeName, mixed $convertedValue = null): Column
    {
        $type = $this->createMock(Type::class);
        $type->method('getName')->willReturn($typeName);

        if ($convertedValue !== null) {
            $type->method('convertToPhpValue')->willReturn($convertedValue);
        }

        $column = $this->createMock(Column::class);
        $column->method('getType')->willReturn($type);

        return $column;
    }

    public function testIgnoredColumnIsRemoved(): void
    {
        $columns = [
            'id'     => $this->columnOfType(Types::INTEGER, 5),
            'secret' => $this->columnOfType(Types::STRING),
        ];

        $result = $this->processRow($columns, ['secret'], ['id' => '5', 'secret' => 'hidden']);

        $this->assertArrayNotHasKey('secret', $result);
        $this->assertArrayHasKey('id', $result);
    }

    public function testNonStringTypeIsConvertedToPhpValue(): void
    {
        $columns = [
            'id' => $this->columnOfType(Types::INTEGER, 5),
        ];

        $result = $this->processRow($columns, [], ['id' => '5']);

        // Integer column goes through convertToPhpValue() -> real int
        $this->assertSame(5, $result['id']);
    }

    public function testStringTypeIsLeftAsStringAndNotCorrupted(): void
    {
        $columns = [
            'name' => $this->columnOfType(Types::STRING),
        ];

        // A valid UTF-8 string with accents must survive the encoding step intact
        $result = $this->processRow($columns, [], ['name' => 'José Ñandú']);

        $this->assertSame('José Ñandú', $result['name']);
    }

    public function testColumnLookupIsCaseInsensitive(): void
    {
        // Row keys may be uppercase; columns are indexed lowercase
        $columns = [
            'id' => $this->columnOfType(Types::INTEGER, 42),
        ];

        $result = $this->processRow($columns, [], ['ID' => '42']);

        $this->assertSame(42, $result['ID']);
    }
}
