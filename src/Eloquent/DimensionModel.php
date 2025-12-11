<?php

namespace JorrIt\LaravelDatawarehouse\Eloquent;

use Illuminate\Support\Str;
use JorrIt\LaravelDatawarehouse\Enum\ScdType;

abstract class DimensionModel extends DatawarehouseModel
{
    protected string $naturalKey = 'id';
    protected string $naturalKeyType = 'bigInteger';
    protected ScdType $scdType;

    /**
     * For SCD type 3 and 6 only; which attributes have previous fields
     */
    protected array $attributes_previous = [];

    // override
    public function getTable() : string
    {
        $dimTableName = 'dim' . $this->scdType->value . '_' . Str::snake(class_basename($this));
        return $this->table ?? $dimTableName;
    }

    public function getHistoryTable() : string
    {
        return $this->getTable() . $this->scdType == ScdType::HISTORY_TABLE ? "_history" : "";
    }

    /**
     * @param class-string<FactModel> $factClass
     */
    public function hasFacts(string $factClass)
    {
        return parent::hasMany($factClass);
    }

    /**
     * @param class-string<DimensionModel> $dimensionClass
     */
    public function hasDimensions(string $dimensionClass)
    {
        return parent::hasMany($dimensionClass);
    }    

    /**
     * @inheritDoc override to specify the typing in phpdoc
     * @param class-string<DimensionModel> $dimensionClass
     */
    public function belongsToDimension(string $dimensionClass)
    {
        return parent::belongsTo($dimensionClass);
    }

    // override
    public function save(array $options = []) : bool
    {
        // To avoid infinite loop
        if (array_key_exists("ldwh_ignore", $options) || !$this->isDirty()) {
            return parent::save($options);
        }

        $saved = false;

        // If SCD type has history, always a new record should be created
        if ($this->scdType->hasHistory()) 
        {
            $ldwh_ignore = ["ldwh_ignore" => true];

            $newHistoricalRecord = $this->replicate();
            $newHistoricalRecord->current = true;
            $newHistoricalRecord->start_at ??= new \DateTime();
            $newHistoricalRecord->setTable($this->getHistoryTable());
            $saved = $newHistoricalRecord->save($ldwh_ignore);

            // Earlier historic records must be marked as outdated
            self::where('current', true)->update(['current' => false, 'end_at' => $newHistoricalRecord->start_at], $ldwh_ignore);

            // If no combination with other SCD types, we can exit the save function
            if ($this->scdType == ScdType::HISTORY_ROWS) {
                return $saved;
            }
        }
        
        // If it is an update, let's check some stuff
        if ($this->exists) 
        {
            // If overwrite is not needed, skip the save and return whether history records was saved
            if (!$this->scdType->hasOverwrite()) 
            {
                return $saved;
            }

            // Fill previous attributes
            if ($this->scdType->hasPrevious()) 
            {
                $originalAttributes = $this->getOriginal();
                foreach ($this->attributes_previous as $attribute) {
                    $previous_attribute = $attribute . '_previous';
                    $this->$previous_attribute = $originalAttributes[$attribute];
                }
            }
        }       
        
        return parent::save($options);
    }
}
