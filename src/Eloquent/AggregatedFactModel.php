<?php

namespace JorrIt\LaravelDatawarehouse\Eloquent;

class AggregatedFactModel extends FactModel
{
    /**
     * @param class-string<FactModel> $factClass
     */
    public function hasGranularFacts(string $factClass)
    {
        return parent::hasMany($factClass);
    }
}