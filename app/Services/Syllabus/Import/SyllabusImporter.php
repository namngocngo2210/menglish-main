<?php

namespace App\Services\Syllabus\Import;

use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\SyllabusUnit;
use App\Models\User;
use App\Services\SafeUploadService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Ghi kế hoạch import đã kiểm tra vào DB trong một transaction và lưu tài liệu.
 * Lỗi bất kỳ: rollback DB và xoá các file đã lưu trong lần import này. File tạm của plan không bị xoá ở đây.
 */
class SyllabusImporter
{
    /**
     * @param  array{visible_to_teachers?: bool, visible_to_assistants?: bool, downloadable?: bool}  $visibility
     *
     * @throws SyllabusImportException khi mã giáo trình đã tồn tại
     */
    public function import(SyllabusImportPlan $plan, User $user, array $visibility): SyllabusCurriculum
    {
        if (! $plan->ok() || $plan->curriculum === null) {
            throw new \LogicException('Không thể import gói còn lỗi.');
        }

        $storedPaths = [];

        try {
            return DB::transaction(function () use ($plan, $user, $visibility, &$storedPaths) {
                $meta = $plan->curriculum;
                if (SyllabusCurriculum::withTrashed()->where('code', $meta['code'])->exists()) {
                    throw $this->duplicate($meta['code']);
                }

                $curriculum = SyllabusCurriculum::create([
                    'code' => $meta['code'],
                    'title' => $meta['title'],
                    'version' => $meta['version'],
                    'description' => $meta['description'],
                    // booted() tạo Chặng 1 với tên này
                    'stage_name' => $plan->stages[0]['name'] ?? null,
                ]);

                /** @var array<int, SyllabusStage> $stages theo position */
                $stages = [];
                foreach ($plan->stages as $row) {
                    $fields = [
                        'name' => $row['name'],
                        'description' => $row['description'],
                        'big_test_title' => $row['big_test_title'],
                        'big_test_note' => $row['big_test_note'],
                        'overview_link' => $row['overview_link'],
                    ];
                    if ($row['position'] === 1) {
                        $stage = SyllabusStage::where('curriculum_id', $curriculum->id)->where('position', 1)->firstOrFail();
                        $stage->update($fields);
                    } else {
                        $stage = SyllabusStage::create(['curriculum_id' => $curriculum->id, 'position' => $row['position']] + $fields);
                    }
                    $stages[$row['position']] = $stage;

                    foreach ($row['units'] as $unitRow) {
                        $unit = SyllabusUnit::create([
                            'curriculum_id' => $curriculum->id,
                            'stage_id' => $stage->id,
                            'unit_number' => $unitRow['unit_number'],
                            'title' => $unitRow['title'],
                        ]);
                        foreach ($unitRow['lessons'] as $lesson) {
                            SyllabusLesson::create(['curriculum_id' => $curriculum->id, 'unit_id' => $unit->id] + $lesson);
                        }
                    }
                }

                foreach ($plan->stages as $row) {
                    foreach ($row['documents'] as $document) {
                        $this->storeDocument($curriculum, $stages[$row['position']], $document, $user, $visibility, $storedPaths);
                    }
                }
                foreach ($plan->sharedDocuments as $document) {
                    $this->storeDocument($curriculum, null, $document, $user, $visibility, $storedPaths);
                }

                return $curriculum;
            });
        } catch (\Throwable $e) {
            foreach ($storedPaths as $path) {
                try {
                    Storage::disk(SyllabusDocument::DISK)->delete($path);
                } catch (\Throwable $cleanupError) {
                    report($cleanupError); // không để lỗi dọn dẹp che lỗi gốc
                }
            }

            if ($e instanceof UniqueConstraintViolationException) {
                throw $this->duplicate($plan->curriculum['code'], $e);
            }

            throw $e;
        }
    }

    private function duplicate(string $code, ?\Throwable $previous = null): SyllabusImportException
    {
        return new SyllabusImportException("Mã {$code} đã tồn tại — đổi mã khác.", 0, $previous);
    }

    /**
     * @param  array{title: string, original_name: string, extension: string, size: int, local_path: string}  $document
     * @param  list<string>  $storedPaths
     */
    private function storeDocument(SyllabusCurriculum $curriculum, ?SyllabusStage $stage, array $document, User $user, array $visibility, array &$storedPaths): void
    {
        $file = new UploadedFile($document['local_path'], $document['original_name'], null, null, true);
        $mime = $file->getMimeType();
        $size = (int) ($document['size'] ?: $file->getSize());

        $path = SafeUploadService::store($file, SyllabusDocument::DIRECTORY, SyllabusDocument::ALLOWED_EXTENSIONS, 'file', SyllabusDocument::DISK);
        $storedPaths[] = $path;

        SyllabusDocument::create([
            'curriculum_id' => $curriculum->id,
            'title' => $document['title'],
            'stage_id' => $stage?->id,
            'stage_name' => $stage?->label,
            'file_path' => $path,
            'original_name' => mb_substr($document['original_name'], 0, 255),
            'extension' => pathinfo($path, PATHINFO_EXTENSION),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'visible_to_teachers' => (bool) ($visibility['visible_to_teachers'] ?? false),
            'visible_to_assistants' => (bool) ($visibility['visible_to_assistants'] ?? false),
            'downloadable' => (bool) ($visibility['downloadable'] ?? false),
            'uploaded_by' => $user->id,
        ]);
    }
}
