<?php

namespace Tests\Unit;

use App\Enums\CompetitionRankingType;
use App\Enums\CompetitionScoringType;
use App\Enums\CompetitionStatus;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class CompetitionDomainTest extends TestCase
{
    public function test_competition_status_defaults_to_draft(): void
    {
        $this->assertSame(CompetitionStatus::Draft, CompetitionStatus::default());
    }

    public function test_scoring_type_defaults_to_highest_score(): void
    {
        $this->assertSame(CompetitionScoringType::HighestScore, CompetitionScoringType::default());
    }

    public function test_ranking_type_defaults_to_score_desc(): void
    {
        $this->assertSame(CompetitionRankingType::ScoreDesc, CompetitionRankingType::default());
    }

    public function test_lifecycle_only_allows_valid_linear_transitions(): void
    {
        $this->assertTrue(CompetitionStatus::Draft->canTransitionTo(CompetitionStatus::Published));
        $this->assertTrue(CompetitionStatus::Published->canTransitionTo(CompetitionStatus::Active));
        $this->assertTrue(CompetitionStatus::Active->canTransitionTo(CompetitionStatus::Ended));
        $this->assertTrue(CompetitionStatus::Ended->canTransitionTo(CompetitionStatus::Archived));

        $this->assertFalse(CompetitionStatus::Archived->canTransitionTo(CompetitionStatus::Active));
        $this->assertFalse(CompetitionStatus::Archived->canTransitionTo(CompetitionStatus::Draft));
        $this->assertFalse(CompetitionStatus::Ended->canTransitionTo(CompetitionStatus::Active));
        $this->assertFalse(CompetitionStatus::Draft->canTransitionTo(CompetitionStatus::Ended));
        $this->assertFalse(CompetitionStatus::Active->canTransitionTo(CompetitionStatus::Published));
    }

    public function test_retirement_transitions_are_explicitly_allowed(): void
    {
        // A competition that has not started may be retired directly to archived.
        $this->assertTrue(CompetitionStatus::Draft->canTransitionTo(CompetitionStatus::Archived));
        $this->assertTrue(CompetitionStatus::Published->canTransitionTo(CompetitionStatus::Archived));

        // An active competition must end before archiving.
        $this->assertFalse(CompetitionStatus::Active->canTransitionTo(CompetitionStatus::Archived));
    }

    public function test_public_display_name_never_exposes_email_or_internal_id(): void
    {
        $user = new User(['name' => 'Mina Walid', 'email' => 'mina.walid@example.com']);
        $user->id = 42;

        $display = $user->publicDisplayName();

        $this->assertSame('Mina W.', $display);
        $this->assertStringNotContainsString('@example.com', $display);
        $this->assertStringNotContainsString('42', $display);
    }

    public function test_public_display_name_handles_single_name(): void
    {
        $user = new User(['name' => 'Kareem']);
        $this->assertSame('Kareem', $user->publicDisplayName());
    }
}
