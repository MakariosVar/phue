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
                sprintf(
                    'Model class "%s" must be a subclass of "%s".',
                    $modelClass,
                    BaseActiveRecord::class
                )
            );
        }

        /** @var BaseActiveRecord $instance */
        $instance = new $modelClass();
        $table = $instance->getTable();

        $pdo = $this->connection->getPdo();
        $driverName = strtolower(
            $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) ?? 'sqlite'
        );

        SqlIdentifier::quote($table, $driverName);

        $modelSchema = $this->schemaRegistry->get($modelClass);

        foreach (array_keys($modelSchema->fields) as $fieldName) {
            SqlIdentifier::quote((string) $fieldName, $driverName);
        }

        $operations = [];

        /*
         * Table does not exist.
         *
         * Create the table/columns first and then create indexes as
         * separate operations.
         */
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
                    autoIncrement:
                        $firstFieldSchema->name === 'id'
                        && $firstFieldSchema->type === 'int',
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
                    continue;
                }

                $column = new ColumnDefinition(
                    name: $fieldSchema->name,
                    type: $fieldSchema->type,
                    nullable: !$fieldSchema->required,
                    primaryKey: $fieldSchema->name === 'id',
                    autoIncrement:
                        $fieldSchema->name === 'id'
                        && $fieldSchema->type === 'int',
                    indexed: (bool) ($fieldSchema->indexed ?? false),
                );

                $operations[] = [
                    'op' => SchemaOperation::AddColumn,
                    'column' => $column,
                ];
            }

            /*
             * Indexes must be separate operations.
             *
             * The id column is excluded because the PRIMARY KEY already
             * provides its index.
             */
            foreach ($fieldItems as $fieldSchema) {
                if (
                    $fieldSchema->name !== 'id'
                    && (bool) ($fieldSchema->indexed ?? false)
                ) {
                    $operations[] = [
                        'op' => SchemaOperation::CreateIndex,
                        'column' => new ColumnDefinition(
                            name: $fieldSchema->name,
                            type: $fieldSchema->type,
                            nullable: !$fieldSchema->required,
                            primaryKey: false,
                            autoIncrement: false,
                            indexed: true,
                        ),
                    ];
                }
            }

            return new SchemaDiff(
                table: $table,
                operations: $operations,
                baseColumns: [],
            );
        }

        /*
         * Table exists: inspect existing columns.
         */
        $existingColumns = $this->inspector->inspect($table);

        /*
         * Check for new columns or altered columns.
         */
        foreach ($modelSchema->fields as $fieldName => $fieldSchema) {
            $targetCol = new ColumnDefinition(
                name: $fieldSchema->name,
                type: $fieldSchema->type,
                nullable: !$fieldSchema->required,
                primaryKey: $fieldSchema->name === 'id',
                autoIncrement:
                    $fieldSchema->name === 'id'
                    && $fieldSchema->type === 'int',
                indexed: (bool) ($fieldSchema->indexed ?? false),
            );

            if (!isset($existingColumns[$fieldName])) {
                $operations[] = [
                    'op' => SchemaOperation::AddColumn,
                    'column' => $targetCol,
                ];

                /*
                 * Index creation is separate from AddColumn.
                 */
                if (
                    $targetCol->indexed
                    && $targetCol->name !== 'id'
                ) {
                    $operations[] = [
                        'op' => SchemaOperation::CreateIndex,
                        'column' => $targetCol,
                    ];
                }

                continue;
            }

            $existingCol = $existingColumns[$fieldName];

            /*
             * indexed is deliberately NOT considered a column change.
             *
             * Index changes are represented by CreateIndex / DropIndex
             * operations below.
             */
            if ($this->isColumnChanged($existingCol, $targetCol)) {
                $operations[] = [
                    'op' => SchemaOperation::AlterColumn,
                    'column' => $existingCol,
                    'new' => $targetCol,
                ];
            }

            /*
             * Index was added.
             *
             * Never create a separate index for the primary-key id.
             */
            if (
                $targetCol->name !== 'id'
                && $targetCol->indexed
                && !$existingCol->indexed
            ) {
                $operations[] = [
                    'op' => SchemaOperation::CreateIndex,
                    'column' => $targetCol,
                ];
            }

            /*
             * Index was removed.
             *
             * Never drop the PK index for id.
             */
            if (
                $targetCol->name !== 'id'
                && !$targetCol->indexed
                && $existingCol->indexed
            ) {
                $operations[] = [
                    'op' => SchemaOperation::DropIndex,
                    'column' => $existingCol,
                ];
            }
        }

        /*
         * Check for dropped columns.
         */
        $modelFieldNames = array_keys($modelSchema->fields);

        foreach ($existingColumns as $colName => $existingCol) {
            if (!in_array($colName, $modelFieldNames, true)) {
                $operations[] = [
                    'op' => SchemaOperation::DropColumn,
                    'column' => $existingCol,
                ];
            }
        }

        return new SchemaDiff(
            table: $table,
            operations: $operations,
            baseColumns: $existingColumns,
        );
    }

    /**
     * Apply schema diff in a database transaction.
     *
     * @param class-string $modelClass
     * @param bool $dryRun
     * @param bool $allowDrops
     *
     * @return array<int, string>
     */
    public function apply(
        string $modelClass,
        bool $dryRun = true,
        bool $allowDrops = false
    ): array {
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

        $driver = strtolower(
            $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) ?? ''
        );

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
                sprintf(
                    'Failed to apply schema diff for model "%s": %s',
                    $modelClass,
                    $e->getMessage()
                ),
                0,
                $e
            );
        }

        return $sqlStatements;
    }

    /**
     * Compare an existing column definition with a target column definition.
     *
     * Index state is intentionally excluded. Indexes are handled separately
     * through CreateIndex / DropIndex operations.
     */
    private function isColumnChanged(
        ColumnDefinition $existing,
        ColumnDefinition $target
    ): bool {
        return $existing->type !== $target->type
            || $existing->nullable !== $target->nullable
            || $existing->primaryKey !== $target->primaryKey
            || $existing->autoIncrement !== $target->autoIncrement;
    }
}