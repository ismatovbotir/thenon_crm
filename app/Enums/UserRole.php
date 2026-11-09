<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('crm.roles.admin'),
            self::Operator => __('crm.roles.operator'),
        };
    }
}
