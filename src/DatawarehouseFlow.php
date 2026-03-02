<?php

namespace JorrIt\LaravelDatawarehouse;

use Flow\Doctrine\Bulk\{Bulk, BulkData, InsertOptions, UpdateOptions};
use Flow\ETL\Adapter\Doctrine\DbalLoader;
use Flow\ETL\Adapter\Doctrine\DbalQueryExtractor;
use Flow\ETL\Adapter\Doctrine\ParametersSet;
use Flow\ETL\Flow;
use Flow\ETL\Row;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JorrIt\LaravelDatawarehouse\Eloquent\DatawarehouseModel;
use JorrIt\LaravelDatawarehouse\Eloquent\DimensionModel;
use function Flow\ETL\Adapter\Doctrine\{dbal_from_query, mysql_insert_options, to_dbal_table_insert, to_dbal_table_update, to_dbal_table_delete};
use function Flow\ETL\DSL\data_frame;
use function Flow\ETL\DSL\integer_entry;

/**
 * @see: https://flow-php.com/
 */
class DatawarehouseFlow
{
    protected \Doctrine\DBAL\Connection $conn;
    public Flow $dataFrame;
    public Bulk $bulk;
    
    public function __construct(\Illuminate\Database\Connection $conn)
    {
        $this->conn = $this->getDoctrineConnection($conn);
        $this->dataFrame = data_frame();
        $this->bulk = Bulk::create();
    }

    /**
     * TODO: This method also updates with scdType RETAIN
     * @param class-string<DatawarehouseModel> $model
     */
    public function upsert(string $model) : DbalLoader
    {
        $dummy = $model::newModelInstance();
        $table = $dummy->getTable();
        return to_dbal_table_insert($this->conn, $table); /*, mysql_insert_options(
            skip_conflicts: $dummy->scdType == ScdType::RETAIN,
            upsert: $dummy->scdType == ScdType::OVERWRITE));

            The options parameter above would be perfect, but doesnt work.
            The result is that ScdType::RETAIN also updates.
            */
    }

    public function fromLaravelQuery(\Illuminate\Database\Query\Builder $query) : DbalQueryExtractor
    {
        return $this->fromSqlQuery($query->getConnection(), $query->toSql(), $query->getBindings());
    }  

    public function fromDoctrineQuery(\Doctrine\ORM\Query $query) : DbalQueryExtractor
    {
        return $this->fromSqlQuery($query->getEntityManager()->getConnection(), $query->getSQL(), $query->getParameters()->toArray());
    }  
    
    public function fromSqlQuery(\Doctrine\DBAL\Connection|\Illuminate\Database\Connection $connection, string $query, array $parameters = []) : DbalQueryExtractor
    {
        if ($connection instanceof \Illuminate\Database\Connection) {
            $connection = $this->getDoctrineConnection($connection);
        }
        
        // return dbal_from_query($connection, $query, $parameters);
        
        $extractor = new DbalQueryExtractor($connection, $query);

        return count($parameters) ? $extractor->withParameters(new ParametersSet(...$parameters)) : $extractor;
    } 

    public function fromTable(\Doctrine\DBAL\Connection|\Illuminate\Database\Connection $connection, string $table, array|string $fields = "*", ?int $batchSize = null, ?string $batchByField = "id") : DbalQueryExtractor
    {
        $fields = is_array($fields) ? implode(", ", $fields) : $fields;
        
        $query = "SELECT $fields FROM $table";
        $params = array();

        if ($batchSize > 0) 
        {
            $query .= " WHERE {$batchByField} BETWEEN :start AND :end ORDER BY {$batchByField}";
            
            $stats = $connection->fetchAssociative("SELECT MIN({$batchByField}) as min_id, MAX({$batchByField}) as max_id FROM {$table}");
            $minId = (int)$stats['min_id'];
            $maxId = (int)$stats['max_id'];
            $params = [];

            for ($start = $minId; $start <= $maxId; $start += $batchSize) {
                $params[] = [
                    'start' => $start, 
                    'end'   => $start + $batchSize - 1
                ];
            }
        }
        
        return $this->fromSqlQuery($connection, $query, $params);
    }
    
    public function bulkInsert(DatawarehouseModel $model, BulkData $bulkData, ?InsertOptions $options = null) : void
    {
        $this->bulk->insert($this->conn, $model->getTable(), $bulkData, $options);
    }

    public function bulkUpdate(DatawarehouseModel $model, BulkData $bulkData, ?UpdateOptions $options = null) : void
    {
        $this->bulk->update($this->conn, $model->getTable(), $bulkData, $options);
    }

    public function bulkDelete(DatawarehouseModel $model, BulkData $bulkData) : void
    {
        $this->bulk->delete($this->conn, $model->getTable(), $bulkData);
    }

    /**
     * Used to map a source field to a dimension
     * @param class-string<DimensionModel> $dimensionName
     * @param string $sourceField which source field holds the key that will be used to find dimension to flow to
     * @param string|null $dimensionField mostly the name of the natural key, null for autodetecting
     * @return (callable(Row):Row)
     */
    public static function mapDimension(string $dimensionName, string $sourceField, ?string $foreignKey = null, ?string $dimensionMatchField = null) : callable
    {
        return static function (Row $row) use ($dimensionName, $sourceField, $dimensionMatchField, $foreignKey) : Row
        {
            /** @var DimensionModel */
            $dimension = ($dimensionMatchField
                ? $dimensionName::query()->current()->where($dimensionMatchField, $row->valueOf($sourceField))->first()
                : $dimensionName::query()->current()->findByNatural($row->valueOf($sourceField))->first())
                ?? new $dimensionName(); // if query gives null, then create a dummy object for reflection

            $reflection = new \ReflectionClass($dimension);
            $foreignKey ??= Str::snake($reflection->getShortName()) . '_' . $dimension->getKeyName();

            return $row->remove($sourceField)->add(integer_entry($foreignKey, $dimension->getKey()));
        };
    }

    /**
     * Used to archive old dimension records using current field
     * @param class-string<DimensionModel> $dimensionName
     * @param string $sourceField which source field holds the natural key that will be used to find dimension
     * @return (callable(Row):Row)
     */
    public static function mapHistory(string $dimensionName, string $sourceField) : callable
    {
        return static function (Row $row) use ($dimensionName, $sourceField) : Row
        {
            /** @var DimensionModel */
            $dimension = $dimensionName::query()->current()->findByNatural($row->valueOf($sourceField))->first();
            if ($dimension?->scdType->hasHistory()) {
                $dimension->current = false;
                $dimension->end_at = Carbon::now();
                $dimension->saveQuietly();
            }
            return $row;
        };
    }
    
    /**
     * Used to archive old dimension records using previous fields
     * @param class-string<DimensionModel> $dimensionName
     * @param string $sourceField which source field holds the natural key that will be used to find dimension
     * @return (callable(Row):Row)
     */
    public static function mapPrevious(string $dimensionName, string $sourceField) : callable
    {
        return static function (Row $row) use ($dimensionName, $sourceField) : Row
        {
            /** @var DimensionModel */
            $dimension = $dimensionName::query()->current()->findByNatural($row->valueOf($sourceField))->first(); 
            if ($dimension?->scdType->hasPrevious()) { 
                foreach ($dimension->attributes_previous as $attr) {
                    $attr_prev = $attr.'_previous';
                    $dimension->$attr_prev = $dimension->$attr;
                }
                $dimension->saveQuietly();
            }
            return $row;
        };
    } 

    private function getDoctrineConnection(\Illuminate\Database\Connection $conn) : \Doctrine\DBAL\Connection
    {
        $config = $conn->getConfig();
        $config['pdo'] = $conn->getPdo();
        $config['driver'] = 'pdo_'.$config['driver'];
        $this->renameKey($config, 'database', 'dbname');
        $this->renameKey($config, 'username', 'user');

        return \Doctrine\DBAL\DriverManager::getConnection($config);
    }

    private function renameKey(array &$arr, string $oldKey, string $newKey) : array
    {
        if (array_key_exists($oldKey, $arr)) {
            $arr[$newKey] = $arr[$oldKey];
            unset($arr[$oldKey]);
        }
        else {
            $arr[$newKey] = null;
        }
        return $arr;
    }
}