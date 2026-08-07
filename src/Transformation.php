<?php

namespace JorrIt\LaravelDatawarehouse;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Capsule\Manager as Capsule;

abstract class Transformation
{
    /**
     * Run the transformation.
     */
    abstract public function up(): void;

    /**
     * Reverse the transformation.
     */
    abstract public function down(): void;

    protected Connection $conn;
    protected Builder $schema;
    protected DatawarehouseFlow $flow;

    public function __construct()
    {
        $this->conn = Capsule::connection();
        $this->schema = $this->conn->getSchemaBuilder();
        $this->schema->blueprintResolver(fn($connection, $table, $callback) => new DatawarehouseBlueprint($connection, $table, $callback));
        $this->flow = new DatawarehouseFlow($this->conn);
    }

    protected function seed(string $table, array $records): void
    {
        $records = array_map(fn($record) => is_array($record) ? $record : ['id' => $record], $records);

        $this->conn->table($table)->upsert($records, "id");
    }

    protected function truncate(string $table, bool $disableForeignKeyConstraints = true): void
    {
        if ($disableForeignKeyConstraints) {
            $this->schema->disableForeignKeyConstraints();
        }

        $this->conn->table($table)->truncate();

        if ($disableForeignKeyConstraints) {
            $this->schema->enableForeignKeyConstraints();
        }
    }
    
    /**
     * Sets current to false and end_at to date for old records
     * 
     * TODO: Move as static function to DimensionModel
     * TODO: Currently only works for MariaDB
     */
    protected function closeHistory(string $table, array|string $partitionBy = "id") : bool
    {
        $cols = is_array($partitionBy) ? $partitionBy : explode(',', $partitionBy);
        $partitionSql = implode(', ', array_map(fn($f) => '`' . trim($f) . '`', $cols));

        return $this->conn->statement("
            UPDATE {$table} old
            INNER JOIN (
                SELECT 
                    `key`,
                    `current`,
                    `start_at`,
                    LEAD(start_at) OVER (
                        PARTITION BY {$partitionSql} 
                        ORDER BY `start_at` ASC
                    ) AS next_start_at
                FROM {$table}
                WHERE `current` = 1
            ) sub ON old.`key` = sub.`key`
            SET 
                old.`current` = 0, 
                old.`end_at` = sub.next_start_at
            WHERE sub.next_start_at IS NOT NULL
        ");   
    }
}