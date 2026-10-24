<?php

namespace Database\Seeders;

use App\Http\Controllers\SyllabusClassController;
use App\Http\Controllers\SyllabusController;
use App\Models\ClassLessonOverride;
use App\Models\ClassModel;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\User;
use App\Services\Syllabus\Import\SyllabusImporter;
use App\Services\Syllabus\Import\SyllabusPackageReader;
use App\Services\Syllabus\Import\SyllabusPackageValidator;
use Database\Seeders\Concerns\InvokesControllersAsUser;
use Database\Seeders\Support\SyllabusSampleFiles;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Dữ liệu demo Kho syllabus (docs/kho-syllabus.md) dựng trên giáo trình DEMO-SYL-* của DemoPhase2Seeder:
 * - Buổi đủ cột form Sheet 1: loại Học / Ôn tập / Big test, quy trình, hoạt động gợi ý, link PPT / PPT GVNN / Quizizz /
 *   đề kiểm tra, sản phẩm đầu ra, mốc thông báo; chặng 1 Starters báo trước 3 buổi.
 * - Tài liệu chung / theo chặng / theo buổi với đối tượng xem khác nhau (GV, TA, chỉ nội bộ, chỉ xem không tải); tài liệu
 *   chặng 3 Starters chưa lớp nào học tới nên GV không thấy; GV CG đã đánh dấu xem 1 tài liệu.
 * - Đề xuất sửa giáo trình: chờ duyệt / đã duyệt / đã từ chối.
 * - Giáo trình DEMO-SYL-FLYERS import từ gói zip mẫu (lịch sử "Import"), chưa gắn trình độ nào.
 *
 * Idempotent: mỗi phần chỉ chạy khi giáo trình chưa có dữ liệu phần đó. Đi qua SyllabusController / dịch vụ import thật.
 */
class DemoSyllabusLibrarySeeder extends Seeder
{
    use InvokesControllersAsUser;

    private const FLYERS = 'DEMO-SYL-FLYERS';

    private const SAMPLES = 'syllabus-import-samples';

    /** Tài liệu demo: [tiêu đề, file mẫu, gắn vào (common | stage:N | lesson:N theo buổi số), GV, TA, cho tải]. */
    private const DOCUMENTS = [
        'DEMO-SYL-STARTERS' => [
            ['Hướng dẫn sử dụng giáo trình Starters', 'slide-buoi-1.pdf', 'common', true, true, true],
            ['Đáp án Workbook Starters (nội bộ — Học vụ order in)', 'slide-buoi-1.pdf', 'common', false, false, true],
            ['Mục lục chặng 1 (chỉ xem)', 'slide-buoi-1.pdf', 'stage:1', true, false, false],
            ['Flashcard con vật', 'hinh-minh-hoa.png', 'stage:2', true, true, true],
            ['Đề Mock test Starters', 'slide-buoi-1.pdf', 'stage:3', true, false, true],
            ['Slide buổi 1 — Chào hỏi', 'slide-buoi-1.pdf', 'lesson:1', true, true, true],
            ['Audio nghe buổi 2', 'audio-nghe.mp3', 'lesson:2', true, true, true],
            ['Video bài hát Animals', 'video-mau.mp4', 'lesson:7', true, true, false],
        ],
        'DEMO-SYL-MOVERS' => [
            ['Hướng dẫn sử dụng giáo trình Movers', 'slide-buoi-1.pdf', 'common', true, false, true],
            ['Tranh minh họa Places in town', 'hinh-minh-hoa.png', 'lesson:4', true, true, true],
        ],
    ];

    private User $admin;

    private User $lead;

    public function run(): void
    {
        $this->admin = User::where('email', 'admin@menglish.edu.vn')->first();
        $this->lead = User::where('email', 'academiclead@menglish.edu.vn')->first();
        $curricula = SyllabusCurriculum::whereIn('code', array_keys(self::DOCUMENTS))->get()->keyBy('code');
        if (! $this->admin || ! $this->lead || $curricula->count() < count(self::DOCUMENTS)) {
            $this->command?->warn('DemoSyllabusLibrarySeeder: chưa có tài khoản / giáo trình DemoPhase2Seeder — bỏ qua.');

            return;
        }

        $originalRequest = app('request');
        try {
            foreach ($curricula as $code => $curriculum) {
                $this->enrichLessons($curriculum);
                $this->uploadDocuments($curriculum, self::DOCUMENTS[$code]);
            }
            $this->markViewed();
            $this->overrideClassLesson();
            $this->seedProposals($curricula['DEMO-SYL-STARTERS']);
            $this->importFlyers();
        } finally {
            app()->instance('request', $originalRequest);
            Auth::forgetUser();
        }
    }

    // ── Buổi theo form Sheet 1 ──────────────────────────────────────────────────

    private function enrichLessons(SyllabusCurriculum $curriculum): void
    {
        if ($curriculum->lessons()->whereNotNull('ppt_url')->exists()) {
            return;
        }

        $lastStage = $curriculum->stages()->max('position');
        $link = 'https://example.com/demo-syllabus/'.strtolower($curriculum->code);
        foreach ($curriculum->stages()->orderBy('position')->get() as $stage) {
            if ($stage->position === 1 && $curriculum->code === 'DEMO-SYL-STARTERS') {
                $this->asUser($this->lead, SyllabusController::class, 'updateStage', $stage->only(['name', 'description', 'overview_link', 'big_test_title', 'big_test_note'])
                    + ['notice_before_sessions' => 3], ['id' => $stage->id]);
            }
            $lessons = $stage->lessons()->get()->values();
            $last = $lessons->count() - 1;
            foreach ($lessons as $i => $lesson) {
                $type = match (true) {
                    $i === $last && $stage->position === $lastStage => 'big_test',
                    str_starts_with($lesson->title, 'Ôn tập') => 'review',
                    default => 'lesson',
                };
                $this->asUser($this->lead, SyllabusController::class, 'updateLesson', $lesson->only([
                    'session_no', 'title', 'objectives', 'content', 'vocabulary_focus', 'grammar_focus', 'homework_guide',
                ]) + [
                    'lesson_type' => $type,
                    'procedure' => 'Warm-up → Presentation → Practice → Production → Wrap-up',
                    'activity_1' => 'Trò chơi khởi động: '.$lesson->title,
                    'activity_2' => $type === 'lesson' ? 'Làm việc nhóm đôi, trình bày trước lớp' : null,
                    'homework_quizizz' => 'Quizizz ôn tập buổi '.$lesson->session_no,
                    'homework_video' => $type === 'lesson' ? 'Quay video 1 phút dùng từ vựng của buổi' : null,
                    'ppt_url' => $link.'/ppt-buoi-'.$lesson->session_no,
                    'ppt_foreign_url' => $type === 'lesson' ? $link.'/ppt-gvnn-buoi-'.$lesson->session_no : null,
                    'quizizz_url' => 'https://quizizz.com/admin/quiz/demo-'.strtolower($curriculum->code).'-'.$lesson->session_no,
                    'test_url' => $type === 'lesson' ? null : $link.'/de-buoi-'.$lesson->session_no,
                    'output_product' => $type === 'lesson' ? 'Phiếu bài làm / sản phẩm nhóm' : 'Bài kiểm tra đã chấm',
                    'notice_milestone' => match (true) {
                        $i === 0 => 'order_print',
                        $i === $last - 2 => 'order_big_test',
                        str_starts_with($lessons[$i + 1]->title ?? '', 'Ôn tập') => 'order_mini_test',
                        default => null,
                    },
                    'note' => $i === 0 ? 'Học vụ in tài liệu chặng trước buổi này.' : null,
                ], ['id' => $lesson->id]);
            }
        }
    }

    // ── Tài liệu ────────────────────────────────────────────────────────────────

    /** @param  list<array{0: string, 1: string, 2: string, 3: bool, 4: bool, 5: bool}>  $documents */
    private function uploadDocuments(SyllabusCurriculum $curriculum, array $documents): void
    {
        if (SyllabusDocument::where('curriculum_id', $curriculum->id)->exists()) {
            return;
        }

        foreach ($documents as [$title, $sample, $target, $teachers, $assistants, $downloadable]) {
            [$kind, $number] = explode(':', $target) + [1 => null];
            $this->asUser($this->lead, SyllabusController::class, 'storeDocument', [
                'curriculum_id' => $curriculum->id,
                'title' => $title,
                'stage_id' => $kind === 'stage' ? SyllabusStage::where('curriculum_id', $curriculum->id)->where('position', $number)->value('id') : null,
                'syllabus_lesson_id' => $kind === 'lesson' ? $curriculum->lessons()->where('session_no', $number)->value('id') : null,
                'file' => $this->sampleFile($sample),
                'visible_to_teachers' => $teachers,
                'visible_to_assistants' => $assistants,
                'downloadable' => $downloadable,
            ]);
        }
    }

    /** GV lớp Starters CG đánh dấu đã xem tài liệu đầu tiên mình được thấy. */
    private function markViewed(): void
    {
        $teacher = User::where('email', 'nguyenvanan@menglish.edu.vn')->first();
        $document = $teacher ? SyllabusDocument::visibleTo($teacher)->orderBy('id')->first() : null;
        if ($document && ! $document->viewedBy($teacher)) {
            $this->asUser($teacher, SyllabusController::class, 'markDocumentViewed', [], ['id' => $document->id]);
        }
    }

    /** Syllabus riêng của lớp: Học thuật chỉnh sửa thủ công 1 buổi cho lớp Starters CG FAM 0 (kèm lý do, GV được báo). */
    private function overrideClassLesson(): void
    {
        $class = ClassModel::where('code', 'DEMO-CG-FAM0')->first();
        $assignment = $class ? SyllabusAssignment::open()->where('class_id', $class->id)->first() : null;
        $lesson = $assignment ? SyllabusLesson::whereHas('unit', fn ($q) => $q->where('stage_id', $assignment->stage_id))->orderBy('session_no')->skip(1)->first() : null;
        if (! $lesson || ClassLessonOverride::where('class_id', $class->id)->exists()) {
            return;
        }
        $this->asUser($this->lead, SyllabusClassController::class, 'override', [
            'title' => $lesson->title,
            'content' => "{$lesson->title} — rút gọn phần đọc, thêm 10 phút luyện nghe theo cặp.",
            'activity_2' => 'Nghe – chép chính tả 5 từ vựng mới',
            'reason' => 'Lớp FAM 0 nghe yếu, cần thêm hoạt động nghe (demo chỉnh sửa thủ công).',
        ], ['class' => $class->id, 'lesson' => $lesson->id]);
    }

    private function sampleFile(string $name): UploadedFile
    {
        $path = sys_get_temp_dir().'/demo-syllabus-'.bin2hex(random_bytes(6)).'-'.$name;
        copy(resource_path(self::SAMPLES.'/'.$name), $path);

        return new UploadedFile($path, $name, null, null, true);
    }

    // ── Đề xuất sửa giáo trình ──────────────────────────────────────────────────

    private function seedProposals(SyllabusCurriculum $curriculum): void
    {
        $teacher = User::where('email', 'nguyenvanan@menglish.edu.vn')->first();
        if (! $teacher || SyllabusChangeProposal::where('curriculum_id', $curriculum->id)->exists()) {
            return;
        }

        $lesson = fn (int $sessionNo) => $curriculum->lessons()->where('session_no', $sessionNo)->firstOrFail();
        $propose = function (SyllabusLesson $target, string $newContent, string $reason) use ($teacher, $curriculum): SyllabusChangeProposal {
            $this->asUser($teacher, SyllabusController::class, 'storeProposal', [
                'curriculum_id' => $curriculum->id, 'lesson_id' => $target->id, 'proposal_type' => 'Nội dung buổi',
                'old_content' => $target->title, 'new_content' => $newContent, 'reason' => $reason,
            ]);

            return SyllabusChangeProposal::where('user_id', $teacher->id)->latest('id')->firstOrFail();
        };

        $propose($lesson(3), 'Tách "Màu sắc & số 1–10" thành 2 buổi, thêm trò chơi Bingo số.', 'HV nhỏ tuổi chưa kịp nhớ cả màu và số trong 1 buổi.');
        $approved = $propose($lesson(5), 'Thêm hoạt động mô tả người thân bằng ảnh HV mang tới.', 'HV hứng thú hơn khi nói về ảnh thật.');
        $this->asUser($this->admin, SyllabusController::class, 'approveProposal', ['review_note' => 'Đồng ý, Học thuật cập nhật syllabus bản sau.'], ['id' => $approved->id]);
        $rejected = $propose($lesson(9), 'Bỏ phần Can / can\'t, thay bằng bài hát.', 'Ngữ pháp hơi khó với lớp FAM 0.');
        $this->asUser($this->admin, SyllabusController::class, 'rejectProposal', ['review_note' => 'Can / can\'t là trọng tâm đề Starters — giữ nguyên, có thể thêm bài hát làm warm-up.'], ['id' => $rejected->id]);
    }

    // ── Giáo trình import từ gói zip ────────────────────────────────────────────

    private function importFlyers(): void
    {
        if (SyllabusCurriculum::withTrashed()->where('code', self::FLYERS)->exists()) {
            return;
        }

        $dir = sys_get_temp_dir().'/demo-syllabus-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dir.'/work');
        try {
            (new SyllabusSampleFiles)->flyersPackage($dir.'/flyers.zip', self::FLYERS, 'Cambridge Flyers (demo — import từ zip)');
            $plan = app(SyllabusPackageValidator::class)->validate(app(SyllabusPackageReader::class)->read($dir.'/flyers.zip', $dir.'/work'), true);
            if (! $plan->ok()) {
                throw new RuntimeException('DemoSyllabusLibrarySeeder: gói Flyers lỗi: '.implode(' ', $plan->errors));
            }
            Auth::setUser($this->lead); // lịch sử thay đổi ghi người import như khi import trên web
            app(SyllabusImporter::class)->import($plan, $this->lead, ['visible_to_teachers' => true, 'visible_to_assistants' => true, 'downloadable' => true]);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
