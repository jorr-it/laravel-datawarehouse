<?php

namespace JorrIt\LaravelDatawarehouse;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JorrIt\LaravelDatawarehouse\Eloquent\DimensionModel;
use JorrIt\LaravelDatawarehouse\Enum\ScdType;

class DatawarehouseBlueprint extends Blueprint
{
    /**
     * @inheritDoc override to specify 'key' as the default identifier column name
     */
    public function id($column = "key")
    {
        return $this->bigIncrements($column);
    }

    public function rowHash()
    {
        return $this->char('row_hash', 32)->unique('ldwh_rowhash_unique');
    }

    /**
     * Add start_at, end_at, current, row_hash to the table.
     *
     * @return Collection<int, \Illuminate\Database\Schema\ColumnDefinition>
     */
    public function versioning() : Collection
    {
        return new Collection([
            $this->timestamp('start_at')->useCurrent(),
            $this->timestamp('end_at')->nullable(),
            $this->index(['start_at', 'end_at']),
            $this->boolean('current')->default(true),
            $this->rowHash(),
            $this->index(['start_at', 'end_at']),
            $this->index(['current'])
        ]);
    }    

    /**
     * Creates dimension table(s) for the chosen SCD type.
     * SCD type 4 creates two tables at once.
     * 
     * @param array $attributes_previous for SCD type 3 and 6 only; which attributes to give previous fields
     */
    public function asDimension(ScdType|int $scdType, string $naturalKey = 'id', string $naturalKeyType = 'bigInteger', array $attributes_previous = []) : Collection
    {
        if (is_int($scdType)) {
            $scdType = ScdType::from($scdType);
        }

        if ($naturalKey == 'key') {
            throw new \Exception('Key name "key" is reservated for the surrogate key');
        }
        
        if (!str_starts_with($this->table, 'dim')) {
            $this->rename("dim{$scdType->value}_{$this->table}");
        }
        
        $fields = new Collection();

        // Key fields (natural and surrogate)
        $parameters = [];
        if (stripos($naturalKeyType, "integer") !== false) $parameters['unsigned'] = true;
        if ($naturalKeyType == 'string')                   $parameters['length'] = 255;
        if ($scdType->hasUniqueNaturalKey())               $parameters['unique'] = true;
        $fields->add($this->addColumn($naturalKeyType, $naturalKey, $parameters));
        $fields->add($this->id('key')); 

        // Create previous attributes
        if ($scdType->hasPrevious()) 
        {
            foreach ($attributes_previous as $attr) {
                $originalColumn = collect($this->getColumns())->firstWhere('name', $attr);
                if (!$originalColumn) throw new \Exception('Attribute ' .$attr . ' does not exist');
                $fields->add($this->addColumn($originalColumn['type'], $attr . '_previous', $originalColumn['parameters'] ?? []));
            }
        }

        if ($scdType == ScdType::HISTORY_TABLE) 
        {
            // Make a copy of the current table blueprint
            $this->connection->getSchemaBuilder()->create($this->table, function(DatawarehouseBlueprint $table) use ($naturalKey) {
                foreach ($this->getColumns() as $column) {
                    $table->addColumn($column['type'], $column['name'], $column['parameters'] ?? []);
                }
                $table->unique($naturalKey, 'ldwh_naturalkey_unique');
            });

            // Let's treat the current blueprint as the history table
            $this->rename($this->table . '_history');
        }

        // Create attributes to define validity
        if ($scdType->hasHistory()) 
        {
            $fields = $fields->merge($this->versioning());
        }
    
        return $fields;
    }

    public function asDateDimension(string $naturalDateKey = 'id') : Collection
    {
        return $this->asDimension(ScdType::RETAIN, $naturalDateKey, 'date');
    }

    /**
     * Creates dimension table(s) as specified by a class that extends DimensionModel.
     * Also validates chosen table name.
     * SCD type 4 creates two tables at once.
     * 
     * @param class-string<DimensionModel> $modelName 
     */
    public function asDimensionByModel(string $modelName): Collection 
    {
        $reflectionClass = new \ReflectionClass($modelName);
        
        $scdType = $reflectionClass->getProperty('scdType')->getDefaultValue();
        $naturalKeyType = $reflectionClass->getProperty('naturalKeyType')->getDefaultValue();
        $naturalKey = $reflectionClass->getProperty('naturalKey')->getDefaultValue();
        $attributes_previous = $reflectionClass->getProperty('attributes_previous')->getDefaultValue();

        $dimTableName = 'dim' . $scdType->value . '_' . Str::snake($reflectionClass->getShortName());
        if ($this->getTable() != $dimTableName)
            throw new \Exception('Table name not valid');

        return $this->asDimension($scdType, $naturalKey, $naturalKeyType, $attributes_previous);
    }

    /**
     * Creates foreign key from fact table or dimension table to generic/parental dimension table
     * 
     * @param string $dimensionTablename should start with dim?_...
     * @param string $fieldName should end with _key or leave null for autogeneration
     * @param bool $nullable discouraged, but might be used when fact table contains aggregations of this dimension
     */
    public function toDimension(string $dimensionTablename, ?string $fieldName = null, bool $nullable = false) : Collection
    {
        $fieldName ??= Str::snake(substr($dimensionTablename, 5)).'_key';

        $fields = new Collection();
         
        $fields->add($this->unsignedBigInteger($fieldName)
            ->nullable($nullable));

        $fields->add($this->foreign($fieldName)
            ->references('key')->on($dimensionTablename)
            ->onDelete('cascade'));

        return $fields;
    }

    /**
     * Creates foreign key from fact table or dimension table to generic/parental dimension table
     * 
     * @param class-string<DimensionModel> $modelName 
     * @param string $fieldName should end with _key or leave null for autogeneration
     * @param bool $nullable discouraged, but might be used when fact table contains aggregations of this dimension
     */
    public function toDimensionByModel(string $modelName, ?string $fieldName = null, bool $nullable = false): Collection 
    {
        $reflectionClass = new \ReflectionClass($modelName);
        
        $scdType = $reflectionClass->getProperty('scdType')->getDefaultValue();
        $dimensionTablename = 'dim' . $scdType->value . '_' . Str::snake($reflectionClass->getShortName());        

        return $this->toDimension($dimensionTablename, $fieldName, $nullable);
    }

    /**
     * Creates foreign key from a base/detailed fact table to an aggregated fact table
     * 
     * @param string $aggregatedFactTablename should start with fact_...
     * @param string $fieldName should end with _key or leave null for autogeneration
     */
    public function toAggregatedFact(string $aggregatedFactTablename, ?string $fieldName = null) : Collection
    {
        $fieldName ??= substr($aggregatedFactTablename, 5).'_key';

        $fields = new Collection();
         
        $fields->add($this->unsignedBigInteger($fieldName));

        $fields->add($this->foreign($fieldName)
            ->references('key')->on($aggregatedFactTablename)
            ->onDelete('cascade'));

        return $fields;
    }    
}
