<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamIntegritySetting extends Model
{
    /** @use HasFactory<\Database\Factories\ExamIntegritySettingFactory> */
    use HasFactory;

    protected $fillable = [
        'exam_id',
        'fullscreen_required',
        'prevent_copy',
        'prevent_paste',
        'prevent_context_menu',
        'detect_tab_switch',
        'detect_window_blur',
        'detect_keyboard_shortcuts',
    ];

    protected function casts(): array
    {
        return [
            'fullscreen_required' => 'boolean',
            'prevent_copy' => 'boolean',
            'prevent_paste' => 'boolean',
            'prevent_context_menu' => 'boolean',
            'detect_tab_switch' => 'boolean',
            'detect_window_blur' => 'boolean',
            'detect_keyboard_shortcuts' => 'boolean',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }
}
