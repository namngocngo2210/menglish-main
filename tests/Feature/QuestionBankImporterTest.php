<?php

namespace Tests\Feature;

use App\Models\QuestionBankPassage;
use App\Models\QuestionBankQuestion;
use App\Services\QuestionBank\ImportSummary;
use App\Services\QuestionBank\QuestionBankImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\QuestionBankZip as Zip;
use Tests\TestCase;

class QuestionBankImporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Zip::cleanup();
        parent::tearDown();
    }

    /** File 070: nhóm A (2 câu, 1 có ảnh), nhóm B (ngữ liệu, 2 câu), nhóm C (1 câu tự luận). */
    private function standardEntries(string $stemSuffix = ''): array
    {
        $groups = [
            Zip::group('Từ vựng & Ngữ pháp', 'Chọn đáp án đúng', [
                Zip::question('B3.1', ['anh' => 'F070_B3-1.png', 'kien_thuc' => 'Unit 1', 'stem' => 'Stem B3.1'.$stemSuffix]),
                Zip::question('B3.2', ['stem' => 'Stem B3.2'.$stemSuffix]),
            ], instruction: 'Choose the correct answer.'),
            Zip::group('Đọc hiểu', 'Điền từ – đoạn văn', [
                Zip::question('B5.1', ['stem' => 'Stem B5.1'.$stemSuffix]),
                Zip::question('B5.2', ['stem' => 'Stem B5.2'.$stemSuffix]),
            ], passage: 'My village is (1) _____ and (2) _____.', instruction: 'Read the passage.'),
            Zip::group('Viết', 'Viết lại câu', [
                ['no' => 'C1', 'stem' => 'Rewrite the sentence.', 'answer' => 'He is taller than me.'],
            ]),
        ];

        return [
            'data/070.json' => Zip::dataFile(70, $groups),
            'hinh/F070_B3-1.png' => Zip::png(),
        ];
    }

    private function import(array $entries): ImportSummary
    {
        return app(QuestionBankImporter::class)->importZip(Zip::write($entries));
    }

    public function test_imports_mcq_questions_with_passages_and_images(): void
    {
        $summary = $this->import($this->standardEntries());

        $this->assertSame(4, $summary->created);
        $this->assertSame(0, $summary->updated);
        $this->assertSame(0, $summary->kept);
        $this->assertSame(1, $summary->skipped);
        $this->assertSame([], $summary->errors);
        $this->assertSame('Thêm 4, cập nhật 0, giữ nguyên 0, bỏ qua 1.', $summary->message());

        $this->assertSame(4, QuestionBankQuestion::query()->count());
        foreach (QuestionBankQuestion::query()->get() as $question) {
            $this->assertSame(9, $question->grade);
            $this->assertSame('gk1', $question->term);
            $this->assertSame('draft', $question->status);
            $this->assertSame('THCS Thử Nghiệm', $question->source_school);
            $this->assertSame('2026-2027', $question->source_year);
            $this->assertSame('/2026-2027/GIỮA KÌ I/Khối 9/De 070', $question->source_file);
        }

        $withImage = QuestionBankQuestion::query()->where('source_key', 'F070-B3.1')->firstOrFail();
        $this->assertSame('Từ vựng & Ngữ pháp', $withImage->skill);
        $this->assertSame('Chọn đáp án đúng', $withImage->type);
        $this->assertSame('Unit 1', $withImage->knowledge);
        $this->assertSame('Choose the correct answer.', $withImage->instruction);
        $this->assertSame(['A' => 'a B3.1', 'B' => 'b B3.1', 'C' => 'c B3.1', 'D' => 'd B3.1'], $withImage->options);
        $this->assertSame('B', $withImage->answer);
        $this->assertNull($withImage->passage_id);
        $this->assertSame(1, $withImage->position);
        $this->assertNotNull($withImage->image_path);
        $this->assertStringStartsWith('question_bank/'.now()->format('Y/m').'/', $withImage->image_path);
        Storage::disk('public')->assertExists($withImage->image_path);

        $this->assertSame(2, QuestionBankQuestion::query()->where('source_key', 'F070-B3.2')->value('position'));

        $passage = QuestionBankPassage::query()->where('source_key', 'F070-NL01')->firstOrFail();
        $this->assertSame('My village is (1) _____ and (2) _____.', $passage->content);
        $this->assertSame('Read the passage.', $passage->instruction);
        $this->assertSame(9, $passage->grade);
        $this->assertSame('gk1', $passage->term);
        $this->assertSame('Đọc hiểu', $passage->skill);
        $this->assertSame('Điền từ – đoạn văn', $passage->type);
        $this->assertSame(['F070-B5.1', 'F070-B5.2'], $passage->questions->pluck('source_key')->all());
        $this->assertSame([1, 2], $passage->questions->pluck('position')->all());
        $this->assertSame(1, QuestionBankPassage::query()->count());
    }

    public function test_reimport_updates_untouched_drafts_and_keeps_reviewed(): void
    {
        $this->import($this->standardEntries());

        QuestionBankQuestion::query()->where('source_key', 'F070-B3.2')->update(['status' => 'approved', 'approved_at' => now()]);
        QuestionBankQuestion::query()->where('source_key', 'F070-B5.1')->update(['edited_at' => now()]);

        $summary = $this->import($this->standardEntries(' (sửa)'));

        $this->assertSame(0, $summary->created);
        $this->assertSame(2, $summary->updated);
        $this->assertSame(2, $summary->kept);
        $this->assertSame(1, $summary->skipped);
        $this->assertSame([], $summary->errors);

        $stems = QuestionBankQuestion::query()->pluck('stem', 'source_key');
        $this->assertSame('Stem B3.1 (sửa)', $stems['F070-B3.1']);
        $this->assertSame('Stem B5.2 (sửa)', $stems['F070-B5.2']);
        $this->assertSame('Stem B3.2', $stems['F070-B3.2']);
        $this->assertSame('Stem B5.1', $stems['F070-B5.1']);
        $this->assertSame('approved', QuestionBankQuestion::query()->where('source_key', 'F070-B3.2')->value('status'));

        $this->assertSame(4, QuestionBankQuestion::query()->count());
        $this->assertSame(1, QuestionBankPassage::query()->count());

        // Câu cập nhật có ảnh mới; ảnh cũ không còn ai dùng thì được xoá.
        $this->assertCount(1, Storage::disk('public')->allFiles('question_bank'));
        Storage::disk('public')->assertExists(QuestionBankQuestion::query()->where('source_key', 'F070-B3.1')->value('image_path'));
    }

    public function test_reimport_keeps_passage_once_any_of_its_questions_was_reviewed(): void
    {
        $entries = $this->standardEntries();
        $this->import($entries);
        QuestionBankQuestion::query()->where('source_key', 'F070-B5.1')->update(['status' => 'hidden']);

        $entries['data/070.json'] = str_replace('My village is', 'Our village is', $entries['data/070.json']);
        $this->import($entries);

        $this->assertSame('My village is (1) _____ and (2) _____.', QuestionBankPassage::query()->value('content'));
    }

    public function test_reimport_updates_passage_while_all_its_questions_are_untouched_drafts(): void
    {
        $entries = $this->standardEntries();
        $this->import($entries);

        $entries['data/070.json'] = str_replace('My village is', 'Our village is', $entries['data/070.json']);
        $this->import($entries);

        $this->assertSame('Our village is (1) _____ and (2) _____.', QuestionBankPassage::query()->value('content'));
    }

    public function test_malformed_json_is_reported_and_others_import(): void
    {
        $summary = $this->import([
            'data/071.json' => '{"source": {"file_no": 71,',
            'data/072.json' => Zip::dataFile(72, [
                Zip::group('Ngữ âm', 'Phát âm', [Zip::question('1'), Zip::question('2')]),
            ]),
        ]);

        $this->assertCount(1, $summary->errors);
        $this->assertStringContainsString('071.json', $summary->errors[0]);
        $this->assertSame(2, $summary->created);
        $this->assertSame(['F072-1', 'F072-2'], QuestionBankQuestion::query()->orderBy('id')->pluck('source_key')->all());
        $this->assertSame('Thêm 2, cập nhật 0, giữ nguyên 0, bỏ qua 0. Lỗi: 1.', $summary->message());
    }

    public function test_file_with_unknown_term_or_grade_is_reported_whole(): void
    {
        $groups = [Zip::group('Ngữ âm', 'Phát âm', [Zip::question('1')])];

        $summary = $this->import([
            'data/073.json' => Zip::dataFile(73, $groups, ['ky' => 'Học kì hè']),
            'data/074.json' => Zip::dataFile(74, $groups, ['khoi' => 5]),
            'data/075.json' => Zip::dataFile(75, $groups, ['khoi' => 7, 'ky' => 'Cuối kì II']),
        ]);

        $this->assertCount(2, $summary->errors);
        $this->assertStringContainsString('073.json', $summary->errors[0]);
        $this->assertStringContainsString('074.json', $summary->errors[1]);
        $this->assertSame(1, $summary->created);
        $question = QuestionBankQuestion::query()->sole();
        $this->assertSame('F075-1', $question->source_key);
        $this->assertSame(7, $question->grade);
        $this->assertSame('ck2', $question->term);
    }

    public function test_rejects_zip_with_path_traversal(): void
    {
        $path = Zip::write([
            'data/076.json' => Zip::dataFile(76, [Zip::group('Ngữ âm', 'Phát âm', [Zip::question('1')])]),
            '../evil.json' => '{}',
        ]);

        try {
            app(QuestionBankImporter::class)->importZip($path);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('không hợp lệ', $e->getMessage());
        }

        $this->assertSame(0, QuestionBankQuestion::query()->count());
    }

    public function test_rejects_zip_with_disallowed_extension(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->import([
            'data/077.json' => Zip::dataFile(77, [Zip::group('Ngữ âm', 'Phát âm', [Zip::question('1')])]),
            'hinh/shell.php' => '<?php echo 1;',
        ]);
    }

    public function test_rejects_zip_with_too_many_entries(): void
    {
        $entries = [];
        for ($i = 0; $i <= QuestionBankImporter::MAX_ENTRIES; $i++) {
            $entries["hinh/{$i}.png"] = '';
        }

        $this->expectException(InvalidArgumentException::class);

        $this->import($entries);
    }

    public function test_rejects_zip_without_data_files(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->import(['hinh/a.png' => Zip::png()]);
    }

    public function test_reads_zip_with_a_wrapping_folder_and_ignores_mac_junk(): void
    {
        $summary = $this->import([
            'khoi-9/data/078.json' => Zip::dataFile(78, [Zip::group('Ngữ âm', 'Phát âm', [Zip::question('1')])]),
            'khoi-9/.DS_Store' => 'junk',
            '__MACOSX/khoi-9/data/._078.json' => 'junk',
        ]);

        $this->assertSame(1, $summary->created);
        $this->assertSame([], $summary->errors);
    }

    public function test_skips_questions_marked_missing_or_without_answer(): void
    {
        $summary = $this->import([
            'data/079.json' => Zip::dataFile(79, [
                Zip::group('Giao tiếp & Biển báo', 'Biển báo / Thông báo', [
                    Zip::question('1'),
                    Zip::question('2', ['answer' => '', 'thieu' => true]),
                    Zip::question('3', ['thieu' => true]),
                    Zip::question('4', ['answer' => '']),
                    Zip::question('5', ['answer' => 'F']),
                    Zip::question('6', ['answer' => 'b']),
                    Zip::question('7', ['options' => ['A' => 'x', 'B' => 'y', 'C' => 'z']]),
                    Zip::question('8', ['options' => ['A' => 'x', 'B' => 'y', 'C' => 'z', 'D' => ' ']]),
                    Zip::question('9', ['options' => ['A' => 'x', 'B' => 'y', 'C' => 'z', 'D' => 'w', 'E' => 'v']]),
                ]),
                Zip::group('Nghe', 'Nghe chọn đáp án', [Zip::question('10')]),
            ]),
        ]);

        $this->assertSame(1, $summary->created);
        $this->assertSame(9, $summary->skipped);
        $this->assertSame(['F079-1'], QuestionBankQuestion::query()->pluck('source_key')->all());
        $this->assertSame([], $summary->errors);
        $this->assertSame(9, array_sum($summary->skipReasons));
    }

    public function test_passage_is_created_only_when_one_of_its_questions_is_accepted(): void
    {
        $this->import([
            'data/080.json' => Zip::dataFile(80, [
                Zip::group('Viết', 'Viết đoạn văn', [Zip::question('1', ['options' => null])], passage: 'Open passage'),
                Zip::group('Đọc hiểu', 'Đọc hiểu – trả lời câu hỏi', [Zip::question('2')], passage: 'Second passage'),
            ]),
        ]);

        $passage = QuestionBankPassage::query()->sole();
        $this->assertSame('F080-NL02', $passage->source_key);
        $this->assertSame('Second passage', $passage->content);
    }

    public function test_missing_image_is_reported_and_question_still_imported(): void
    {
        $summary = $this->import([
            'data/081.json' => Zip::dataFile(81, [
                Zip::group('Giao tiếp & Biển báo', 'Biển báo / Thông báo', [
                    Zip::question('1', ['anh' => 'F081_1.png']),
                ]),
            ]),
        ]);

        $this->assertSame(1, $summary->created);
        $this->assertCount(1, $summary->errors);
        $this->assertStringContainsString('F081_1.png', $summary->errors[0]);
        $this->assertNull(QuestionBankQuestion::query()->value('image_path'));
    }

    public function test_same_image_referenced_twice_is_stored_once(): void
    {
        $this->import([
            'data/082.json' => Zip::dataFile(82, [
                Zip::group('Giao tiếp & Biển báo', 'Biển báo / Thông báo', [
                    Zip::question('1', ['anh' => 'shared.png']),
                    Zip::question('2', ['anh' => 'shared.png']),
                ]),
            ]),
            'hinh/shared.png' => Zip::png(),
        ]);

        $paths = QuestionBankQuestion::query()->pluck('image_path')->unique();
        $this->assertCount(1, $paths);
        $this->assertNotNull($paths->first());
        $this->assertCount(1, Storage::disk('public')->allFiles('question_bank'));
    }

    public function test_command_prints_summary(): void
    {
        $path = Zip::write($this->standardEntries());

        $before = DB::table('activity_log')->count();

        $this->artisan('question-bank:import', ['zip' => $path])
            ->expectsOutputToContain('Thêm 4')
            ->assertSuccessful();

        // Như import qua web: một dòng nhật ký tổng, không ghi từng câu / ngữ liệu.
        $this->assertSame($before + 1, DB::table('activity_log')->count());
        $log = DB::table('activity_log')->latest('id')->first();
        $this->assertSame('Ngân hàng câu hỏi', $log->log_name);
        $this->assertStringStartsWith('Import ngân hàng câu hỏi: Thêm 4', $log->description);
        $this->assertSame(basename($path), json_decode($log->properties, true)['file']);
        $this->assertSame(4, json_decode($log->properties, true)['created']);
    }

    public function test_command_fails_for_unsafe_zip(): void
    {
        $path = Zip::write(['../evil.json' => '{}']);

        $this->artisan('question-bank:import', ['zip' => $path])
            ->expectsOutputToContain('không hợp lệ')
            ->assertFailed();
    }
}
