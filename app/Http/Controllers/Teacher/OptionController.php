<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOptionRequest;
use App\Http\Requests\UpdateOptionRequest;
use App\Http\Resources\OptionResource;
use App\Models\Option;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OptionController extends Controller
{
    public function index(Request $request, Question $question): JsonResponse
    {
        $this->authorize('view', $question);

        return $this->success(
            OptionResource::collection($question->options()->get()),
            'Options retrieved.'
        );
    }

    public function store(CreateOptionRequest $request, Question $question): JsonResponse
    {
        $option = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $question) {
            $isCorrect = $request->boolean('is_correct');
            $isSingleChoice = $question->type === \App\Enums\QuestionType::SingleChoice
                || $question->type?->value === 'single_choice';

            if ($isCorrect && $isSingleChoice) {
                $question->options()->update(['is_correct' => false]);
            }

            return Option::create([
                'question_id' => $question->getKey(),
                'option_text' => $request->validated('option_text'),
                'is_correct' => $isCorrect,
                'position' => $request->validated('position', (int) $question->options()->max('position') + 1),
            ]);
        });

        return $this->success(
            new OptionResource($option),
            'Option created.',
            201
        );
    }

    public function update(UpdateOptionRequest $request, Option $option): JsonResponse
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $option) {
            $option->fill($request->validated());
            $option->save();

            // When marking an option as correct on a single-choice question,
            // automatically unset is_correct on all other options of that question.
            $question = $option->question;
            $isSingleChoice = $question && ($question->type === \App\Enums\QuestionType::SingleChoice || $question->type?->value === 'single_choice');
            if ($request->boolean('is_correct') && $isSingleChoice) {
                Option::query()
                    ->where('question_id', $option->question_id)
                    ->where('id', '!=', $option->id)
                    ->update(['is_correct' => false]);
            }
        });

        return $this->success(
            new OptionResource($option->fresh()),
            'Option updated.'
        );
    }

    public function destroy(Option $option): JsonResponse
    {
        $this->authorize('delete', $option);

        $option->delete();

        return $this->success(null, 'Option deleted.');
    }
}
