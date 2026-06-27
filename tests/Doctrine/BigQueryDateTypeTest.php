<?php
namespace MysqlToGoogleBigQuery\Tests\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use MysqlToGoogleBigQuery\Doctrine\BigQueryDateType;
use PHPUnit\Framework\TestCase;

class BigQueryDateTypeTest extends TestCase
{
    private BigQueryDateType $type;
    private AbstractPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new BigQueryDateType();
        $this->platform = $this->createMock(AbstractPlatform::class);
    }

    public function testGetName(): void
    {
        $this->assertSame('bigquerydate', $this->type->getName());
    }

    public function testGetSQLDeclaration(): void
    {
        $this->assertSame('date', $this->type->getSQLDeclaration([], $this->platform));
    }

    public function testConvertZeroDateToNull(): void
    {
        $this->assertNull($this->type->convertToPHPValue('0000-00-00', $this->platform));
    }

    public function testConvertValidDateIsUnchanged(): void
    {
        $this->assertSame('2024-01-15', $this->type->convertToPHPValue('2024-01-15', $this->platform));
    }

    public function testConvertToDatabaseValueIsUnchanged(): void
    {
        $this->assertSame('2024-01-15', $this->type->convertToDatabaseValue('2024-01-15', $this->platform));
    }
}
