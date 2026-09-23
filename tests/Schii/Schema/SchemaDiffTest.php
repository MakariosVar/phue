<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Schema;

use PHPUnit\Framework\TestCase;
use Phue\Modules\Schii\Schema\ColumnDefinition;
use Phue\Modules\Schii\Schema\SchemaDiff;
use Phue\Modules\Schii\Schema\SchemaOperation;

/**
 * SchemaDiff: operation bookkeeping and per-dialect SQL generation.
 *
 * @package Phue\Tests\Schii\Schema
 */
#[\PHPUnit\Framework\Attributes\Group('unit')]
final class SchemaDiffTest extends TestCase
{
    private function column(string $name = 'name', string $type = 'text', bool $nullable = true): ColumnDefinition
    {
        return new ColumnDefinition(name: $name, type: $type, nullable: $nullable);
    }

    public function testEmptyDiffIsEmptyAndHasNoDrops(): void
    {
        $diff = new SchemaDiff('users');

        $this->assertTrue($diff->isEmpty());
        $this->assertFalse($diff->hasDrops());
        $this->assertSame([], $diff->toSqls('mysql'));
    }

    public function testCreateTableGeneratesMysqlSql(): void
    {
        $diff = new SchemaDiff('users', [
            ['op' => SchemaOperation::CreateTable, 'column' => $this->column()],
        ]);

        $sql = $diff->toSqls('mysql');
        $this->assertCount(1, $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `users`', $sql[0]);
        $this->assertStringContainsString('`name` LONGTEXT', $sql[0]);
    }

    public function testAddColumnGeneratesSqliteSql(): void
    {
        $diff = new SchemaDiff('users', [
            ['op' => SchemaOperation::AddColumn, 'column' => $this->column('age', 'int')],
        ]);

        $sql = $diff->toSqls('sqlite');
        $this->assertCount(1, $sql);
        $this->assertSame('ALTER TABLE "users" ADD COLUMN "age" INTEGER NULL', $sql[0]);
    }

    public function testPostgresAlterColumnEmitsTypeCast(): void
    {
        $diff = new SchemaDiff('users', [
            [
                'op' => SchemaOperation::AlterColumn,
                'column' => $this->column('name'),
                'new' => $this->column('name', 'text', false),
            ],
        ]);

        $sql = $diff->toSqls('pgsql');
        $this->assertCount(1, $sql);
        $this->assertSame('ALTER TABLE "users" ALTER COLUMN "name" TYPE TEXT', $sql[0]);
    }

    public function testMysqlAlterColumnUsesModifyColumn(): void
    {
        $diff = new SchemaDiff('users', [
            [
                'op' => SchemaOperation::AlterColumn,
                'column' => $this->column('name'),
                'new' => $this->column('name', 'text', false),
            ],
        ]);

        $sql = $diff->toSqls('mysql');
        $this->assertCount(1, $sql);
        $this->assertStringContainsString('ALTER TABLE `users` MODIFY COLUMN `name`', $sql[0]);
    }

    public function testDropColumnDetectedByHasDrops(): void
    {
        $diff = new SchemaDiff('users', [
            ['op' => SchemaOperation::DropColumn, 'column' => $this->column('legacy')],
        ]);

        $this->assertTrue($diff->hasDrops());
        $this->assertFalse($diff->isEmpty());

        $sql = $diff->toSqls('mysql');
        $this->assertSame('ALTER TABLE `users` DROP COLUMN `legacy`', $sql[0]);
    }

    public function testSqliteRebuildEmittedForAlterColumn(): void
    {
        $diff = new SchemaDiff('users', [
            [
                'op' => SchemaOperation::AlterColumn,
                'column' => $this->column('name'),
                'new' => $this->column('name', 'text', false),
            ],
        ]);

        $sql = $diff->toSqls('sqlite');
        $this->assertCount(4, $sql);
        $this->assertStringContainsString('CREATE TABLE', $sql[0]);
        $this->assertStringContainsString('INSERT INTO', $sql[1]);
        $this->assertStringContainsString('DROP TABLE', $sql[2]);
        $this->assertStringContainsString('RENAME TO', $sql[3]);
    }

    public function testSqliteRebuildEmittedForDropColumn(): void
    {
        $diff = new SchemaDiff('users', [
            ['op' => SchemaOperation::DropColumn, 'column' => $this->column('legacy')],
        ]);

        $sql = $diff->toSqls('sqlite');
        $this->assertCount(4, $sql);
    }

    public function testUnknownDriverFallsBackToVarcharAndBareIdentifier(): void
    {
        $diff = new SchemaDiff('users', [
            ['op' => SchemaOperation::AddColumn, 'column' => $this->column('email')],
        ]);

        $sql = $diff->toSqls('weird');
        $this->assertSame('ALTER TABLE users ADD COLUMN email VARCHAR NULL', $sql[0]);
    }

    public function testCreateTableWithPrimaryKeyAndAutoIncrement(): void
    {
        $id = new ColumnDefinition(name: 'id', type: 'int', nullable: false, primaryKey: true, autoIncrement: true);

        $diff = new SchemaDiff('users', [
            ['op' => SchemaOperation::CreateTable, 'column' => $id],
        ]);

        $mysql = $diff->toSqls('mysql')[0];
        $this->assertStringContainsString('`id` INT PRIMARY KEY AUTO_INCREMENT NOT NULL', $mysql);

        $sqlite = $diff->toSqls('sqlite')[0];
        $this->assertStringContainsString('"id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL', $sqlite);
    }

    public function testMixedOperationsGenerateDedupedSqlList(): void
    {
        $diff = new SchemaDiff('users', [
            ['op' => SchemaOperation::CreateTable, 'column' => $this->column()],
            ['op' => SchemaOperation::AddColumn, 'column' => $this->column('email')],
        ]);

        $sql = $diff->toSqls('mysql');
        $this->assertCount(2, $sql);
    }

    public function testCreateIndexEmitsMysqlSql(): void
    {
        $diff = new SchemaDiff('users', [
            ['op' => SchemaOperation::CreateIndex, 'column' => new ColumnDefinition(name: 'email', type: 'text', indexed: true)],
        ]);

        $sql = $diff->toSqls('mysql');
        $this->assertCount(1, $sql);
        $this->assertSame('CREATE INDEX `idx_users_email` ON `users` (`email`)', $sql[0]);
    }

    public function testCreateIndexEmitsSqliteSql(): void
    {
        $diff = new SchemaDiff('users', [
            ['op' => SchemaOperation::CreateIndex, 'column' => new ColumnDefinition(name: 'email', type: 'text', indexed: true)],
        ]);

        $sql = $diff->toSqls('sqlite');
        $this->assertCount(1, $sql);
        $this->assertSame('CREATE INDEX IF NOT EXISTS "idx_users_email" ON "users" ("email")', $sql[0]);
    }

    public function testCreateIndexEmitsPgsqlSql(): void
    {
        $diff = new SchemaDiff('users', [
            ['op' => SchemaOperation::CreateIndex, 'column' => new ColumnDefinition(name: 'email', type: 'text', indexed: true)],
        ]);

        $sql = $diff->toSqls('pgsql');
        $this->assertCount(1, $sql);
        $this->assertSame('CREATE INDEX IF NOT EXISTS "idx_users_email" ON "users" ("email")', $sql[0]);
    }

    public function testDropIndexEmitsMysqlSql(): void
    {
        $diff = new SchemaDiff('users', [
            ['op' => SchemaOperation::DropIndex, 'column' => new ColumnDefinition(name: 'email', type: 'text', indexed: true)],
        ]);

        $sql = $diff->toSqls('mysql');
        $this->assertCount(1, $sql);
        $this->assertSame('DROP INDEX `idx_users_email` ON `users`', $sql[0]);
    }

    public function testDropIndexEmitsSqliteSql(): void
    {
        $diff = new SchemaDiff('users', [
            ['op' => SchemaOperation::DropIndex, 'column' => new ColumnDefinition(name: 'email', type: 'text', indexed: true)],
        ]);

        $sql = $diff->toSqls('sqlite');
        $this->assertCount(1, $sql);
        $this->assertSame('DROP INDEX "idx_users_email"', $sql[0]);
    }

    public function testDropIndexEmitsPgsqlSql(): void
    {
        $diff = new SchemaDiff('users', [
            ['op' => SchemaOperation::DropIndex, 'column' => new ColumnDefinition(name: 'email', type: 'text', indexed: true)],
        ]);

        $sql = $diff->toSqls('pgsql');
        $this->assertCount(1, $sql);
        $this->assertSame('DROP INDEX IF EXISTS "idx_users_email"', $sql[0]);
    }

    public function testIndexOnlyDiffDoesNotTriggerSqliteRebuild(): void
    {
        $diff = new SchemaDiff('users', [
            ['op' => SchemaOperation::CreateIndex, 'column' => new ColumnDefinition(name: 'email', type: 'text', indexed: true)],
        ]);

        $sql = $diff->toSqls('sqlite');
        $this->assertCount(1, $sql);
        $this->assertStringContainsString('CREATE INDEX', $sql[0]);
        $this->assertStringNotContainsString('RENAME TO', $sql[0]);
    }

    public function testIndexNameIsDerDeterministicFromTableAndColumn(): void
    {
        $diff = new SchemaDiff('order_items', [
            ['op' => SchemaOperation::CreateIndex, 'column' => new ColumnDefinition(name: 'parent_id', type: 'int', indexed: true)],
        ]);

        $sql = $diff->toSqls('pgsql');
        $this->assertSame('CREATE INDEX IF NOT EXISTS "idx_order_items_parent_id" ON "order_items" ("parent_id")', $sql[0]);
    }

    public function testSqliteRebuildRecreatesIndexesForIndexedColumns(): void
    {
        $diff = new SchemaDiff('users', [
            [
                'op' => SchemaOperation::AlterColumn,
                'column' => new ColumnDefinition(name: 'email', type: 'text', indexed: true),
                'new' => new ColumnDefinition(name: 'email', type: 'text', nullable: false, indexed: true),
            ],
        ], baseColumns: ['email' => new ColumnDefinition(name: 'email', type: 'text', indexed: true)]);

        $sql = $diff->toSqls('sqlite');
        $this->assertCount(5, $sql);

        $script = implode("\n", $sql);
        $this->assertStringContainsString('CREATE TABLE', $script);
        $this->assertStringContainsString('CREATE INDEX IF NOT EXISTS "idx_users_email" ON "users" ("email")', $script);
    }

    public function testSqliteRebuildHonorsDropIndexOnUnchangedColumn(): void
    {
        $diff = new SchemaDiff('users', [
            [
                'op' => SchemaOperation::DropIndex,
                'column' => new ColumnDefinition(name: 'email', type: 'text', indexed: true),
            ],
            [
                'op' => SchemaOperation::AlterColumn,
                'column' => new ColumnDefinition(name: 'name', type: 'text'),
                'new' => new ColumnDefinition(name: 'name', type: 'text', nullable: false),
            ],
        ], baseColumns: [
            'email' => new ColumnDefinition(name: 'email', type: 'text', indexed: true),
            'name' => new ColumnDefinition(name: 'name', type: 'text'),
        ]);

        $sql = $diff->toSqls('sqlite');

        $script = implode("\n", $sql);
        $this->assertStringNotContainsString(
            'CREATE INDEX IF NOT EXISTS "idx_users_email"',
            $script,
            'A dropped index must not be re-created by a table rebuild triggered by an unrelated column.'
        );
    }

    public function testDiffColumnCarriesIndexedFlag(): void
    {
        $column = new ColumnDefinition(name: 'email', type: 'text', indexed: true);

        $this->assertTrue($column->indexed);
        $this->assertFalse((new ColumnDefinition(name: 'name', type: 'text'))->indexed);
    }

    public function testBoolTypeMapsToBooleanPerDriver(): void
    {
        $diff = new SchemaDiff('users', [
            ['op' => SchemaOperation::AddColumn, 'column' => $this->column('active', 'boolean')],
        ]);

        $this->assertStringContainsString('"active" INTEGER', $diff->toSqls('sqlite')[0]);
        $this->assertStringContainsString('`active` TINYINT(1)', $diff->toSqls('mysql')[0]);
        $this->assertStringContainsString('"active" BOOLEAN', $diff->toSqls('pgsql')[0]);
    }
}