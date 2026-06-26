<?php
namespace MysqlToGoogleBigQuery\Doctrine;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Platforms\AbstractPlatform;

class BigQueryDateType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'date';
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): mixed
    {
        if ($value === '0000-00-00') {
            return null;
        }

        return $value;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): mixed
    {
        return $value;
    }

    public function getName(): string
    {
        return 'bigquerydate';
    }
}
