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
     */
    protected function closeHistory(string $table, array|string $joinFields = "id") : bool
    {
        if (is_string($joinFields)) $joinFields = explode(",", $joinFields);
        $joinFields = array_map('trim', $joinFields);        
        $joinFields = array_map(fn($keyField) => "a.{$keyField} <=> b.{$keyField}", $joinFields);
        $joinOn = implode(" AND ", $joinFields);

        return $this->conn->statement("UPDATE {$table} old
            INNER JOIN (
                SELECT 
                    a.key, 
                    MIN(b.start_at) AS next_start_at
                FROM {$table} a
                INNER JOIN {$table} b ON {$joinOn} AND
                    a.start_at < b.start_at AND
                    a.current=1 AND b.current=1
                GROUP BY a.key
            ) exact_next ON old.key = exact_next.key
            SET old.current = 0, old.end_at = exact_next.next_start_at");  
    }
}