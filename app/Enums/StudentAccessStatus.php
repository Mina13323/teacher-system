<?php

namespace App\Enums;

enum StudentAccessStatus: string
{
    case Active = 'active';
    case Due = 'due';
    case PendingReview = 'pending_review';
    case Expired = 'expired';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Due => 'Due for Renewal',
            self::PendingReview => 'Pending Review',
            self::Expired => 'Expired',
            self::Suspended => 'Suspended',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Due => 'مستحق التجديد',
            self::PendingReview => 'قيد المراجعة',
            self::Expired => 'منتهي',
            self::Suspended => 'معلق / موقوف',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function isOverdue(): bool
    {
        return in_array($this, [self::Due, self::Expired, self::Suspended], true);
    }
}
