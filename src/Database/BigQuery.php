<?php

namespace MysqlToGoogleBigQuery\Database;

use Doctrine\DBAL\Types\Types;
use Google\Cloud\BigQuery\BigQueryClient;

class BigQuery
{
    protected ?BigQueryClient $client = null;
    protected $tablesMetadata = [];

    /**
     * Allow injecting a pre-built client (used by tests)
     */
    public function __construct(?BigQueryClient $client = null)
    {
        $this->client = $client;
    }

    /**
     * Create a BigQuery Table based on MySQL Table columns
     * @param string $tableName Table Name
     * @param array $mysqlTableColumns Array of Doctrine\DBAL\Schema\Column
     * @return \Google\Cloud\BigQuery\Table Table object
     */
    public function createTable($tableName, $mysqlTableColumns)
    {
        $bigQueryColumns = [];

        // Valid types for BigQuery are:
        // STRING, BYTES, INTEGER, FLOAT, BOOLEAN,
        // TIMESTAMP, DATE, TIME, DATETIME
        foreach ($mysqlTableColumns as $name => $column) {
            switch ($column->getType()->getName()) {
                case 'bigquerydate':
                    $type = 'DATE';
                    break;

                case 'bigquerydatetime':
                    $type = 'DATETIME';
                    break;

                case Types::BIGINT:
                    $type = 'INTEGER';
                    break;

                case Types::BOOLEAN:
                    $type = 'BOOLEAN';
                    break;

                case Types::DATE_MUTABLE:
                case Types::DATE_IMMUTABLE:
                    $type = 'DATETIME';
                    break;

                case Types::DATETIME_MUTABLE:
                case Types::DATETIME_IMMUTABLE:
                    $type = 'DATETIME';
                    break;

                case Types::DECIMAL:
                    $type = 'FLOAT';
                    break;

                case Types::FLOAT:
                    $type = 'FLOAT';
                    break;

                case Types::INTEGER:
                    $type = 'INTEGER';
                    break;

                case Types::SMALLINT:
                    $type = 'INTEGER';
                    break;

                case Types::TIME_MUTABLE:
                case Types::TIME_IMMUTABLE:
                    $type = 'TIME';
                    break;

                default:
                    $type = 'STRING';
                    break;
            }

            $bigQueryColumns[] = [
                'name' => $name,
                'type' => $type
            ];
        }

        $client = $this->getClient();
        $dataset = $client->dataset($_ENV['BQ_DATASET']);

        return $dataset->createTable($tableName, [
            'schema' => [
                'fields' => $bigQueryColumns
            ],
        ]);
    }

    /**
     * Delete a BigQuery Table
     * @param string $tableName Table Name
     */
    public function deleteTable(string $tableName)
    {
        $client = $this->getClient();
        $dataset = $client->dataset($_ENV['BQ_DATASET']);
        $dataset->table($tableName)->delete();
    }

    /**
     * Get the number of rows on a table
     * @param string $tableName Table name
     * @return int|bool          false if table doesn't exists, or the number of rows
     */
    public function getCountTableRows(string $tableName)
    {
        $this->getTablesMetadata();

        if (!array_key_exists($tableName, $this->tablesMetadata)) {
            return false;
        }

        return $this->tablesMetadata[$tableName]['row_count'];
    }

    /**
     * Get the maximum value of a column
     * @param string $tableName Table name
     * @param string $columnName Column name
     * @return string               Max value
     */
    public function getMaxColumnValue(string $tableName, string $columnName)
    {
        $client = $this->getClient();

        $sql = 'SELECT MAX(`' . $columnName . '`) AS columnMax'
            . ' FROM `' . $_ENV['BQ_DATASET'] . '.' . $tableName . '`'
            . ' WHERE created_at >= \'' . date('Y-m-d', strtotime($_ENV['CREATED_AT_LOOKBACK'] ?? '-8 days')) . '\'';

        // runQuery() blocks until the query completes; the job location is
        // propagated natively by the client (no manual reload loop needed)
        $result = $client->runQuery($client->query($sql));

        foreach ($result->rows() as $row) {
            return $row['columnMax'];
        }

        return false;
    }

    /**
     * Delete all values of a column
     * @param string $tableName Table name
     * @param string $columnName Column name
     * @param string $columnValue Value to be deleted
     * @return \Google\Cloud\BigQuery\QueryResults Result
     */
    public function deleteColumnValue(string $tableName, string $columnName, string $columnValue)
    {
        $client = $this->getClient();

        // Non numeric values needs ""
        if (!is_numeric($columnValue)) {
            $columnValue = '"' . $columnValue . '"';
        }

        $date = date('Y-m-d', strtotime('-3 month'));

        $sql = 'DELETE FROM `' . $_ENV['BQ_DATASET'] . '.' . $tableName . '`' .
            ' WHERE `' . $columnName . '` = ' . $columnValue . " AND created_at >= '$date'";

        return $client->runQuery($client->query($sql));
    }

    /**
     * Get BigQuery API Client
     * @return BigQueryClient BigQuery API Client
     */
    public function getClient()
    {
        if ($this->client) {
            return $this->client;
        }

        $keyFilePath = $_ENV['BQ_KEY_FILE'];

        // Support relative and absolute path
        if ($keyFilePath[0] !== '/') {
            $keyFilePath = getcwd() . '/' . $keyFilePath;
        }

        if (!file_exists($keyFilePath)) {
            throw new \Exception('Google Service Account JSON Key File not found', 1);
        }

        return $this->client = new BigQueryClient([
            'projectId' => $_ENV['BQ_PROJECT_ID'],
            'keyFile' => json_decode(file_get_contents($keyFilePath), true),
            'scopes' => [BigQueryClient::SCOPE],
            'location' => $_ENV['BQ_LOCATION'] ?? 'US',
        ]);
    }

    /**
     * Get table metadata
     * See https://cloud.google.com/bigquery/querying-data#metadata_about_tables_in_a_dataset
     *
     * @return array Array with all dataset tables information
     */
    public function getTablesMetadata()
    {
        $client = $this->getClient();

        // __TABLES__ requires backticks under Standard SQL (the modern client
        // defaults to Standard SQL; the old one ran this under Legacy SQL)
        $query = $client->query('SELECT * FROM `' . $_ENV['BQ_DATASET'] . '.__TABLES__`')
            ->useQueryCache(false);

        $queryResults = $client->runQuery($query);

        foreach ($queryResults->rows() as $row) {
            $this->tablesMetadata[$row['table_id']] = $row;
        }

        return $this->tablesMetadata;
    }

    /**
     * Load data to BigQuery reading it from JSON NEWLINE DELIMITED File
     * @param resource|string $file Resource or String (path) of JSON file
     * @param string $tableName Table Name
     * @param bool $truncate Replace the table data atomically (WRITE_TRUNCATE)
     *                       instead of appending; the swap happens on job
     *                       commit, so the table is never left empty
     * @return \Google\Cloud\BigQuery\Job            BigQuery Data Load Job
     */
    public function loadFromJson($file, $tableName, bool $truncate = false)
    {
        $client = $this->getClient();
        $dataset = $client->dataset($_ENV['BQ_DATASET']);
        $table = $dataset->table($tableName);

        $loadConfig = $table->load($file)
            ->sourceFormat('NEWLINE_DELIMITED_JSON');

        if ($truncate) {
            $loadConfig = $loadConfig->writeDisposition('WRITE_TRUNCATE');
        }

        // startJob() returns without waiting: SyncService overlaps the upload
        // of one batch with the generation of the next one
        return $client->startJob($loadConfig);
    }

    /**
     * Check if a BigQuery table exists
     * @param string $tableName Table name
     * @return bool              True if table exists
     */
    public function tableExists(string $tableName)
    {
        $client = $this->getClient();
        $dataset = $client->dataset($_ENV['BQ_DATASET']);

        return $dataset->table($tableName)->exists();
    }
}
