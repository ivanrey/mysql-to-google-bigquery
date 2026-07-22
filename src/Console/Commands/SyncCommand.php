<?php
namespace MysqlToGoogleBigQuery\Console\Commands;

use MysqlToGoogleBigQuery\Services\SyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncCommand extends Command
{
    /**
     * Allow injecting the service (used by tests); by default it is
     * resolved from the DI container on execute()
     */
    public function __construct(private ?SyncService $syncService = null)
    {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setName('sync')
            ->setDescription('Sync a MySQL table to BigQuery')
            ->setHelp('This commands syncs data between a MySQL table and BigQuery')
            ->addArgument('table-name', InputArgument::REQUIRED, 'The name of the table you want to sync')
            ->addOption('create-table', 'c', InputOption::VALUE_NONE, 'If BigQuery table doesn\'t exist, create it')
            ->addOption('delete-table', 'd', InputOption::VALUE_NONE, 'Delete the BigQuery table before syncing')
            ->addOption('no-data', null, InputOption::VALUE_NONE, 'If specified do not copy data')
            ->addOption(
                'un-buffer',
                null,
                InputOption::VALUE_NONE,
                'Full dump streaming the MySQL table (unbuffered). Requires --delete-table; the reload is atomic (WRITE_TRUNCATE), the table is never left empty'
            )
            ->addOption(
                'order-column',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Column to order the results by. This column is also used to determine if new rows have to be synced.'
            )
            ->addOption(
                'ignore-column',
                'i',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Ignore a column from syncing. You can use this option multiple times'
            )
            ->addOption('database-name', null, InputOption::VALUE_OPTIONAL, 'MySQL database name')
            ->addOption('bigquery-table-name', null, InputOption::VALUE_OPTIONAL, 'BigQuery table name');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ignoreColumns = $input->getOption('ignore-column');

        if (empty($ignoreColumns) && isset($_ENV['IGNORE_COLUMNS'])) {
            $ignoreColumns = explode(',', $_ENV['IGNORE_COLUMNS']);
        }

        $orderColumn = $input->getOption('order-column');

        if (empty($orderColumn) && isset($_ENV['ORDER_COLUMN'])) {
            $orderColumn = $_ENV['ORDER_COLUMN'];
        }

        $databaseName = $input->getOption('database-name');

        if (empty($databaseName)) {
            $databaseName = $_ENV['DB_DATABASE_NAME'];
        }

        $bigQueryTableName = $input->getOption('bigquery-table-name');

        if (empty($bigQueryTableName)) {
            $bigQueryTableName = $input->getArgument('table-name');
        }

        // Errors propagate to Symfony Console, which reports them once
        // (readable, with trace under -v) and exits with a non-zero code
        $service = $this->syncService
            ?? \DI\ContainerBuilder::buildDevContainer()->get(SyncService::class);

        $service->execute(
            $databaseName,
            $input->getArgument('table-name'),
            $bigQueryTableName,
            $input->getOption('create-table'),
            $input->getOption('delete-table'),
            $orderColumn,
            $ignoreColumns,
            $output,
            (bool) $input->getOption('no-data'),
            (bool) $input->getOption('un-buffer')
        );

        return Command::SUCCESS;
    }
}
