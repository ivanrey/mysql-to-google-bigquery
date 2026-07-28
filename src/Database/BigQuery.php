<?php

namespace MysqlToGoogleBigQuery\Database;

use Doctrine\DBAL\Types\Types;
use Google\Cloud\BigQuery\BigQueryClient;
use MysqlToGoogleBigQuery\Config\EnvironmentLoader;

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
     * Resolve the created_at lookback window for a table.
     *
     * Precedence: CREATED_AT_LOOKBACK_<TABLE> (BigQuery table name uppercased,
     * non-alphanumerics replaced by "_") > CREATED_AT_LOOKBACK > '-3 month'.
     * The value is any strtotime()-parseable relative expression looking back.
     *
     * @param string $tableName BigQuery table name (the queries filter on it,
     *                          so the override key is the destination name)
     * @return string               strtotime()-parseable lookback expression
     */
    public function getCreatedAtLookback(string $tableName): string
    {
        $tableVar = 'CREATED_AT_LOOKBACK_' . preg_replace('/[^A-Z0-9]/', '_', strtoupper($tableName));

        // Empty/whitespace-only values count as unset, so a bare
        // `CREATED_AT_LOOKBACK=` line (or a blank per-table override) falls
        // back to the next source instead of aborting the sync.
        $lookback = '-3 month';
        $source = 'CREATED_AT_LOOKBACK';
        foreach ([$tableVar, 'CREATED_AT_LOOKBACK'] as $var) {
            if (isset($_ENV[$var]) && trim($_ENV[$var]) !== '') {
                $lookback = trim($_ENV[$var]);
                $source = $var;
                break;
            }
        }

        $timestamp = strtotime($lookback);

        if ($timestamp === false) {
            throw new \InvalidArgumentException(
                'Invalid lookback expression "' . $lookback . '" in ' . $source .
                ': must be a strtotime()-parseable value like "-8 days" or "-3 month"'
            );
        }

        // A future window (e.g. "8 days" missing its "-") would match no rows,
        // making getMaxColumnValue() return false and silently triggering a
        // full re-dump that duplicates the whole table. Reject it up front.
        if ($timestamp > time()) {
            throw new \InvalidArgumentException(
                'Lookback expression "' . $lookback . '" in ' . $source .
                ' resolves to a future date: it must look backwards (e.g. "-8 days", not "8 days")'
            );
        }

        return $lookback;
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
            . ' WHERE created_at >= \'' . date('Y-m-d', strtotime($this->getCreatedAtLookback($tableName))) . '\'';

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

        // Same lookback as getMaxColumnValue(): if the delete looked back a
        // shorter window than the max lookup, duplicates could survive
        $date = date('Y-m-d', strtotime($this->getCreatedAtLookback($tableName)));

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

        $config = [
            'scopes' => [BigQueryClient::SCOPE],
            'location' => $_ENV['BQ_LOCATION'] ?? 'US',
        ];

        // Both are optional: without them the client falls back to the
        // Application Default Credentials of the host and their project
        if (isset($_ENV['BQ_PROJECT_ID']) && trim($_ENV['BQ_PROJECT_ID']) !== '') {
            $config['projectId'] = trim($_ENV['BQ_PROJECT_ID']);
        }

        $keyFile = $this->getKeyFile();

        if ($keyFile !== null) {
            $config['keyFile'] = $keyFile;
        }

        return $this->client = new BigQueryClient($config);
    }

    /**
     * Decoded Google Service Account key, or null to authenticate with the
     * Application Default Credentials of the host.
     *
     * BQ_KEY_FILE may hold a path, or the key itself when it comes from a
     * secret (BQ_KEY_FILE=sm://…, already resolved by the EnvironmentLoader):
     * in that case the key is never written to disk.
     *
     * @return array|null Decoded key file, null when there is none
     */
    public function getKeyFile(): ?array
    {
        $keyFile = $_ENV['BQ_KEY_FILE'] ?? '';

        if (!is_string($keyFile) || trim($keyFile) === '') {
            return null;
        }

        if (str_starts_with(ltrim($keyFile), '{')) {
            $contents = $keyFile;
            $source = 'the BQ_KEY_FILE secret';
        } else {
            $contents = file_get_contents($this->getKeyFilePath());
            $source = $this->getKeyFilePath();
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            // Never echo the contents back: it is a credential
            throw new \Exception('The Google Service Account key from ' . $source . ' is not valid JSON', 1);
        }

        return $decoded;
    }

    /**
     * Absolute path of the Google Service Account JSON Key File
     *
     * A relative BQ_KEY_FILE is resolved against the directory of the loaded
     * .env, so a configuration keeps working from any working directory
     *
     * @return string Absolute path of an existing key file
     */
    public function getKeyFilePath(): string
    {
        $keyFilePath = EnvironmentLoader::resolvePath($_ENV['BQ_KEY_FILE']);

        if (!file_exists($keyFilePath)) {
            throw new \Exception('Google Service Account JSON Key File not found: ' . $keyFilePath, 1);
        }

        return $keyFilePath;
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
