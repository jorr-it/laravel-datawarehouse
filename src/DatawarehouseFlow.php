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
use JorrIt\LaravelDatawarehouse\Enum\DuplicateHandling;
use JorrIt\LaravelDatawarehouse\Enum\ScdType;
use function Flow\ETL\Adapter\Doctrine\{mysql_insert_options, to_dbal_table_insert};
use function Flow\ETL\DSL\data_frame;
use function Flow\ETL\DSL\integer_entry;
use function Flow\ETL\DSL\string_entry;

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

    #region FROM

    public function fromLaravelQuery(\Illuminate\Database\Query\Builder $query) : DbalQueryExtractor
    {
        return $this->fromSqlQuery($query->getConnection(), $query->toSql(), $query->getBindings());
    }  

    public function fromDoctrineQuery(\Doctrine\ORM\Query $query) : DbalQueryExtractor
    {
        return $this->fromSqlQuery($query->getEntityManager()->getConnection(), $query->getSQL(), $query->getParameters()->toArray());
    }  
    
    public function fromSqlQuery(\Doctrine\DBAL\Connection|\Illuminate\Database\Connection $connection, string $query, array $parameters = [], ?int $batchSize = null, ?string $batchByField = "id") : DbalQueryExtractor
    {
        if ($connection instanceof \Illuminate\Database\Connection) {
            $connection = $this->getDoctrineConnection($connection);
        }
        
        $params = array();

        if ($batchSize > 0) 
        {
            $queryCount = preg_replace('/\bselect\b\s+.*?\s+\bfrom\b/is', "SELECT MIN({$batchByField}) as min_id, MAX({$batchByField}) as max_id FROM", $query, 1);
            $stats = $connection->fetchAssociative($queryCount);
            $minId = (int)$stats['min_id'];
            $maxId = (int)$stats['max_id'];

            $query .= stripos($query, 'WHERE') !== false 
                ? " AND {$batchByField} BETWEEN :start AND :end ORDER BY {$batchByField}" 
                : " WHERE {$batchByField} BETWEEN :start AND :end ORDER BY {$batchByField}";

            $params = [];

            for ($start = $minId; $start <= $maxId; $start += $batchSize) {
                $params[] = [
                    ...$parameters,
                    'start' => $start, 
                    'end'   => $start + $batchSize - 1
                ];
            }
        }
        else {
            $params = count($parameters) ? [$parameters] : [];
        }
        
        $extractor = new DbalQueryExtractor($connection, $query);

        return count($params) ? $extractor->withParameters(new ParametersSet(...$params)) : $extractor;
    } 

    public function fromTable(\Doctrine\DBAL\Connection|\Illuminate\Database\Connection $connection, string $table, array|string $fields = "*", ?int $batchSize = null, ?string $batchByField = "id") : DbalQueryExtractor
    {
        $fields = is_array($fields) ? implode(", ", $fields) : $fields;
        
        $query = "SELECT $fields FROM $table";

        return $this->fromSqlQuery($connection, $query, [], $batchSize, $batchByField);
    }

    #endregion

    #region BULK
    
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

    #endregion

    #region MAP

    /**
     * @param array<class-string<DimensionModel>, array> $dimensionCache dimensionName => [ source id => dimension key ]
     */
    private static array $dimensionCache = [];

    /**
     * Used to map a source field to a dimension
     * @param class-string<DimensionModel> $dimensionName
     * @param string $sourceField which source field holds the key that will be used to find dimension to flow to
     * @param string|null $dimensionField mostly the name of the natural key, null for autodetecting
     * @return (callable(Row):Row)
     */
    public static function mapDimension(string $dimensionName, string $sourceField, ?string $foreignKey = null, ?string $dimensionMatchField = null, bool $withCaching = true) : callable
    {
        return static function (Row $row) use ($dimensionName, $sourceField, $dimensionMatchField, $foreignKey, $withCaching) : Row
        {
            $reflection = new \ReflectionClass($dimensionName);
            $dummy = $dimensionName::newModelInstance();
            $keyName = $dummy->getKeyName();
            $foreignKey ??= Str::snake($reflection->getShortName()) . '_' . $keyName;
            $dimensionMatchField ??= $dummy->getNaturalKeyName();

            if ($withCaching) {
                // lazy load on first request
                if (!array_key_exists($dimensionName, self::$dimensionCache)) {
                    self::$dimensionCache[$dimensionName] = $dimensionName::query()->current()->pluck($keyName, $dimensionMatchField)->all();
                }
                $foreignKeyValue = self::$dimensionCache[$dimensionName][$row->valueOf($sourceField)] ?? null;
            }
            else {
                $foreignKeyValue = $dimensionName::query()->current()->where($dimensionMatchField, $row->valueOf($sourceField))->pluck($keyName)->first();
            }

            return $row->remove($sourceField)->add(integer_entry($foreignKey, $foreignKeyValue));
        };
    }

    /**
     * @param array<string, class-string<DimensionModel>> $dimensions sourceField => dimensionName
     */
    public static function mapDimensions(array $dimensions, bool $withCaching = true) : callable
    {
        return static function (Row $row) use ($dimensions, $withCaching) : Row
        {
            return array_reduce(
                array_keys($dimensions),
                static function (Row $carry, string $sourceField) use ($dimensions, $withCaching) : Row {
                    $map = self::mapDimension($dimensions[$sourceField], $sourceField, withCaching: $withCaching);
                    return $map($carry);
                },
                $row
            );
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
            if ($dimension?->getScdType()->hasHistory()) {
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
            if ($dimension?->getScdType()->hasPrevious()) { 
                foreach ($dimension->attributes_previous as $attr) {
                    $attr_prev = $attr.'_previous';
                    $dimension->$attr_prev = $dimension->$attr;
                }
                $dimension->saveQuietly();
            }
            return $row;
        };
    } 

    /**
     * Maps a generated row hash to the desired field
     * @param class-string<DatawarehouseModel> $modelName
     * @return (callable(Row):Row)
     */
    public static function mapRowHash(string $modelName, string $fieldName = 'row_hash') : callable
    {
        return static function (Row $row) use ($modelName, $fieldName) : Row
        {
            /** @var DatawarehouseModel */
            $dummy = $modelName::newModelInstance($row->toArray());
            $rowHash = $dummy->generateRowHash();
            return $row->add(string_entry($fieldName, $rowHash));
        };
    }    

    #endregion

    /**
     * Only works with MySQL and with SCD types 0, 1 and 2
     * 
     * @param class-string<DatawarehouseModel> $model
     * @param ?DuplicateHandling $duplicateHandling Null for autodetect. Duplicates are detected by unique indexes, which are automatically generated by Blueprint's asDimension method.
     */
    public function upsert(string $model, ?DuplicateHandling $duplicateHandling = null) : DbalLoader
    {
        $dummy = $model::newModelInstance();
        $table = $dummy->getTable();

        $scdType = $dummy instanceof DimensionModel ? $dummy->getScdType() : null;

        if ($scdType >= 3) {  
            throw new \Exception("SCD types 3, 4 and 6 are not yet implemented for use with Flow upsert, use Eloquent save() instead");
        }

        if ($duplicateHandling == null) {
            $duplicateHandling = match ($scdType) {
                ScdType::RETAIN => DuplicateHandling::IGNORE,
                default => DuplicateHandling::UPDATE,
            };
        }

        return to_dbal_table_insert($this->conn, $table, mysql_insert_options(
            skip_conflicts: $duplicateHandling == DuplicateHandling::IGNORE, // INSERT IGNORE in SQL
            upsert: $duplicateHandling == DuplicateHandling::UPDATE)); // ON DUPLICATE KEY UPDATE in SQL
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