<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    case Accepted = 'accepted';
    case Done = 'done';
    case Sent = 'sent';
    case Delivered = 'delivered';

    public function label(): string
    {
        return __('order.status.'.$this->value);
    }

    public function isEditableByEmployee(): bool
    {
        return $this === self::Draft;
    }
}
