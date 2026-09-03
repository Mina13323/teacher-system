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
        $option = Option::create([
            'question_id' => $question->getKey(),
            'option_text' => $request->validated('option_text'),
            'is_correct' => $request->boolean('is_correct'),
            'position' => $request->validated('position', (int) $question->options()->max('position') + 1),
        ]);

        return $this->success(
            new OptionResource($option),
            'Option created.',
            201
        );
    }

    public function update(UpdateOptionRequest $request, Option $option): JsonResponse
    {
        $option->fill($request->validated());
        $option->save();

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
