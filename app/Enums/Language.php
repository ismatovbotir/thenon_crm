<?php

namespace App\Enums;

enum Language: string
{
    case Uz = 'uz';
    case Ru = 'ru';

    public function label(): string
    {
        return match ($this) {
            self::Uz => 'O‘zbekcha',
            self::Ru => 'Русский',
        };
    }
}
