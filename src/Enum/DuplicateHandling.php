<?php

namespace JorrIt\LaravelDatawarehouse\Enum;

enum DuplicateHandling: int
{
    case IGNORE = 0; 
    case UPDATE = 1; 
    case ERROR = 2;    
}

