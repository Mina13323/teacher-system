<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Unit\CreateUnitAction;
use App\Actions\Unit\ReorderUnitsAction;
use App\Actions\Unit\UpdateUnitAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUnitRequest;
use App\Http\Requests\ReorderUnitsRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Http\Resources\UnitDetailResource;
use App\Http\Resources\UnitResource;
use App\Models\Course;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;

class UnitController extends Controller
{
    public function __construct(
        private readonly CreateUnitAction $createUnit,
        private readonly UpdateUnitAction $updateUnit,
        private readonly ReorderUnitsAction $reorderUnits,
    ) {
    }

    public function index(Course $course): JsonResponse
    {
        $this->authorize('view', $course);

        $units = $course->units()
            ->withCount('lessons')
            ->orderBy('position')
            ->get();

        return $this->success(UnitResource::collection($units), 'Units retrieved.');
    }

    public function store(CreateUnitRequest $request, Course $course): JsonResponse
    {
        $unit = $this->createUnit->execute($course, $request->validated());

        return $this->success(
            new UnitResource($unit->loadCount('lessons')),
            'Unit created.',
            201
        );
    }

    public function show(Unit $unit): JsonResponse
    {
        $this->authorize('view', $unit);

        $unit->load(['course', 'lessons.videos'])
            ->loadCount(['lessons']);

        return $this->success(new UnitDetailResource($unit), 'Unit retrieved.');
    }

    public function update(UpdateUnitRequest $request, Unit $unit): JsonResponse
    {
        $unit = $this->updateUnit->execute($unit, $request->validated());

        return $this->success(new UnitResource($unit->loadCount('lessons')), 'Unit updated.');
    }

    public function reorder(ReorderUnitsRequest $request, Course $course): JsonResponse
    {
        $this->reorderUnits->execute($course, $request->validated('ordered_ids'));

        return $this->success(null, 'Units reordered.');
    }

    public function destroy(Unit $unit): JsonResponse
    {
        $this->authorize('delete', $unit);

        $unit->delete();

        return $this->success(null, 'Unit deleted.');
    }
}
