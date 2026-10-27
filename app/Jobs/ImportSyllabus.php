<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Syllabus\Import\SyllabusImporter;
use App\Services\Syllabus\Import\SyllabusImportException;
use App\Services\Syllabus\Import\SyllabusImportStore;
use App\Services\Syllabus\Import\SyllabusPackageException;
use App\Services\Syllabus\Import\SyllabusPackageReader;
use App\Services\Syllabus\Import\SyllabusPackageValidator;

/**
 * Bước ③ Xác nhận import giáo trình chạy nền: đọc lại gói đã kiểm tra ở bước xem trước, validate lại theo quyền hiện tại,
 * ghi giáo trình và đẩy tài liệu lên kho file (R2) — việc lâu nhất. Xong thì xoá file tạm của phiên import.
 */
class ImportSyllabus extends BackgroundTaskJob
{
    private ?int $curriculumId = null;

    protected function process(): array
    {
        $payload = $this->task->payload;
        $user = User::find($this->task->user_id);
        if (! $user) {
            return ['ok' => false, 'message' => 'Người import không còn tồn tại.'];
        }

        try {
            $package = app(SyllabusPackageReader::class)->read($payload['path'], $payload['work_dir']);
            $plan = app(SyllabusPackageValidator::class)->validate($package, $user->can('syllabus.library'));
            if (! $plan->ok()) {
                return ['ok' => false, 'message' => 'File còn lỗi, hãy sửa file rồi tải lên lại.', 'report' => ['errors' => array_slice($plan->errors, 0, 20)]];
            }
            $curriculum = app(SyllabusImporter::class)->import($plan, $user, $payload['visibility']);
        } catch (SyllabusPackageException|SyllabusImportException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            app(SyllabusImportStore::class)->deleteFiles($payload['token']);
        }

        $this->curriculumId = $curriculum->id;
        $s = $plan->summary();

        return ['ok' => true, 'message' => "Đã import giáo trình {$plan->curriculum['code']}: {$s['stages']} chặng, {$s['lessons']} buổi, {$s['documents']} tài liệu. Gán cấp độ khoá học để lớp dùng được giáo trình."];
    }

    public function failed(?\Throwable $e): void
    {
        app(SyllabusImportStore::class)->deleteFiles($this->task->payload['token'] ?? '');
        parent::failed($e);
    }

    protected function link(): string
    {
        return $this->curriculumId ? route('syllabus.curriculums.show', ['id' => $this->curriculumId]) : route('syllabus.import');
    }
}
