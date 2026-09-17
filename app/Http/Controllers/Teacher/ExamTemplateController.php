<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Exam\ApplyExamTemplateAction;
use App\Actions\Exam\SaveExamTemplateFromExamAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApplyExamTemplateRequest;
use App\Http\Requests\StoreExamTemplateRequest;
use App\Http\Resources\ExamTemplateResource;
use App\Models\Exam;
use App\Models\ExamTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reusable exam structures.
 *
 * A template is the seeded catalog plus whatever the caller has built for
 * themselves. Templates carry no questions and no answers — only counts and
 * marks — so listing them exposes nothing sensitive.
 */
class ExamTemplateController extends Controller
{
    public function __construct(
        private readonly ApplyExamTemplateAction $applyTemplate,
        private readonly SaveExamTemplateFromExamAction $saveFromExam,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        // Admins (staffOwnerIds() === null) see the whole catalog; a teacher sees
        // the seeded templates plus their own; an assistant additionally sees
        // those belonging to the teacher they work for.
        $ownerIds = $request->user()->staffOwnerIds();

        $templates = ExamTemplate::query()
            // Eager-loaded: ExamTemplateResource derives its totals from it, and
            // a template list without it would issue one query per row.
            ->with('sections')
            ->where(function ($query) use ($ownerIds) {
                $query->where('is_system', true);

                if ($ownerIds !== null) {
                    $query->orWhereIn('created_by', $ownerIds);
                }
            })
            // Seeded presets first, then the teacher's own, alphabetically.
            // The catalog is deliberately small (a handful of system presets
            // plus a teacher's own saves) and the picker needs every row, so it
            // is returned as a plain collection rather than paginated.
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return $this->success(ExamTemplateResource::collection($templates), 'Exam templates retrieved.');
    }

    /**
     * Appends a template's blank questions to an exam.
     */
    public function apply(ApplyExamTemplateRequest $request, Exam $exam): JsonResponse
    {
        $template = ExamTemplate::query()
            ->with('sections')
            ->findOrFail($request->validated('template_id'));

        $created = $this->applyTemplate->execute($exam, $template);

        return $this->success(
            [
                'template' => new ExamTemplateResource($template),
                'created_count' => $created->count(),
                'total_questions' => $exam->questions()->count(),
            ],
            'Template applied. '.$created->count().' blank questions were added to the exam.',
            201
        );
    }

    /**
     * Captures an existing exam's structure as a new teacher-owned template.
     */
    public function storeFromExam(StoreExamTemplateRequest $request, Exam $exam): JsonResponse
    {
        $template = $this->saveFromExam->execute(
            $exam,
            $request->user(),
            $request->validated('name'),
            $request->validated('description')
        );

        return $this->success(new ExamTemplateResource($template), 'Template saved.', 201);
    }

    public function destroy(Request $request, ExamTemplate $template): JsonResponse
    {
        if (! $template->isEditableBy($request->user())) {
            return $this->error('This template is built in and cannot be deleted.', 403);
        }

        $template->delete();

        return $this->success(null, 'Template deleted.');
    }
}
