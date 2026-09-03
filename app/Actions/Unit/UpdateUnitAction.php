<?php

namespace App\Actions\Unit;

use App\Models\Unit;

/**
 * Updates an existing unit from validated input.
 */
class UpdateUnitAction
{
    public function execute(Unit $unit, array $data): Unit
    {
        $unit->fill($data)->save();

        return $unit->refresh();
    }
}
