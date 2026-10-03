<?php

namespace Tests\Feature;

use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\User;
use App\Services\PlacementPdfAnswerSheet;
use App\Services\PlacementRubricService;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tạo đề test đầu vào từ file PDF: tải file → hệ thống dựng sẵn phiếu trả lời + đáp án → người tạo đề sửa rồi lưu;
 * thí sinh xem file PDF và trả lời trên phiếu, chấm tự động như đề soạn từng câu. Cách soạn từng câu vẫn giữ nguyên.
 */
class PlacementPdfTestTest extends TestCase
{
    use RefreshDatabase;

    private User $academicLead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->academicLead = User::factory()->create(['is_active' => true]);
        $this->academicLead->assignRole('academic_lead');
    }

    public function test_sheet_is_built_from_numbered_questions_options_sections_and_answer_key(): void
    {
        $sheet = PlacementPdfAnswerSheet::build(<<<'TXT'
            MENGLISH PLACEMENT TEST - GRADE 3
            PART 1. LISTENING
            Listen and tick the correct picture.
            1. What is Tom doing?
            2. Where is the cat?
            A B C D
            Part 2: Read and choose the correct answer.
            3. She ___ to school every day.
            A. go B. goes C. going D. gone
            4. They ___ football yesterday.
            A. play
            B. played
            5. Look and write: This is a ________ .
            WRITING
            6. Write about your family (30 - 40 words).
            ANSWER KEY
            Part 1: 1B 2A
            Part 2: 3. B 4. b 5. pencil | pen
            TXT);

        $q = collect($sheet['questions'])->keyBy('number');
        $this->assertCount(6, $sheet['questions']);
        $this->assertSame([], $sheet['warnings']);
        $this->assertSame(5, $sheet['answers_found']);

        // Câu chọn tranh không ghi chữ phương án → trắc nghiệm A–C, kỹ năng Nghe theo tiêu đề phần.
        $this->assertSame(['listening', 'multiple_choice', 'B', ['A', 'B', 'C']], [$q['1']['skill'], $q['1']['type'], $q['1']['correct_answer'], array_column($q['1']['options'], 'key')]);
        // Dòng nhãn tranh "A B C D" dưới câu hỏi → 4 phương án, không lẫn vào nội dung câu.
        $this->assertSame(['A', 'B', 'C', 'D'], array_column($q['2']['options'], 'key'));
        $this->assertSame('Where is the cat?', $q['2']['title']);
        $this->assertSame(['reading', 'multiple_choice', 'B'], [$q['3']['skill'], $q['3']['type'], $q['3']['correct_answer']]);
        $this->assertSame(['go', 'goes', 'going', 'gone'], array_column($q['3']['options'], 'text'));
        $this->assertSame('She ___ to school every day.', $q['3']['title']);
        $this->assertSame('B', $q['4']['correct_answer']);
        $this->assertSame(['fill_blank', 'pencil | pen'], [$q['5']['type'], $q['5']['correct_answer']]);
        $this->assertSame(['writing', 'essay', ''], [$q['6']['skill'], $q['6']['type'], $q['6']['correct_answer']]);
    }

    public function test_answers_follow_order_when_each_part_restarts_numbering(): void
    {
        $sheet = PlacementPdfAnswerSheet::build("Part 1 Listen and tick\n1. Cat\n2. Dog\nPart 2 Read and choose\n1. Sun\n2. Moon\nĐáp án:\nPart 1: 1C 2A\nPart 2: 1B 2C");

        $this->assertSame(['C', 'A', 'B', 'C'], array_column($sheet['questions'], 'correct_answer'));
        $this->assertSame(['listening', 'listening', 'reading', 'reading'], array_column($sheet['questions'], 'skill'));
        $this->assertSame([1, 2, 3, 4], array_column($sheet['questions'], 'id'));
    }

    public function test_scanned_pdf_without_text_asks_for_a_blank_sheet(): void
    {
        $sheet = PlacementPdfAnswerSheet::build('');

        $this->assertSame([], $sheet['questions']);
        $this->assertStringContainsString('bản scan', $sheet['warnings'][0]);
        $this->assertCount(30, PlacementPdfAnswerSheet::blankSheet(30));
    }

    public function test_pasted_answers_are_parsed_in_common_formats(): void
    {
        $this->assertSame(
            [['number' => 1, 'answer' => 'A'], ['number' => 2, 'answer' => 'b'], ['number' => 3, 'answer' => 'seven | 7'], ['number' => 4, 'answer' => 'C'], ['number' => 12, 'answer' => 'D']],
            PlacementPdfAnswerSheet::parseKey("1. A  2. b 3 - seven | 7 4) C\nCâu 12: D")
        );
        $this->actingAs($this->academicLead)->postJson(route('placement-tests.pdf.answers'), ['text' => '1A 2B'])
            ->assertOk()->assertJsonPath('answers.1', ['number' => 2, 'answer' => 'B']);
    }

    public function test_uploading_a_pdf_stores_the_file_and_returns_a_draft_sheet(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->academicLead)->post(route('placement-tests.pdf.store'), ['file' => $this->pdfUpload()], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonCount(3, 'questions')
            ->assertJsonPath('answers_found', 3)
            ->assertJsonPath('answer_key_page', 1)
            ->assertJsonPath('questions.0.skill', 'listening')
            ->assertJsonPath('questions.1.correct_answer', 'B')
            ->assertJsonPath('questions.2.correct_answer', 'bút chì');
        Storage::disk('public')->assertExists($response->json('pdf_path'));
        $this->assertStringStartsWith('placement_tests/pdf/', $response->json('pdf_path'));
    }

    public function test_separate_answer_key_pdf_is_read_but_not_kept(): void
    {
        Storage::fake('public');

        $this->actingAs($this->academicLead)->post(route('placement-tests.pdf.store'), ['file' => $this->pdfUpload(), 'answers_only' => 1], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('answers', [['number' => 1, 'answer' => 'B'], ['number' => 2, 'answer' => 'B'], ['number' => 3, 'answer' => 'bút chì']]);
        $this->assertSame([], Storage::disk('public')->allFiles('placement_tests'));
    }

    public function test_upload_rejects_files_that_are_not_pdf(): void
    {
        Storage::fake('public');

        $this->actingAs($this->academicLead)
            ->post(route('placement-tests.pdf.store'), ['file' => $this->fileNamed('de.pdf', '<?php echo 1;')], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertSame([], Storage::disk('public')->allFiles('placement_tests'));
    }

    public function test_pdf_test_is_saved_shown_to_candidates_as_pdf_with_answer_sheet_and_auto_graded(): void
    {
        Storage::fake('public');
        // File có đáp án: đọc được phiếu + đáp án nhưng không được dùng làm file phát cho thí sinh.
        $upload = $this->actingAs($this->academicLead)->post(route('placement-tests.pdf.store'), ['file' => $this->pdfUpload()], ['Accept' => 'application/json'])->json();
        $payload = [
            'code' => 'TEST-G3-G4-PDF', 'title' => 'Đề PDF lớp 3', 'grade_level' => 'lop_3', 'duration_minutes' => 30,
            'mode' => 'pdf', 'audio_url' => '/storage/placement_tests/nghe.mp3', 'questions' => json_encode($upload['questions']),
        ];
        $this->actingAs($this->academicLead)->post(route('placement-tests.store'), $payload + ['pdf_path' => $upload['pdf_path']])
            ->assertSessionHasErrors(['pdf_path' => 'File đề PDF còn phần đáp án (trang 1) — thí sinh sẽ thấy đáp án. Hãy bấm "Đổi file" và tải file đề không có trang đáp án; phiếu và đáp án đã đọc được vẫn giữ nguyên.']);

        $studentCopy = $this->actingAs($this->academicLead)->post(route('placement-tests.pdf.store'), ['file' => $this->pdfUpload(withKey: false)], ['Accept' => 'application/json'])
            ->assertJsonPath('answer_key_page', null)->json();
        $this->actingAs($this->academicLead)->post(route('placement-tests.store'), $payload + ['pdf_path' => $studentCopy['pdf_path']])
            ->assertRedirect(route('placement-tests.index'))->assertSessionHasNoErrors();
        $upload = $studentCopy;

        $test = PlacementTest::where('code', 'TEST-G3-G4-PDF')->firstOrFail();
        $this->assertSame($upload['pdf_path'], $test->pdf_path);
        $this->assertSame('/storage/placement_tests/nghe.mp3', $test->audio_url);
        $this->assertSame(3, $test->questions_count);

        $this->get(route('portal.test.take', $test->code))->assertOk()
            ->assertInertia(fn ($page) => $page->component('PlacementTests/Portal/Take')
                ->where('test.pdf_url', $test->pdfUrl())
                ->where('test.audio_src', '/storage/placement_tests/nghe.mp3')
                ->has('sheet', 3)
                ->where('sheet.0.number', '1')
                ->where('sheet.1.options', ['A', 'B', 'C'])
                ->where('sheet.2.type', 'fill_blank')
                ->where('writingPrompt', null)
                ->missing('sheet.0.correct_answer'));

        $this->post(route('portal.test.submit', $test->code), [
            'candidate_name' => 'Bé Na', 'candidate_phone' => '0901234567',
            'answers' => ['1' => 'B', '2' => 'A', '3' => 'Bút chì'],
        ])->assertRedirect(route('portal.test.done', $test->code));

        $submission = PlacementTestSubmission::latest('id')->firstOrFail();
        $this->assertEquals(PlacementRubricService::maxScores('khoi_3_4')['listening'], (float) $submission->listening_score); // Nghe 1/1 câu đúng
        // Đọc 1/2 câu đúng (điền từ không phân biệt hoa thường) → nửa thang Đọc & Viết của khối.
        $this->assertEquals(PlacementRubricService::maxScores('khoi_3_4')['reading_writing'] / 2, (float) $submission->reading_writing_score);
    }

    public function test_pdf_mode_requires_a_file_and_manual_tests_still_work(): void
    {
        $this->actingAs($this->academicLead)->post(route('placement-tests.store'), [
            'code' => 'TEST-G3-G4-NOFILE', 'title' => 'Thiếu file', 'grade_level' => 'lop_3', 'duration_minutes' => 30,
            'mode' => 'pdf', 'pdf_path' => '', 'questions' => '[]',
        ])->assertSessionHasErrors('pdf_path');

        $this->actingAs($this->academicLead)->post(route('placement-tests.store'), [
            'code' => 'TEST-G3-G4-FAKE', 'title' => 'File lạ', 'grade_level' => 'lop_3', 'duration_minutes' => 30,
            'mode' => 'pdf', 'pdf_path' => '../../.env', 'questions' => json_encode(PlacementPdfAnswerSheet::blankSheet(2)),
        ])->assertSessionHasErrors('pdf_path');

        $this->actingAs($this->academicLead)->post(route('placement-tests.store'), [
            'code' => 'TEST-G3-G4-MANUAL', 'title' => 'Soạn tay', 'grade_level' => 'lop_3', 'duration_minutes' => 30,
            'mode' => 'manual', 'pdf_path' => '', 'audio_url' => '',
            'questions' => json_encode([['id' => 1, 'skill' => 'reading', 'type' => 'multiple_choice', 'title' => 'Pick one', 'options' => [['key' => 'A', 'text' => 'x'], ['key' => 'B', 'text' => 'y']], 'correct_answer' => 'A', 'points' => 1]]),
        ])->assertSessionHasNoErrors();

        $manual = PlacementTest::where('code', 'TEST-G3-G4-MANUAL')->firstOrFail();
        $this->assertNull($manual->pdf_path);
        $this->get(route('portal.test.take', $manual->code))->assertOk()
            ->assertInertia(fn ($page) => $page->where('test.pdf_url', null)->has('reading', 1)->where('sheet', []));
    }

    public function test_switching_an_existing_pdf_test_to_manual_drops_the_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('placement_tests/pdf/2026/10/de.pdf', '%PDF-1.4');
        $test = PlacementTest::create([
            'code' => 'TEST-G3-G4-EDIT', 'title' => 'Đề sửa', 'target_level' => 'Lớp 3', 'grade_level' => 'lop_3', 'duration_minutes' => 30,
            'questions_count' => 2, 'questions' => PlacementPdfAnswerSheet::blankSheet(2), 'pdf_path' => 'placement_tests/pdf/2026/10/de.pdf', 'is_active' => true,
        ]);

        $this->actingAs($this->academicLead)->get(route('placement-tests.edit', $test->id))->assertOk()
            ->assertInertia(fn ($page) => $page->where('test.pdf_path', 'placement_tests/pdf/2026/10/de.pdf')->where('test.pdf_url', $test->pdfUrl()));

        $this->actingAs($this->academicLead)->put(route('placement-tests.update', $test->id), [
            'title' => 'Đề sửa', 'target_level' => 'Lớp 3', 'duration_minutes' => 30, 'is_active' => 1,
            'mode' => 'manual', 'pdf_path' => '', 'audio_url' => '', 'questions' => json_encode($test->questions),
        ])->assertSessionHasNoErrors();

        $this->assertNull($test->fresh()->pdf_path);
    }

    /** File upload thật (đuôi file đoán theo nội dung, không theo tên như UploadedFile::fake()). */
    private function fileNamed(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pdfUpload(bool $withKey = true): UploadedFile
    {
        $html = '<h2>PART 1. LISTENING</h2><p>Listen and tick the correct picture.</p><p>1. What is Tom doing?</p>'
            .'<h2>Part 2: Read and choose</h2><p>2. She ___ to school.</p><p>A. go &nbsp; B. goes &nbsp; C. going</p>'
            .'<p>3. Đây là cái ___ .</p>'.($withKey ? '<h3>ANSWER KEY</h3><p>1B 2B 3. bút chì</p>' : '');
        $pdf = Pdf::loadHTML('<meta charset="utf-8"><body style="font-family: DejaVu Sans">'.$html.'</body>')->output();

        return $this->fileNamed('de-test.pdf', $pdf);
    }
}
