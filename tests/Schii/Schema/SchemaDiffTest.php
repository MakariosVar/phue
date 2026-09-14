<?php

declare(strict_types=1);

namespace Phue\Tests\Schii\Schema;

use PHPUnit\Framework\TestCase;
use Phue\Schii\Schema\ColumnDefinition;
use Phue\Schii\Schema\SchemaDiff;
use Phue\Schii\Schema\SchemaOperation;

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
}