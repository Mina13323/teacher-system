<?php

namespace App\Http\Controllers\Teacher;

use App\Enums\ExamAttemptStatus;
use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamAnswer;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The essay-grading workspace's data.
 *
 * The queue lists essay answers of HANDED-IN attempts only (submitted,
 * grading, published; never in progress, never strictly expired), one row per
 * frozen essay question of each attempt. A blank essay (no answer row) is a
 * row of its own and reads ungraded; points_earned is NULL until a teacher
 * enters a grade, never 0 by default. Question text and maximum come from the
 * attempt's frozen snapshot. Everything is scoped to the exam in the URL, so
 * attempt/student filters cannot reach another exam's answers.
 *
 * Built as joined queries (paginated before anything is materialized) so the
 * query count does not grow with the page size, and it carries no MCQ, option
 * or other answer payload.
 */
class EssayGradingController extends Controller
{
    private const ESSAY_QUEUE_MAX_PER_PAGE = 50;

    public function essayAnswers(Request $request, Exam $exam): JsonResponse
    {
        $this->authorize('viewAttempts', $exam);

        $filters = $request->validate([
            'question_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'attempt_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'student_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', Rule::in(['ungraded', 'graded', 'all'])],
            'publication_status' => ['sometimes', 'nullable', Rule::in(['unpublished', 'published', 'all'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'order' => ['sometimes', 'nullable', Rule::in(['question', 'student'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::ESSAY_QUEUE_MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
            'include_summary' => ['sometimes', 'boolean'],
        ]);

        $page = $this->filtered($this->base($exam), $filters)
            ->join('users as u', 'u.id', '=', 'a.student_id')
            ->select([
                'aq.attempt_id', 'aq.question_id', 'aq.question_text', 'aq.points', 'aq.position',
                'a.attempt_number', 'a.status as attempt_status', 'a.grades_published_at', 'a.student_id',
                'u.name as student_name', 'u.student_code',
                'ans.id as answer_id', 'ans.answer_text', 'ans.points_earned', 'ans.feedback',
                'ans.graded_by', 'ans.graded_at',
            ])
            ->orderBy('u.name')
            ->orderBy('a.student_id')
            ->orderBy('a.attempt_number')
            ->orderBy('a.id')
            ->orderBy('aq.position')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        $rows = collect($page->items())->map(fn ($row) => $this->row($row))->values();

        $payload = [
            'answers' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];

        if ($request->boolean('include_summary', true)) {
            $payload['summary'] = $this->summary($exam);
        }

        return $this->success(array_merge(['exam' => ['id' => $exam->id, 'title' => $exam->title]], $payload), 'Essay answers retrieved.');
    }

    /** Essay questions of handed-in attempts of this exam, with their answer row if any. */
    private function base(Exam $exam): Builder
    {
        return DB::table('exam_attempt_questions as aq')
            ->join('exam_attempts as a', 'a.id', '=', 'aq.attempt_id')
            ->leftJoin('exam_answers as ans', function ($join) {
                $join->on('ans.attempt_id', '=', 'aq.attempt_id')
                    ->on('ans.question_id', '=', 'aq.question_id');
            })
            ->where('a.exam_id', $exam->getKey())
            ->whereNull('a.deleted_at')
            ->whereIn('a.status', ExamAttemptStatus::submittedValues())
            ->where('aq.question_type', QuestionType::Essay->value);
    }

    /** @param  array<string, mixed>  $filters */
    private function filtered(Builder $query, array $filters): Builder
    {
        if (! empty($filters['question_id'])) {
            $query->where('aq.question_id', (int) $filters['question_id']);
        }
        if (! empty($filters['attempt_id'])) {
            $query->where('aq.attempt_id', (int) $filters['attempt_id']);
        }
        if (! empty($filters['student_id'])) {
            $query->where('a.student_id', (int) $filters['student_id']);
        }

        match ($filters['status'] ?? 'all') {
            'ungraded' => $query->whereNull('ans.points_earned'),
            'graded' => $query->whereNotNull('ans.points_earned'),
            default => null,
        };

        match ($filters['publication_status'] ?? 'all') {
            'unpublished' => $query->whereNull('a.grades_published_at'),
            'published' => $query->whereNotNull('a.grades_published_at'),
            default => null,
        };

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->whereExists(function ($sub) use ($like) {
                $sub->selectRaw('1')
                    ->from('users as su')
                    ->whereColumn('su.id', 'a.student_id')
                    ->where(fn ($w) => $w->where('su.name', 'like', $like)->orWhere('su.student_code', 'like', $like));
            });
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function row(object $row): array
    {
        $answer = $row->answer_id === null ? null : (new ExamAnswer)->newFromBuilder([
            'points_earned' => $row->points_earned,
            'feedback' => $row->feedback,
            'graded_by' => $row->graded_by,
            'graded_at' => $row->graded_at,
        ]);

        $points = $answer?->points_earned;

        return [
            'attempt_id' => (int) $row->attempt_id,
            'attempt_number' => (int) $row->attempt_number,
            'attempt_status' => $row->attempt_status,
            'grades_published' => $row->grades_published_at !== null,
            'question_id' => (int) $row->question_id,
            'question_text' => $row->question_text,
            'max_points' => (int) $row->points,
            'position' => (int) $row->position,
            'student' => [
                'id' => (int) $row->student_id,
                'name' => $row->student_name,
                'student_code' => $row->student_code,
            ],
            'has_answer' => $answer !== null && trim((string) $row->answer_text) !== '',
            'answer_text' => $row->answer_text,
            'points_earned' => $points !== null ? (int) $points : null,
            'feedback' => $answer?->feedback === '' ? null : $answer?->feedback,
            'graded_at' => $answer?->graded_at?->toISOString(),
            'grade_version' => ExamAnswer::gradeVersion($answer),
            'grading_status' => $points === null ? 'ungraded' : 'graded',
        ];
    }

    /**
     * Exam-wide counts (independent of the page filters): per essay question,
     * and per attempt publication state. Two aggregate queries.
     *
     * @return array<string, mixed>
     */
    private function summary(Exam $exam): array
    {
        $questions = $this->base($exam)
            ->leftJoin('questions as q', 'q.id', '=', 'aq.question_id')
            ->groupBy('aq.question_id')
            ->orderByRaw('MIN(aq.position)')
            ->selectRaw('aq.question_id, MAX(aq.question_text) as question_text, MAX(aq.points) as max_points, MIN(aq.position) as position,
                MAX(q.reference_answer) as reference_answer,
                SUM(CASE WHEN ans.points_earned IS NULL THEN 1 ELSE 0 END) as ungraded,
                SUM(CASE WHEN ans.points_earned IS NOT NULL THEN 1 ELSE 0 END) as graded,
                SUM(CASE WHEN ans.points_earned = 0 THEN 1 ELSE 0 END) as graded_zero')
            ->get()
            ->map(fn ($q) => [
                'question_id' => (int) $q->question_id,
                'question_text' => $q->question_text,
                'max_points' => (int) $q->max_points,
                'position' => (int) $q->position,
                'reference_answer' => $q->reference_answer,
                'ungraded' => (int) $q->ungraded,
                'graded' => (int) $q->graded,
                'graded_zero' => (int) $q->graded_zero,
            ])
            ->values();

        $attempts = DB::table('exam_attempts')
            ->where('exam_id', $exam->getKey())
            ->whereNull('deleted_at')
            ->whereIn('status', ExamAttemptStatus::submittedValues())
            ->selectRaw("
                SUM(CASE WHEN grades_published_at IS NULL AND status = 'submitted' THEN 1 ELSE 0 END) as ready_to_publish,
                SUM(CASE WHEN grades_published_at IS NULL AND status = 'grading' THEN 1 ELSE 0 END) as awaiting_grading,
                SUM(CASE WHEN grades_published_at IS NOT NULL THEN 1 ELSE 0 END) as published")
            ->first();

        return [
            'questions' => $questions,
            'ungraded' => $questions->sum('ungraded'),
            'graded' => $questions->sum('graded'),
            'attempts' => [
                'ready_to_publish' => (int) ($attempts->ready_to_publish ?? 0),
                'awaiting_grading' => (int) ($attempts->awaiting_grading ?? 0),
                'published' => (int) ($attempts->published ?? 0),
            ],
        ];
    }
}
