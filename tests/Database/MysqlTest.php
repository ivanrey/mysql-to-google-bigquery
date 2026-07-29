<?php
namespace MysqlToGoogleBigQuery\Tests\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use MysqlToGoogleBigQuery\Database\Mysql;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MysqlTest extends TestCase
{
    private AbstractSchemaManager&MockObject $schemaManager;

    /**
     * Mysql with its connection stubbed out: the schema manager below stands
     * in for the ~4 information_schema queries introspectTable() runs.
     */
    private function mysql(): Mysql
    {
        $this->schemaManager = $this->createMock(AbstractSchemaManager::class);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->schemaManager);

        $mysql = $this->getMockBuilder(Mysql::class)
            ->onlyMethods(['getConnection'])
            ->getMock();
        $mysql->method('getConnection')->willReturn($connection);

        return $mysql;
    }

    /**
     * Table double whose getColumns() returns one Column per given name
     */
    private function tableWithColumns(string ...$names): Table&MockObject
    {
        $columns = [];
        foreach ($names as $name) {
            $columns[$name] = $this->createMock(Column::class);
        }

        $table = $this->createMock(Table::class);
        $table->method('getColumns')->willReturn($columns);

        return $table;
    }

    public function testTheSchemaIsIntrospectedOnlyOncePerTable(): void
    {
        $mysql = $this->mysql();

        // A sync asks for the same schema once to validate and once per batch
        $this->schemaManager->expects($this->once())
            ->method('introspectTable')
            ->with('users')
            ->willReturn($this->tableWithColumns('id', 'created_at'));

        $first = $mysql->getTableColumns('mydb', 'users');
        $second = $mysql->getTableColumns('mydb', 'users');

        $this->assertSame($first, $second);
        $this->assertSame(['id', 'created_at'], array_keys($first));
    }

    public function testEachTableGetsItsOwnColumns(): void
    {
        $mysql = $this->mysql();

        $this->schemaManager->expects($this->exactly(2))
            ->method('introspectTable')
            ->willReturnCallback(fn (string $table) => $this->tableWithColumns(
                ...($table === 'users' ? ['id', 'name'] : ['id', 'total'])
            ));

        $this->assertSame(['id', 'name'], array_keys($mysql->getTableColumns('mydb', 'users')));
        $this->assertSame(['id', 'total'], array_keys($mysql->getTableColumns('mydb', 'orders')));

        // Both stay cached afterwards
        $mysql->getTableColumns('mydb', 'users');
        $mysql->getTableColumns('mydb', 'orders');
    }

    public function testTheSameTableInAnotherDatabaseIsIntrospectedAgain(): void
    {
        $mysql = $this->mysql();

        // The cache key is "<database>.<table>": same name, different schema
        $this->schemaManager->expects($this->exactly(2))
            ->method('introspectTable')
            ->willReturn($this->tableWithColumns('id'));

        $mysql->getTableColumns('client_a', 'users');
        $mysql->getTableColumns('client_b', 'users');
    }
}
