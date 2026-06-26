<?php
namespace MysqlToGoogleBigQuery\Doctrine;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Platforms\AbstractPlatform;

class BigQueryDateTimeType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'datetime';
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): mixed
    {
        if ($value === '0000-00-00 00:00:00') {
            return null;
        }

        return str_replace(' ', 'T', $value);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): mixed
    {
        return str_replace('T', ' ', $value);
    }

    public function getName(): string
    {
        return 'bigquerydatetime';
    }
}
