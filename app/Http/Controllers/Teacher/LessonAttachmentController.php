<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Audit\RecordAuditLogAction;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Lesson attachment management (P2): upload/list/update/delete files for a
 * lesson. Files live on the private disk; students fetch them only through
 * the authorized download endpoint (LessonAttachmentPolicy::viewFile).
 */
class LessonAttachmentController extends Controller
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
    ) {
    }

    public function index(Lesson $lesson): JsonResponse
    {
        $this->authorize('update', $lesson);

        return $this->success($lesson->attachments()->get(), 'Attachments retrieved.');
    }

    public function store(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorize('update', $lesson);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:51200'], // 50 MB
        ]);

        $file = $request->file('file');

        $attachment = $lesson->attachments()->create([
            'uploaded_by' => $request->user()->getKey(),
            'title' => $data['title'],
            'file_path' => $file->store('lesson-attachments/'.$lesson->getKey(), 'local'),
            'file_name' => $file->getClientOriginalName(),
            'file_mime' => $file->getClientMimeType(),
            'file_size' => $file->getSize() ?: 0,
            'position' => (int) $lesson->attachments()->max('position') + 1,
        ]);

        $this->auditLog->execute('lesson.attachment.upload', $attachment, [
            'lesson_id' => $lesson->id,
            'file_name' => $attachment->file_name,
            'file_size' => $attachment->file_size,
        ]);

        return $this->success($attachment, 'Attachment uploaded.', 201);
    }

    public function update(Request $request, LessonAttachment $attachment): JsonResponse
    {
        $this->authorize('update', $attachment);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        $attachment->update($data);

        return $this->success($attachment->fresh(), 'Attachment updated.');
    }

    /**
     * Authorized file download (routed under shared auth: policy admits staff
     * of the course AND students who can access the lesson).
     */
    public function downloadFile(LessonAttachment $attachment): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('viewFile', $attachment);

        abort_unless($attachment->file_path && Storage::disk('local')->exists($attachment->file_path), 404, 'File not found.');

        return Storage::disk('local')->download(
            $attachment->file_path,
            $attachment->file_name ?: 'attachment',
            ['Content-Type' => $attachment->file_mime ?: 'application/octet-stream']
        );
    }

    public function destroy(Request $request, LessonAttachment $attachment): JsonResponse
    {
        $this->authorize('delete', $attachment);

        // The record is removed; the file itself is deleted from storage —
        // it is not an academic record (no student work references it).
        if ($attachment->file_path) {
            Storage::disk('local')->delete($attachment->file_path);
        }
        $attachment->delete();

        $this->auditLog->execute('lesson.attachment.delete', $attachment, [
            'lesson_id' => $attachment->lesson_id,
            'file_name' => $attachment->file_name,
        ]);

        return $this->success(null, 'Attachment deleted.');
    }
}
