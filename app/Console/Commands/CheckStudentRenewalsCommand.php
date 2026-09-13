<?php

namespace App\Console\Commands;

use App\Enums\StudentAccessStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudentAccessPeriod;
use App\Models\User;
use App\Notifications\StudentRenewalDueNotification;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class CheckStudentRenewalsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'students:check-renewals';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check students for overdue monthly access renewals, transition status, and notify authorized staff.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Scanning student access periods for overdue renewals...');

        $overduePeriods = StudentAccessPeriod::query()
            ->where('status', StudentAccessStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with(['student.createdBy'])
            ->get();

        $processedCount = 0;
        $notifiedCount = 0;

        foreach ($overduePeriods as $period) {
            $student = $period->student;
            if (! $student) {
                continue;
            }

            // Transition status to Due
            $period->status = StudentAccessStatus::Due;
            $period->save();
            $processedCount++;

            // Collect staff recipients (teachers & authorized assistants)
            $recipients = collect();

            if ($student->createdBy && ($student->createdBy->isTeacher() || $student->createdBy->isAdmin())) {
                $recipients->push($student->createdBy);
            }

            // Teachers owning courses this student is enrolled in
            $courseIds = Enrollment::query()
                ->where('student_id', $student->getKey())
                ->pluck('course_id');

            if ($courseIds->isNotEmpty()) {
                $teacherIds = Course::query()
                    ->whereIn('id', $courseIds)
                    ->pluck('created_by');

                $teachers = User::query()->whereIn('id', $teacherIds)->get();
                $recipients = $recipients->merge($teachers);
            }

            // Assistants with permission or role to manage students
            $assistants = User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'assistant'))
                ->get();
            $recipients = $recipients->merge($assistants);

            // Deduplicate recipients
            $recipients = $recipients->unique('id');

            foreach ($recipients as $recipient) {
                // Idempotency check: ensure recipient does not already have an unread notification for this student
                $exists = DatabaseNotification::query()
                    ->where('notifiable_type', User::class)
                    ->where('notifiable_id', $recipient->getKey())
                    ->whereNull('read_at')
                    ->where('type', StudentRenewalDueNotification::class)
                    ->whereJsonContains('data->student_id', $student->getKey())
                    ->exists();

                if (! $exists) {
                    $recipient->notify(new StudentRenewalDueNotification($student, $period->expires_at));
                    $notifiedCount++;
                }
            }
        }

        $this->info("Completed renewal check. Processed: {$processedCount} overdue access periods. Notifications dispatched: {$notifiedCount}.");

        return self::SUCCESS;
    }
}
