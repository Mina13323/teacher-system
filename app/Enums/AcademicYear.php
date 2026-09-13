<?php

namespace App\Enums;

enum AcademicYear: string
{
    case Secondary1 = 'secondary_1';
    case Secondary2 = 'secondary_2';
    case Secondary3 = 'secondary_3';

    public function label(): string
    {
        return match ($this) {
            self::Secondary1 => '1st Secondary (الصف الأول الثانوي)',
            self::Secondary2 => '2nd Secondary (الصف الثاني الثانوي)',
            self::Secondary3 => '3rd Secondary (الصف الثالث الثانوي)',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::Secondary1 => '1st Secondary',
            self::Secondary2 => '2nd Secondary',
            self::Secondary3 => '3rd Secondary',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Secondary1 => 'الصف الأول الثانوي',
            self::Secondary2 => 'الصف الثاني الثانوي',
            self::Secondary3 => 'الصف الثالث الثانوي',
        };
    }

    /**
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
