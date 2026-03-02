<?php

namespace JorrIt\LaravelDatawarehouse\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property-read string $primaryKey while this is mutable in bare Eloquent, it is not in laravel-datawarehouse
 * @property int $key
 */
class DatawarehouseModel extends Model
{
    /** @var string */
    protected $primaryKey = 'key';
}