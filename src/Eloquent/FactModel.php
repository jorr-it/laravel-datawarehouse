<?php

namespace JorrIt\LaravelDatawarehouse\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use JorrIt\LaravelDatawarehouse\Enum\AggregateFunction;
use Illuminate\Database\Eloquent\Builder;

class FactModel extends DatawarehouseModel
{
    /**
     * In the key of each array item describe the fact field name.
     * In the value of each array item choose which aggregate function should be applied 
     * or describe the raw sql select statement for the field.
     * 
     * @var array<string, AggregateFunction|string> 
     */
    protected array $facts = [];

    // override
    public function getTable() : string
    {
        $factTableName = 'fact_' . Str::snake(class_basename($this));
        return $this->table ?? $factTableName;
    }    

    /**
     * @param class-string<DimensionModel> $dimensionClass
     * @return BelongsTo<DimensionModel>
     */
    public function toDimension(string $dimensionClass)
    {
        return parent::belongsTo($dimensionClass);
    }

    /**
     * @param class-string<AggregatedFactModel> $aggregatedFactClass
     * @return BelongsTo<AggregatedFactModel>
     */
    public function toAggregatedFact(string $aggregatedFactClass)
    {
        return parent::belongsTo($aggregatedFactClass);
    } 

    #[Scope]
    protected function selectFacts(Builder $query) : void
    {
        foreach ($this->facts as $fieldname => $sql) 
        {
            if ($sql instanceof AggregateFunction) 
                $sql = $sql->value;

            if (stripos($sql, '(') !== false)
                $sql = $sql . '(' . $fieldname . ')';           
            
            if (stripos($sql, ' as ') !== false)
                $sql .= ' as ' . $fieldname;

            $query->selectRaw($sql);
        }
    }    

    protected static function booted(): void
    {
        static::created(fn($factModel) => $factModel->aggregate());
        static::updated(fn($factModel) => $factModel->aggregate());
        static::deleted(fn($factModel) => $factModel->aggregate());
    }  
    
    public function aggregate() 
    {
        /**
         * This should be done a) async and b) unified for the same aggregated fact
         * Laravel Jobs/Queue would be ideal, but how to implement outside Laravel main installation?
         * Maybe: https://masnun.com/using-laravel-queues-standalone-outside-laravel/
         */
    }

    /**
     * @return array<string, DimensionModel> key contains relation name
     */
    public function getDimensions(): array
    {
        return $this->getBelongToRelations(DimensionModel::class);
    }

    /**
     * @return array<string>
     */    
    public function getDimensionKeys(): array
    {
        return array_map(fn ($relationName) => Str::snake($relationName).'_key', array_keys($this->getDimensions()));
    }

    /**
     * @return array<string, AggregatedFactModel> key contains relation name
     */
    public function getAggregatedFacts(): array
    {
        return $this->getBelongToRelations(AggregatedFactModel::class);
    }    

    /**
     * @param class-string<DatawarehouseModel>|null $ofModel
     * @return array<string, DatawarehouseModel> key contains relation name
     */
    protected function getBelongToRelations(?string $ofModel): array
    {
        $reflection = new \ReflectionClass($this);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        $relations = [];

        /** @var \ReflectionMethod $method */
        foreach ($methods as $method) 
        {
            if ($method->getReturnType() instanceof \ReflectionNamedType && $method->getReturnType()->getName() === BelongsTo::class) 
            {
                $relationName = $method->getName();
                $relationObject = $this->$relationName; // should the relation be loaded to gte meta info?
                if ($relationObject && ($ofModel == null || is_subclass_of($relationObject, $ofModel))) {
                    $relations[$relationName] = $relationObject;
                }                
                /*
                $relationObject = $this->$relationName();
                $relationClass = get_class($relationObject->getRelated());
                if ($ofModel == null || is_subclass_of($relationClass, $ofModel)) {
                    $relations[$relationName] = $relationClass;
                }
                */
            }           
        }

        return $relations;
    }    
}