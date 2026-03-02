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

    protected function truncate(string $table, bool $ignoreForeignKeyConstraintsOnEmptyTable = true): void
    {
        // If table is empty, don't check foreign key constraints
        $contraintsTouched = false;
        if ($ignoreForeignKeyConstraintsOnEmptyTable) {
            $count = $this->conn->table($table)->count();
            if ($count == 0) {
                $this->schema->disableForeignKeyConstraints();
                $contraintsTouched = true;
            }
        }

        $this->conn->table($table)->truncate();

        if ($contraintsTouched) {
            $this->schema->enableForeignKeyConstraints();
        }
    }    
}