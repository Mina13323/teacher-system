<?php

namespace App\Enums;

/**
 * Supported question types.
 */
enum QuestionType: string
{
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case Essay = 'essay';

    public static function default(): self
    {
        return self::SingleChoice;
    }

    public function isMcq(): bool
    {
        return in_array($this, [self::SingleChoice, self::MultipleChoice], true);
    }

    public function isEssay(): bool
    {
        return $this === self::Essay;
    }

    public function label(): string
    {
        return match ($this) {
            self::SingleChoice => 'Single Choice (اختيار من متعدد)',
            self::MultipleChoice => 'Multiple Choice (متعدد الخيارات)',
            self::Essay => 'Essay (سؤال مقالي)',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::SingleChoice => 'Single Choice',
            self::MultipleChoice => 'Multiple Choice',
            self::Essay => 'Essay',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::SingleChoice => 'اختيار من متعدد',
            self::MultipleChoice => 'متعدد الخيارات',
            self::Essay => 'سؤال مقالي',
        };
    }
}
