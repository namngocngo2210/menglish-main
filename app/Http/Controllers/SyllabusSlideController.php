<?php

namespace App\Http\Controllers;

use App\Models\SyllabusLesson;
use App\Models\SyllabusLessonSlide;
use App\Services\SafeUploadService;
use App\Services\Syllabus\SyllabusNotifier;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use ZipArchive;

/**
 * Bài giảng PowerPoint của buổi (docs/kho-syllabus.md §7.4): Học thuật tải file .pptx lên từng buổi (PPT GV / PPT GVNN), GV mở
 * trang trình chiếu trong app — iframe trình xem Office Online đọc file qua link công khai có chữ ký, hết hạn sau vài giờ.
 */
class SyllabusSlideController extends Controller
{
    public function store(Request $request, int $lesson)
    {
        $lessonModel = SyllabusLesson::with('unit')->findOrFail($lesson);
        $validated = $request->validate([
            'kind' => ['required', Rule::in(array_keys(SyllabusLessonSlide::KINDS))],
            'file' => ['required', 'file', 'max:'.SyllabusLessonSlide::MAX_KB],
        ], ['file.required' => 'Vui lòng chọn file PowerPoint.', 'file.max' => 'Dung lượng file tối đa 100 MB.']);

        $file = $request->file('file');
        $path = $this->storeFile($file);
        $slide = SyllabusLessonSlide::firstOrNew(['syllabus_lesson_id' => $lessonModel->id, 'kind' => $validated['kind']]);
        $oldPath = $slide->file_path;
        $slide->fill([
            'file_path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'size_bytes' => (int) $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ])->save();
        if ($oldPath && $oldPath !== $path) {
            Storage::disk(SyllabusLessonSlide::DISK)->delete($oldPath);
        }
        if ($stage = $lessonModel->unit?->stage) {
            app(SyllabusNotifier::class)->stageContentUpdated($stage);
        }

        return redirect()->back()->with('status', "Đã tải lên {$slide->label} cho Buổi {$lessonModel->session_no} ({$slide->size_human}).");
    }

    public function destroy(int $id)
    {
        $slide = SyllabusLessonSlide::with('lesson')->findOrFail($id);
        $slide->delete();
        Storage::disk(SyllabusLessonSlide::DISK)->delete($slide->file_path);

        return redirect()->back()->with('status', "Đã xóa {$slide->label} của Buổi {$slide->lesson?->session_no}.");
    }

    /** Trang trình chiếu: iframe trình xem Office Online. */
    public function show(Request $request, int $id)
    {
        $slide = SyllabusLessonSlide::with('lesson.unit')->findOrFail($id);
        abort_unless($slide->lesson && $slide->isVisibleTo($request->user()), 404);

        return Inertia::render('Syllabus/SlideViewer', [
            'title' => "Buổi {$slide->lesson->session_no}: {$slide->lesson->title}",
            'label' => $slide->label,
            'name' => $slide->original_name,
            'embedUrl' => $slide->embedUrl(),
            // Trình xem Office chỉ đọc được file từ địa chỉ công khai: máy chạy thử (localhost / IP nội bộ) không trình chiếu được.
            'publicHost' => ! self::isPrivateHost(parse_url((string) config('app.url'), PHP_URL_HOST) ?: ''),
            'downloadUrl' => SyllabusLessonSlide::userSeesAll($request->user()) ? route('syllabus.slides.download', $slide->id) : null,
        ]);
    }

    public function download(int $id)
    {
        $slide = SyllabusLessonSlide::findOrFail($id);
        $disk = Storage::disk(SyllabusLessonSlide::DISK);
        abort_unless($disk->exists($slide->file_path), 404, 'File bài giảng không còn trên máy chủ.');

        return $disk->download($slide->file_path, $slide->original_name);
    }

    /** File cho trình xem Office Online (không đăng nhập): chỉ mở được bằng link có chữ ký còn hạn. */
    public function publicFile(int $slide, int $expires, string $signature)
    {
        $model = SyllabusLessonSlide::find($slide);
        abort_unless($model && $model->hasValidSignature($expires, $signature), 403, 'Link trình chiếu đã hết hạn — mở lại trang trình chiếu.');
        $disk = Storage::disk(SyllabusLessonSlide::DISK);
        abort_unless($disk->exists($model->file_path), 404);

        $extension = pathinfo($model->file_path, PATHINFO_EXTENSION);

        return $disk->response($model->file_path, "slide.{$extension}", [
            'Content-Type' => $extension === 'ppt' ? 'application/vnd.ms-powerpoint' : 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'Content-Disposition' => 'inline; filename="slide.'.$extension.'"',
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * Đuôi file theo nội dung (SafeUploadService). Một số phần mềm ghi .pptx mà thứ tự mục trong zip khiến máy chủ không nhận ra
     * PowerPoint (chỉ thấy "zip"): chấp nhận .pptx khi đúng là gói zip có ppt/presentation.xml.
     */
    private function storeFile(UploadedFile $file): string
    {
        try {
            return SafeUploadService::store($file, SyllabusLessonSlide::DIRECTORY, SyllabusLessonSlide::EXTENSIONS, 'file', SyllabusLessonSlide::DISK);
        } catch (ValidationException $e) {
            $zip = new ZipArchive;
            $isPptx = strtolower($file->getClientOriginalExtension()) === 'pptx'
                && $zip->open($file->getRealPath()) === true && $zip->locateName('ppt/presentation.xml') !== false;
            if (! $isPptx) {
                throw $e;
            }

            return $file->storeAs(SyllabusLessonSlide::DIRECTORY, SafeUploadService::randomName('pptx'), SyllabusLessonSlide::DISK);
        }
    }

    private static function isPrivateHost(string $host): bool
    {
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.test') || str_ends_with($host, '.local')) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
