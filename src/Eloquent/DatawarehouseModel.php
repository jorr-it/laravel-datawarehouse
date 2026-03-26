<?php

namespace JorrIt\LaravelDatawarehouse\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property-read string $primaryKey while this is mutable in bare Eloquent, it is not in laravel-datawarehouse
 * @property int $key
 * @property string $row_hash
 */
class DatawarehouseModel extends Model
{
    /** @var string */
    protected $primaryKey = 'key';
  
    protected $guarded = ['key', 'row_hash', 'created_at', 'updated_at'];

    /**
     * Creates a unique md5 string based on the model attribute values.
     * 
     * @return string md5 char(32)
     */
    public function generateRowHash() : string
    {
        $notUniqueAttributes = $this->guarded;

        if ($this instanceof DimensionModel) {
            // 1. NaturalKeyName has its own unique index if needed
            // 2. 'Current' field should not be excluded
            $notUniqueAttributes = [...$notUniqueAttributes, $this->getNaturalKeyName(), 'start_at', 'end_at'];
        }

        $values = collect($this->getAttributes())
            ->except($notUniqueAttributes)
            ->sortKeys()
            ->values()
            ->all();

        $rawString = json_encode($values, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        $this->row_hash = md5($rawString);
        return $this->row_hash;
    }
}