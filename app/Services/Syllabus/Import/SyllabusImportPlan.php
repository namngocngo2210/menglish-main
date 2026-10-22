<?php

namespace App\Services\Syllabus\Import;

/**
 * Kết quả kiểm tra gói import: cây Chặng → Unit → Buổi + tài liệu, kèm lỗi (chặn import) và cảnh báo.
 *
 * @phpstan-type Document array{title: string, original_name: string, extension: string, size: int, local_path: string}
 * @phpstan-type Lesson array{session_no: int, title: string, objectives: ?string, content: ?string, vocabulary_focus: ?string, grammar_focus: ?string, homework_guide: ?string}
 * @phpstan-type Unit array{unit_number: int, title: string, lessons: list<Lesson>}
 */
final readonly class SyllabusImportPlan
{
    /**
     * @param  array{code: string, title: string, version: string, description: ?string}|null  $curriculum
     * @param  list<array{position: int, name: string, description: ?string, big_test_title: ?string, big_test_note: ?string, overview_link: ?string, units: list<Unit>, documents: list<Document>}>  $stages
     * @param  list<Document>  $sharedDocuments  file ở gốc tai-lieu/
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?array $curriculum,
        public array $stages,
        public array $sharedDocuments,
        public array $errors,
        public array $warnings,
    ) {}

    public function ok(): bool
    {
        return $this->errors === [];
    }

    /** @return array{stages: int, units: int, lessons: int, documents: int} */
    public function summary(): array
    {
        $units = 0;
        $lessons = 0;
        $documents = count($this->sharedDocuments);
        foreach ($this->stages as $stage) {
            $units += count($stage['units']);
            $documents += count($stage['documents']);
            foreach ($stage['units'] as $unit) {
                $lessons += count($unit['lessons']);
            }
        }

        return ['stages' => count($this->stages), 'units' => $units, 'lessons' => $lessons, 'documents' => $documents];
    }
}
