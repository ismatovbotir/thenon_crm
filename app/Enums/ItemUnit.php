<?php

namespace App\Enums;

enum ItemUnit: string
{
    case Pcs = 'pcs';
    case Pack = 'pack';

    public function label(): string
    {
        return __('crm.items.units.'.$this->value);
    }
}
