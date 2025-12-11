<?php

namespace JorrIt\LaravelDatawarehouse;

use Flow\Doctrine\Bulk\{Bulk, BulkData, InsertOptions, UpdateOptions};
use Flow\ETL\Adapter\Doctrine\DbalLoader;
use Flow\ETL\Adapter\Doctrine\DbalQueryExtractor;
use Flow\ETL\Flow;
use JorrIt\LaravelDatawarehouse\Eloquent\DatawarehouseModel;
use function Flow\ETL\Adapter\Doctrine\{dbal_from_query, to_dbal_table_insert, to_dbal_table_update, to_dbal_table_delete};
use function Flow\ETL\DSL\data_frame;

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
     * @param class-string<DatawarehouseModel> $model
     */
    public function toInsert(string $model, ?InsertOptions $options = null) : DbalLoader
    {
        $table = $model::newModelInstance()->getTable();
        return to_dbal_table_insert($this->conn, $table, $options);
    }

    /**
     * @param class-string<DatawarehouseModel> $model
     */    
    public function toUpdate(string $model, ?UpdateOptions $options = null) : DbalLoader
    {
        $table = $model::newModelInstance()->getTable();
        return to_dbal_table_update($this->conn, $table, $options);
    }

    /**
     * @param class-string<DatawarehouseModel> $model
     */
    public function toDelete(string $model) : DbalLoader
    {
        $table = $model::newModelInstance()->getTable();
        return to_dbal_table_delete($this->conn, $table);
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
        
        return dbal_from_query($connection, $query, $parameters);
    } 

    public function fromTable(\Doctrine\DBAL\Connection|\Illuminate\Database\Connection $connection, string $table) : DbalQueryExtractor
    {
        return $this->fromSqlQuery($connection, "select * from " . $table);
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

    private function getDoctrineConnection(\Illuminate\Database\Connection $conn) : \Doctrine\DBAL\Connection
    {
        $config = $conn->getConfig();
        $params = [
            'driver'   => 'pdo_'.$config['driver'],
            'dbname'   => $config['database'],
            'pdo'      => $conn->getPdo(), 
        ];

        return \Doctrine\DBAL\DriverManager::getConnection($params);
    }
}