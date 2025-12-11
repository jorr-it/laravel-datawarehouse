<?php

namespace JorrIt\LaravelDatawarehouse\Enum;

enum ScdType: int
{
    case RETAIN = 0; 
    case OVERWRITE = 1; 
    case HISTORY_ROWS = 2; 
    case HISTORY_ATTRIBUTE = 3; 
    case HISTORY_TABLE = 4; // 1 & 2
    case HYBRID = 6; // 1+2+3

    public function hasOverwrite(): bool
    {
        return in_array($this->value, [1, 3, 4, 6]) || $this->hasPrevious();
    }

    public function hasHistory(): bool
    {
        return in_array($this->value, [2, 4, 6]);
    }    

    public function hasPrevious(): bool
    {
        return in_array($this->value, [3, 6]);
    }
    
    public function hasUniqueNaturalKey(): bool
    {
        return in_array($this->value, [0, 1, 3]);
    }        
}

