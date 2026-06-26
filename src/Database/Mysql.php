<?php
namespace MysqlToGoogleBigQuery\Database;

use Doctrine\DBAL\Types\Type;

class Mysql
{
    protected $conn;

    /**
     * Configure and connect to MySQL Database
     * @param  string $databaseName      Database name
     * @return Doctrine\DBAL\Connection  Doctrine DBAL Connection
     */
    public function getConnection(string $databaseName)
    {
        // If we are connected, just return the last connection
        if ($this->conn) {
            return $this->conn;
        }

        $connParams = [
            'dbname' => $databaseName,
            'user' => $_ENV['DB_USERNAME'],
            'password' => $_ENV['DB_PASSWORD'],
            'host' => $_ENV['DB_HOST'],
            'charset'  => 'utf8',
            'driver' => 'pdo_mysql',
            // Special doctrine driver, with reconnect attempts support
            'wrapperClass' => \Facile\DoctrineMySQLComeBack\Doctrine\DBAL\Connection::class,
            'driverOptions' => [
                'x_reconnect_attempts' => 9
            ]
        ];

        $this->conn = \Doctrine\DBAL\DriverManager::getConnection($connParams);

        // Replace the DateTime conversion (guard prevents re-registration on repeated calls)
        if (!Type::hasType('bigquerydatetime')) {
            Type::addType('bigquerydatetime', 'MysqlToGoogleBigQuery\Doctrine\BigQueryDateTimeType');
        }
        if (!Type::hasType('bigquerydate')) {
            Type::addType('bigquerydate', 'MysqlToGoogleBigQuery\Doctrine\BigQueryDateType');
        }

        // Map types to classes
        $this->conn->getDatabasePlatform()->registerDoctrineTypeMapping('date', 'bigquerydate');
        $this->conn->getDatabasePlatform()->registerDoctrineTypeMapping('datetime', 'bigquerydatetime');
        $this->conn->getDatabasePlatform()->registerDoctrineTypeMapping('timestamp', 'bigquerydatetime');

        // Add support for MySQL 5.7 JSON type
        $this->conn->getDatabasePlatform()->registerDoctrineTypeMapping('json', 'text');
        $this->conn->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'text');

        return $this->conn;
    }

    /**
     * Get the number of rows on a table
     * @param  string $databaseName Database name
     * @param  string $tableName    Table name
     * @param  string $columnName   Column name
     * @param  string $columnValue  Column value
     * @return int                  Number of rows
     */
    public function getCountTableRows(string $databaseName, string $tableName, $columnName = null, $columnValue = null)
    {
        if ($columnName && $columnValue) {
            $result = $this->getConnection($databaseName)->executeQuery(
                'SELECT COUNT(*) AS count FROM `' . $tableName . '` WHERE ' . $columnName . ' >= "' . $columnValue . '"'
            );
        } else {
            $result = $this->getConnection($databaseName)->executeQuery('SELECT COUNT(*) AS count FROM `' . $tableName . '`');
        }

        while ($row = $result->fetchAssociative()) {
            return (int) $row['count'];
        }

        throw new \Exception('Mysql table ' . $tableName . ' not found');
    }

    /**
     * Get the maximum value of a column of a table
     * @param  string $databaseName Database name
     * @param  string $tableName    Table name
     * @param  string $columnName   Column name
     * @return string               Max value
     */
    public function getMaxColumnValue(string $databaseName, string $tableName, string $columnName)
    {
        $result = $this->getConnection($databaseName)->executeQuery('SELECT MAX(' . $columnName . ') AS columnMax FROM `' . $tableName . '`');

        while ($row = $result->fetchAssociative()) {
            return $row['columnMax'];
        }

        throw new \Exception('Mysql table ' . $tableName . ' not found');
    }

    /**
     * Return the table columns
     * @param  string $databaseName Database name
     * @param  string $tableName    Table name
     * @return array                Array of Doctrine\DBAL\Schema\Column
     */
    public function getTableColumns($databaseName, $tableName)
    {
        $mysqlConnection = $this->getConnection($databaseName);
        $mysqlSchemaManager = $mysqlConnection->createSchemaManager();

        $mysqlTableDetails = $mysqlSchemaManager->introspectTable($tableName);
        return $mysqlTableDetails->getColumns();
    }
}
