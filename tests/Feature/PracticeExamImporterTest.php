<?php

namespace Tests\Feature;

use App\Models\PracticeExam;
use App\Models\QuestionBankQuestion;
use App\Services\QuestionBank\ExamImportSummary;
use App\Services\QuestionBank\PracticeExamImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\PracticeExamFixture;
use Tests\TestCase;

/** Import đề cố định từ Excel + Word (docs/luyen-tap-ngan-hang-cau-hoi.md §11.2). */
class PracticeExamImporterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PracticeExamFixture::cleanup();
        parent::tearDown();
    }

    private function import(array $paths): ExamImportSummary
    {
        return app(PracticeExamImporter::class)->import(array_map(fn (string $p) => ['path' => $p, 'name' => basename($p)], $paths));
    }

    public function test_zip_with_excel_and_word_creates_draft_exam_in_exam_order(): void
    {
        $zip = PracticeExamFixture::zip([
            'Đề GK Cấp 2/Khối 6/De_GK1_Anh6_3_de.xlsx' => PracticeExamFixture::xlsx(['601' => [], '602' => []]),
            'Đề GK Cấp 2/Khối 6/De_GK1_Anh6_So1_Ma601.docx' => PracticeExamFixture::docx('601'),
            '__MACOSX/._junk.docx' => PracticeExamFixture::docx('999'),
        ]);

        $summary = $this->import([$zip]);

        $this->assertCount(2, $summary->saved);
        $this->assertSame([], $summary->errors);
        $exam = PracticeExam::query()->where('code', '601')->firstOrFail();
        $this->assertSame([6, 'gk1', PracticeExam::DRAFT, 60], [$exam->grade, $exam->term, $exam->status, $exam->duration_minutes]);
        $this->assertSame('De_GK1_Anh6_3_de.xlsx', $exam->source_file);

        $questions = $exam->questions()->with('passage')->get();
        $this->assertSame([1, 2, 3, 4, 5, 6], $questions->pluck('position')->all());
        // gạch chân lấy từ Word; Excel không có
        $this->assertSame(['A' => 'f[a]ther', 'B' => 'br[o]ther', 'C' => 'c[a]rd', 'D' => 'cl[a]ss'], $questions[0]->options);
        $this->assertSame('Ngữ âm', $questions[0]->skill);
        $this->assertSame('', $questions[0]->stem);
        $this->assertStringStartsWith('Mark the letter', $questions[0]->instruction);
        // hai câu điền từ dùng chung một ngữ liệu
        $this->assertNotNull($questions[3]->passage_id);
        $this->assertSame($questions[3]->passage_id, $questions[4]->passage_id);
        $this->assertStringContainsString('(4) _____', $questions[3]->passage->content);
        $this->assertSame('Đọc hiểu', $questions[3]->skill);
        // "Câu 6: …" ghép vào đề của câu 6; ngoặc vuông của Excel không thành gạch chân
        $this->assertSame("What does the sign say?\n\n(Biển báo) QUIET, PLEASE!", $questions[5]->stem);
        $this->assertSame('Giao tiếp & Biển báo', $questions[5]->skill);
        $this->assertSame('A', $questions[5]->answer);

        // đề 602 không có file Word → câu Phát âm thiếu gạch chân được cảnh báo
        $other = PracticeExam::query()->where('code', '602')->firstOrFail();
        $this->assertCount(2, $other->import_warnings);
        $this->assertStringContainsString('Câu 1 (Phát âm)', $other->import_warnings[0]);

        // câu của đề cố định không vào kho trộn
        $this->assertSame(0, QuestionBankQuestion::query()->inBank()->count());
        $this->assertSame(12, QuestionBankQuestion::query()->whereNotNull('exam_id')->count());
    }

    public function test_answer_key_mismatch_is_reported_and_grade_term_read_from_title(): void
    {
        $xlsx = PracticeExamFixture::xlsx(['803' => ['grade' => 8, 'term' => 'CUỐI HỌC KÌ II', 'minutes' => 45, 'keyOverride' => [3 => 'C']]]);

        $summary = $this->import([$xlsx]);

        $exam = PracticeExam::query()->firstOrFail();
        $this->assertSame([8, 'ck2', '803', 45], [$exam->grade, $exam->term, $exam->code, $exam->duration_minutes]);
        $this->assertSame('B', $exam->questions()->where('position', 3)->value('answer'));
        $this->assertTrue(collect($summary->warnings)->contains(fn ($w) => str_contains($w, 'Câu 3: đáp án trong bảng (B) khác bảng đáp án cuối sheet (C)')));
    }

    public function test_reimport_replaces_draft_but_keeps_published_exam(): void
    {
        $this->import([PracticeExamFixture::xlsx()]);
        $exam = PracticeExam::query()->firstOrFail();
        $firstIds = $exam->questions()->pluck('id')->all();

        $again = $this->import([PracticeExamFixture::xlsx(), PracticeExamFixture::docx()]);
        $this->assertStringContainsString('cập nhật 6 câu', $again->saved[0]);
        $this->assertSame(1, PracticeExam::query()->count());
        $this->assertSame(6, $exam->questions()->count());
        $this->assertEmpty(array_intersect($firstIds, $exam->questions()->pluck('id')->all()));
        $this->assertSame([], $exam->fresh()->import_warnings);

        $exam->update(['status' => PracticeExam::PUBLISHED]);
        $ids = $exam->questions()->pluck('id')->all();
        $kept = $this->import([PracticeExamFixture::xlsx()]);
        $this->assertSame([], $kept->saved);
        $this->assertCount(1, $kept->kept);
        $this->assertSame($ids, $exam->questions()->pluck('id')->all());
    }

    public function test_sheet_without_title_is_an_error_and_other_files_are_rejected(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setCellValue('A1', 'Bảng điểm');
        $path = sys_get_temp_dir().'/practice-exam-bad-'.uniqid().'.xlsx';
        (new Xlsx($book))->save($path);

        $summary = $this->import([$path]);
        @unlink($path);
        $this->assertSame([], $summary->saved);
        $this->assertStringContainsString('ĐỀ KIỂM TRA', $summary->errors[0]);

        $this->expectException(InvalidArgumentException::class);
        $this->import([PracticeExamFixture::docx()]); // chỉ có Word, không có Excel đề
    }

    public function test_zip_with_path_traversal_is_rejected(): void
    {
        $zip = PracticeExamFixture::zip(['../evil.xlsx' => PracticeExamFixture::xlsx()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('đường dẫn không hợp lệ');
        $this->import([$zip]);
    }
}
