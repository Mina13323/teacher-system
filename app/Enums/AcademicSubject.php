<?php

namespace App\Enums;

enum AcademicSubject: string
{
    case History = 'history';
    case Geography = 'geography';
    case Both = 'both';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::History => 'History (التاريخ)',
            self::Geography => 'Geography (الجغرافيا)',
            self::Both => 'Both (التاريخ والجغرافيا)',
            self::General => 'General (عام)',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::History => 'History',
            self::Geography => 'Geography',
            self::Both => 'History & Geography',
            self::General => 'General',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::History => 'التاريخ',
            self::Geography => 'الجغرافيا',
            self::Both => 'التاريخ والجغرافيا',
            self::General => 'عام',
        };
    }

    /**
     * Options list for Third Secondary (secondary_3).
     *
     * @return array<int, array{value: string, label: string, label_ar: string, label_en: string}>
     */
    public static function thirdSecondaryOptions(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'label_ar' => $case->labelAr(),
            'label_en' => $case->labelEn(),
        ], [self::History, self::Geography, self::Both]);
    }

    /**
     * All options array format for API resources/selects.
     *
     * @return array<int, array{value: string, label: string, label_ar: string, label_en: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'label_ar' => $case->labelAr(),
            'label_en' => $case->labelEn(),
        ], self::cases());
    }
}
