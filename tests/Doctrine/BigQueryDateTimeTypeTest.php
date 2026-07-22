<?php
namespace MysqlToGoogleBigQuery\Tests\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use MysqlToGoogleBigQuery\Doctrine\BigQueryDateTimeType;
use PHPUnit\Framework\TestCase;

class BigQueryDateTimeTypeTest extends TestCase
{
    private BigQueryDateTimeType $type;
    private AbstractPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new BigQueryDateTimeType();
        $this->platform = $this->createMock(AbstractPlatform::class);
    }

    public function testGetName(): void
    {
        $this->assertSame('bigquerydatetime', $this->type->getName());
    }

    public function testGetSQLDeclaration(): void
    {
        $this->assertSame('datetime', $this->type->getSQLDeclaration([], $this->platform));
    }

    public function testConvertZeroDateTimeToNull(): void
    {
        $this->assertNull($this->type->convertToPHPValue('0000-00-00 00:00:00', $this->platform));
    }

    public function testConvertToPHPValueReplacesSpaceWithT(): void
    {
        // BigQuery DATETIME expects ISO 8601 with 'T' separator
        $this->assertSame(
            '2024-01-15T10:30:00',
            $this->type->convertToPHPValue('2024-01-15 10:30:00', $this->platform)
        );
    }

    public function testConvertToDatabaseValueReplacesTWithSpace(): void
    {
        $this->assertSame(
            '2024-01-15 10:30:00',
            $this->type->convertToDatabaseValue('2024-01-15T10:30:00', $this->platform)
        );
    }
}
