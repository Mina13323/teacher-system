<?php

namespace App\Enums;

enum StudentCapabilityPreset: string
{
    case All = 'ALL';
    case LessonsOnly = 'LESSONS_ONLY';
    case ExamsOnly = 'EXAMS_ONLY';
    case CompetitionsOnly = 'COMPETITIONS_ONLY';
    case None = 'NONE';
    case Custom = 'CUSTOM';

    /**
     * @return array{can_access_lessons: bool, can_take_exams: bool, can_join_competitions: bool}|null
     */
    public function capabilities(): ?array
    {
        return match ($this) {
            self::All => [
                'can_access_lessons' => true,
                'can_take_exams' => true,
                'can_join_competitions' => true,
            ],
            self::LessonsOnly => [
                'can_access_lessons' => true,
                'can_take_exams' => false,
                'can_join_competitions' => false,
            ],
            self::ExamsOnly => [
                'can_access_lessons' => false,
                'can_take_exams' => true,
                'can_join_competitions' => false,
            ],
            self::CompetitionsOnly => [
                'can_access_lessons' => false,
                'can_take_exams' => false,
                'can_join_competitions' => true,
            ],
            self::None => [
                'can_access_lessons' => false,
                'can_take_exams' => false,
                'can_join_competitions' => false,
            ],
            self::Custom => null,
        };
    }

    public static function fromCapabilities(bool $lessons, bool $exams, bool $competitions): self
    {
        if ($lessons && $exams && $competitions) {
            return self::All;
        }

        if ($lessons && ! $exams && ! $competitions) {
            return self::LessonsOnly;
        }

        if (! $lessons && $exams && ! $competitions) {
            return self::ExamsOnly;
        }

        if (! $lessons && ! $exams && $competitions) {
            return self::CompetitionsOnly;
        }

        if (! $lessons && ! $exams && ! $competitions) {
            return self::None;
        }

        return self::Custom;
    }
}
