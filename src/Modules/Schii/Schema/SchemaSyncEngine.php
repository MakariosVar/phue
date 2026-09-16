<?php

declare(strict_types=1);

namespace Phue\Modules\Schii\Schema;

use InvalidArgumentException;
use PDOException;
use Phue\Core\Database\ActiveRecord\BaseActiveRecord;
use Phue\Core\Database\Connection;
use Phue\Core\Database\SqlIdentifier;
use RuntimeException;

final class SchemaSyncEngine
{
    public function __construct(
        private readonly SchemaRegistry $schemaRegistry,
        private readonly SchemaInspector $inspector,
        private readonly Connection $connection,
    ) {}

    /**
     * Get the PDO instance from the connection.
     *
     * @return \PDO
     */
    public function getPdo(): \PDO
    {
        return $this->connection->getPdo();
    }

    /**
     * Compute schema diff between the Model attributes and the actual database table.
     * Does not modify the database.
     *
     * @param class-string $modelClass
     *
     * @return SchemaDiff
     *
     * @throws InvalidArgumentException If not a BaseActiveRecord subclass
     */
    public function diff(string $modelClass): SchemaDiff
    {
        if (!is_subclass_of($modelClass, BaseActiveRecord::class)) {
            throw new InvalidArgumentException(
                sprintf('Model class "%s" must be a subclass of "%s".', $modelClass, BaseActiveRecord::class)
            );
        }

        /** @var BaseActiveRecord $instance */
        $instance = new $modelClass();
        $table = $instance->getTable();

        $pdo = $this->connection->getPdo();
        $driverName = strtolower($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) ?? 'sqlite');
        SqlIdentifier::quote($table, $driverName);

        $modelSchema = $this->schemaRegistry->get($modelClass);
        foreach (array_keys($modelSchema->fields) as $fieldName) {
            SqlIdentifier::quote((string) $fieldName, $driverName);
        }

        $operations = [];

        // If the table does not exist, plan table creation and initial columns
        if (!$this->inspector->tableExists($table)) {
            $firstField = null;
            $fieldItems = array_values($modelSchema->fields);

            if (!empty($fieldItems)) {
                $firstFieldSchema = $fieldItems[0];
                $firstField = new ColumnDefinition(
                    name: $firstFieldSchema->name,
                    type: $firstFieldSchema->type,
                    nullable: !$firstFieldSchema->required,
                    primaryKey: $firstFieldSchema->name === 'id',
                    autoIncrement: $firstFieldSchema->name === 'id' && $firstFieldSchema->type === 'int',
                    indexed: (bool) ($firstFieldSchema->indexed ?? false),
                );
            }

            if ($firstField !== null) {
                $operations[] = [
                    'op' => SchemaOperation::CreateTable,
                    'column' => $firstField,
                ];
            }

            foreach ($fieldItems as $index => $fieldSchema) {
                if ($index === 0 && $firstField !== null) {
                    continue; // First column was handled in CreateTable
                }

                $operations[] = [
                    'op' => SchemaOperation::AddColumn,
                    'column' => new ColumnDefinition(
                        name: $fieldSchema->name,
                        type: $fieldSchema->type,
                        nullable: !$fieldSchema->required,
                        primaryKey: $fieldSchema->name === 'id',
                        autoIncrement: $fieldSchema->name === 'id' && $fieldSchema->type === 'int',
                        indexed: (bool) ($fieldSchema->indexed ?? false),
                    ),
                ];
            }

            return new SchemaDiff(table: $table, operations: $operations, baseColumns: []);
        }

        // Table exists: inspect existing columns
        $existingColumns = $this->inspector->inspect($table);

        // Check for new columns or altered columns
        foreach ($modelSchema->fields as $fieldName => $fieldSchema) {
            $targetCol = new ColumnDefinition(
                name: $fieldSchema->name,
                type: $fieldSchema->type,
                nullable: !$fieldSchema->required,
                primaryKey: $fieldSchema->name === 'id',
                autoIncrement: $fieldSchema->name === 'id' && $fieldSchema->type === 'int',
                indexed: (bool) ($fieldSchema->indexed ?? false),
            );

            if (!isset($existingColumns[$fieldName])) {
                $operations[] = [
                    'op' => SchemaOperation::AddColumn,
                    'column' => $targetCol,
                ];
            } else {
                $existingCol = $existingColumns[$fieldName];
                if ($this->isColumnChanged($existingCol, $targetCol)) {
                    $operations[] = [
                        'op' => SchemaOperation::AlterColumn,
                        'column' => $existingCol,
                        'new' => $targetCol,
                    ];
                }
            }
        }

        // Check for dropped columns
        $modelFieldNames = array_keys($modelSchema->fields);
        foreach ($existingColumns as $colName => $existingCol) {
            if (!in_array($colName, $modelFieldNames, true)) {
                $operations[] = [
                    'op' => SchemaOperation::DropColumn,
                    'column' => $existingCol,
                ];
            }
        }

        return new SchemaDiff(table: $table, operations: $operations, baseColumns: $existingColumns);
    }

    /**
     * Apply schema diff in a database transaction.
     *
     * @param class-string $modelClass
     * @param bool         $dryRun     If true, returns planned SQL statements without executing.
     * @param bool         $allowDrops Allow dropping columns only with explicit flag (default false due to potential data loss).
     *
     * @return array<int, string> Executed or planned SQL statements.
     */
    public function apply(string $modelClass, bool $dryRun = true, bool $allowDrops = false): array
    {
        $diff = $this->diff($modelClass);

        if ($diff->isEmpty()) {
            return [];
        }

        if ($diff->hasDrops() && !$allowDrops) {
            throw new RuntimeException(
                sprintf(
                    'Schema diff for table "%s" contains drop operations. Set $allowDrops to true to confirm potential data loss.',
                    $diff->table
                )
            );
        }

        $pdo = $this->connection->getPdo();
        $driver = strtolower($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) ?? '');
        $sqlStatements = $diff->toSqls($driver);

        if ($dryRun) {
            return $sqlStatements;
        }

        $pdo->beginTransaction();

        try {
            foreach ($sqlStatements as $sql) {
                $pdo->exec($sql);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException(
                sprintf('Failed to apply schema diff for model "%s": %s', $modelClass, $e->getMessage()),
                0,
                $e
            );
        }

        return $sqlStatements;
    }

    /**
     * Compare an existing column definition with a target column definition to detect modifications.
     *
     * @param ColumnDefinition $existing
     * @param ColumnDefinition $target
     *
     * @return bool
     */
    private function isColumnChanged(ColumnDefinition $existing, ColumnDefinition $target): bool
    {
        return $existing->type !== $target->type
            || $existing->nullable !== $target->nullable
            || $existing->primaryKey !== $target->primaryKey
            || $existing->autoIncrement !== $target->autoIncrement;
    }
}