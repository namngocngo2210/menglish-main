<?php

namespace App\Services\Syllabus\StageImport;

/**
 * Kết quả kiểm tra file chặng: chặng đích (null = thêm chặng mới), unit / buổi sẽ tạo / sửa / xóa mềm, lỗi theo dòng và
 * cảnh báo. Chưa ghi DB — StageImporter áp dụng khi người dùng xác nhận.
 */
final readonly class StageImportPlan
{
    /**
     * @param  list<array{line: int, unit_number: int, attributes: array<string, mixed>, lesson_id: ?int}>  $lessons
     * @param  list<int>  $deleteLessonIds
     * @param  list<array{unit_number: int, title: string, objectives: ?string, unit_id: ?int}>  $units
     * @param  list<array{line: int, message: string}>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?int $stageId,
        public string $stageName,
        public array $lessons,
        public array $deleteLessonIds,
        public array $units,
        public array $errors,
        public array $warnings,
        public int $openClasses,
    ) {}

    public function ok(): bool
    {
        return $this->errors === [];
    }

    /** @return array{create: int, update: int, delete: int, units: int} */
    public function summary(): array
    {
        $updates = count(array_filter($this->lessons, fn (array $lesson) => $lesson['lesson_id'] !== null));

        return [
            'create' => count($this->lessons) - $updates,
            'update' => $updates,
            'delete' => count($this->deleteLessonIds),
            'units' => count(array_filter($this->units, fn (array $unit) => $unit['unit_id'] === null)),
        ];
    }
}
