<?php

namespace JorrIt\LaravelDatawarehouse\Eloquent;

use Illuminate\Support\Carbon;
use JorrIt\LaravelDatawarehouse\Enum\ScdType;

/**
 * @property-read string $naturalKeyType Do not change
 * @property-read ScdType $scdType Do not change
 */
abstract class DateDimensionModel extends DimensionModel
{
    protected string $naturalKeyType = 'date';
    protected ScdType $scdType = ScdType::RETAIN;

    protected $casts = [
        'date' => 'datetime',
    ];

    /**
     * @param ?Carbon $tillDate Today when null
     * @param ?Carbon $fromDate Day after max existing (or today when null)
     */
    public static function seed(?Carbon $tillDate = null, ?Carbon $fromDate = null)
    {
        $tillDate ??= Carbon::now();
        $fromDate ??= Carbon::now();
        $fromDate = max(static::max('id')?->addDay(), $fromDate);
        $values = [];
        $current = $fromDate->copy();

        while ($current->lte($tillDate)) {
            $values[] = ['id' => $current->copy()];
            $current->addDay();
        }

        static::insert($values);
    }
}
