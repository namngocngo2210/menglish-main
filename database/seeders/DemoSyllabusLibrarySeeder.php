<?php

namespace Database\Seeders;

use App\Http\Controllers\StaffReportController;
use App\Http\Controllers\StudentPortalController;
use App\Http\Controllers\SyllabusClassController;
use App\Http\Controllers\SyllabusController;
use App\Http\Controllers\TeacherHomeworkController;
use App\Http\Controllers\TeacherLessonController;
use App\Http\Controllers\TeacherOrderController;
use App\Http\Controllers\TeacherPortalController;
use App\Http\Controllers\WorkTaskController;
use App\Models\ClassLessonOverride;
use App\Models\ClassLessonProgress;
use App\Models\ClassModel;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\StaffReport;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\SyllabusLesson;
use App\Models\SyllabusStage;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Reports\WeeklyReportMetrics;
use App\Services\Syllabus\ClassSyllabus;
use App\Services\Syllabus\ConfirmReading;
use App\Services\Syllabus\Import\SyllabusImporter;
use App\Services\Syllabus\Import\SyllabusPackageReader;
use App\Services\Syllabus\Import\SyllabusPackageValidator;
use App\Services\Syllabus\ProgressBackfill;
use App\Services\Syllabus\StageEndAlerts;
use App\Services\Syllabus\StageSchedule;
use App\Services\Syllabus\SyllabusOrders;
use App\Support\ReportPeriod;
use Database\Seeders\Concerns\InvokesControllersAsUser;
use Database\Seeders\Support\SyllabusSampleFiles;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
 * - Quy trình giai đoạn 2–5 đủ bước để mỗi vai trò có dữ liệu trên màn của mình: GV đã dạy / chưa đánh dấu, bản sửa riêng,
 *   Customize "Giãn" đã duyệt + "Dời" chờ duyệt, order theo mốc (in ấn → vận hành cơ sở, đề mini test → Học thuật) còn mốc
 *   đến hạn để nút ORDER nhấp nháy, nhắc 24h chưa xác nhận, việc cuối chặng mới / đang làm / quá hạn / hoàn thành, báo cáo
 *   tuần Học thuật có ghi chú của Quản lý, buổi mini test đã dạy chưa nhập điểm, homework 3 giỏ.
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
            $this->markMiniTests($curricula['DEMO-SYL-STARTERS']);
            $this->markViewed();
            $this->overrideClassLesson();
            $this->seedProgress();
            $this->seedStageEndTasks();
            $this->seedHomework();
            $this->seedCustomize();
            $this->seedOrders();
            $this->runReminders();
            $this->seedProposals($curricula['DEMO-SYL-STARTERS']);
            $this->seedAcademicWeekly();
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
                        $i === $last - 3 => 'notify_academic',
                        str_starts_with($lessons[$i + 1]->title ?? '', 'Ôn tập') => 'order_mini_test',
                        default => null,
                    },
                    'note' => $i === 0 ? 'Học vụ in tài liệu chặng trước buổi này.' : null,
                ], ['id' => $lesson->id]);
            }
        }
    }

    /**
     * Buổi "Ôn tập Unit 1–2" cuối chặng 1 Starters có làm mini test (như syllabus FAM 2): loại Mini test → mốc order mini test
     * có ngày thi, buổi test đã dạy chưa nhập điểm hiện "Chưa nhập điểm". Sửa thẳng loại buổi (chạy cả trên dữ liệu demo cũ).
     */
    private function markMiniTests(SyllabusCurriculum $curriculum): void
    {
        if ($curriculum->lessons()->where('lesson_type', 'mini_test')->exists()) {
            return;
        }
        $stage = $curriculum->stages()->where('position', 1)->first();
        $stage?->lessons()->where('syllabus_lessons.title', 'like', 'Ôn tập%')->get()->each->update(['lesson_type' => 'mini_test']);
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
            // Đã mở xem tài liệu từ 1 phút trước ("Đánh dấu đã xem" đòi mở đủ ConfirmReading::DOCUMENT_MIN_SECONDS giây).
            Cache::put(ConfirmReading::documentOpenKey($teacher, $document), now()->subMinute()->getTimestamp(), now()->addHour());
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

    /**
     * Tiến độ theo "Đã dạy" (docs/kho-syllabus.md §8): CG FAM 1 — GV bấm Đã dạy các buổi đã qua của chặng 2 (1 buổi có ghi chú
     * dạy chậm), chừa buổi gần nhất chưa đánh dấu để thử nhắc; lớp khác đánh dấu sẵn theo buổi đã học. BD FAM 1 có yêu cầu
     * Customize syllabus "Dời tiến độ" chờ duyệt.
     */
    private function seedProgress(): void
    {
        $class = ClassModel::where('code', 'DEMO-CG-FAM1')->first();
        $assignment = $class ? SyllabusAssignment::open()->where('class_id', $class->id)->first() : null;
        $teacher = User::where('email', 'nguyenvanan@menglish.edu.vn')->first();
        if ($assignment && $teacher && ! ClassLessonProgress::where('syllabus_assignment_id', $assignment->id)->exists()) {
            $past = app(StageSchedule::class)->sessions($class, $assignment->start_date ?? $assignment->opened_at)
                ->filter(fn ($s) => $s->date->lt(today()))->values();
            foreach ($past->slice(0, max(0, $past->count() - 1))->values() as $i => $session) {
                $this->asUser($teacher, TeacherLessonController::class, 'markTaught', [
                    'class_session_id' => $session->id,
                    'pace_note' => $i === 0 ? 'Lớp chậm phần Speaking, cần thêm 10 phút ôn ở buổi sau.' : null,
                ], ['class' => $class->id]);
            }
        }
        app(ProgressBackfill::class)->run();

        $bd = ClassModel::where('code', 'DEMO-BD-FAM1')->first();
        $bdTeacher = User::where('email', 'gv.cohuu2@menglish.edu.vn')->first();
        if ($bd && $bdTeacher && SyllabusAssignment::open()->where('class_id', $bd->id)->exists()
            && ! SyllabusAdjustmentRequest::where('class_id', $bd->id)->where('kind', 'postpone')->exists()) {
            $this->asUser($bdTeacher, SyllabusController::class, 'storeAdjustmentRequest', [
                'class_id' => $bd->id, 'kind' => 'postpone', 'extra_sessions' => 1,
                'reason' => 'Lớp nghỉ 1 buổi do mất điện, cần dời tiến độ 1 buổi (demo Customize syllabus).',
            ]);
        }
    }

    /** Việc cuối chặng cho Học thuật (docs/kho-syllabus.md §9.2): tạo theo tiến độ demo, Học thuật đã NHẬN VIỆC việc của CG FAM 1. */
    private function seedStageEndTasks(): void
    {
        app(StageEndAlerts::class)->run();
        $task = WorkTask::where('kind', WorkTask::KIND_SYLLABUS_STAGE_END)->where('status', 'new')
            ->whereHas('classModel', fn ($q) => $q->where('code', 'DEMO-CG-FAM1'))->first();
        if ($task) {
            $this->asUser(User::findOrFail($task->assignee_id), WorkTaskController::class, 'accept', [], ['id' => $task->id]);
        }
    }

    /**
     * Homework 3 giỏ (docs/kho-syllabus.md §10): GV CG FAM 1 giao 2 bài theo syllabus (buổi vừa dạy + buổi hiện tại); học viên nộp
     * ảnh bài giấy / video / bấm "Đã làm" Quizizz (nhân sự nộp hộ); GV đã xem 1 bài.
     */
    private function seedHomework(): void
    {
        $class = ClassModel::where('code', 'DEMO-CG-FAM1')->first();
        $assignment = $class ? SyllabusAssignment::open()->where('class_id', $class->id)->first() : null;
        $teacher = User::where('email', 'nguyenvanan@menglish.edu.vn')->first();
        $admin = User::where('email', 'admin@menglish.edu.vn')->first();
        if (! $assignment || ! $teacher || ! $admin || Homework::where('class_id', $class->id)->where('title', 'like', 'Bài tập buổi%')->exists()) {
            return;
        }
        $rows = app(ClassSyllabus::class)->timeline($assignment)['rows']->values();
        $current = $rows->search(fn ($r) => $r['taught_at'] === null);
        $picked = collect([$current === false ? $rows->count() - 1 : $current - 1, $current])
            ->filter(fn ($i) => is_int($i) && $i >= 0 && isset($rows[$i]))->unique();

        $homeworks = $picked->map(function (int $i) use ($rows, $class, $teacher) {
            $row = $rows[$i];
            $sessionId = $row['taught_session_id'] ?? $row['planned_session_id'];
            if (! $sessionId) {
                return null;
            }
            $items = array_filter([
                'workbook' => $row['data']['homework_guide'] ?? 'Workbook trang '.(2 * $row['lesson']->session_no).'–'.(2 * $row['lesson']->session_no + 1),
                'quiz' => $row['data']['homework_quizizz'] ?? 'Quizizz ôn từ vựng buổi '.$row['lesson']->session_no,
                'video' => $row['data']['homework_video'] ?? 'Quay video đọc to đoạn hội thoại của bài',
            ]);
            $this->asUser($teacher, TeacherPortalController::class, 'homeworkStore', [
                'class_session_id' => $sessionId, 'title' => 'Bài tập buổi '.$row['lesson']->session_no.': '.$row['data']['title'],
                'categories' => array_keys($items), 'items' => $items, 'quizizz_url' => $row['data']['quizizz_url'] ?? 'https://quizizz.com/join?gc=123456',
                'due_at' => ($row['taught_at'] ?? $row['planned_date'] ?? today())->copy()->addDays(3)->setTime(20, 0)->toDateTimeString(),
            ], ['classId' => $class->id]);

            return Homework::where('class_id', $class->id)->latest('id')->first();
        })->filter()->values();

        $students = $class->rosterStudents()->take(3)->values();
        $first = $homeworks->first();
        if (! $first || $students->isEmpty()) {
            return;
        }
        $submit = fn ($student, string $basket, array $input = []) => $this->asUser($admin, StudentPortalController::class, 'submitBasket',
            ['student_id' => $student->id] + $input, ['homework' => $first->id, 'basket' => $basket]);
        $submit($students[0], 'paper', ['file' => $this->sampleFile('hinh-minh-hoa.png'), 'note' => 'Con làm xong Workbook rồi ạ.']);
        $submit($students[0], 'quizizz');
        if (isset($students[1])) {
            $submit($students[1], 'video', ['file' => $this->sampleFile('video-mau.mp4')]);
        }
        $paper = HomeworkSubmission::where('homework_id', $first->id)->where('basket', 'paper')->first();
        if ($paper) {
            $this->asUser($teacher, TeacherHomeworkController::class, 'review', [], ['submission' => $paper->id]);
        }
    }

    /** Customize syllabus "Giãn" (docs/kho-syllabus.md §8.4): GV CG FAM 0 xin thêm 1 buổi, Admin duyệt → lớp có buổi giãn cho nút "Dạy chưa xong". */
    private function seedCustomize(): void
    {
        $class = ClassModel::where('code', 'DEMO-CG-FAM0')->first();
        $teacher = User::where('email', 'nguyenvanan@menglish.edu.vn')->first();
        if (! $class || ! $teacher || ! SyllabusAssignment::open()->where('class_id', $class->id)->exists()
            || SyllabusAdjustmentRequest::where('class_id', $class->id)->where('kind', 'extend')->exists()) {
            return;
        }
        $this->asUser($teacher, SyllabusController::class, 'storeAdjustmentRequest', [
            'class_id' => $class->id, 'kind' => 'extend', 'extra_sessions' => 1,
            'reason' => 'Lớp FAM 0 cần thêm 1 buổi luyện nghe trước mini test Unit 1–2 (demo Customize syllabus "Giãn").',
        ]);
        $request = SyllabusAdjustmentRequest::where('class_id', $class->id)->where('kind', 'extend')->latest('id')->firstOrFail();
        $this->asUser($this->admin, SyllabusController::class, 'approveAdjustmentRequest', [], ['id' => $request->id]);
    }

    /**
     * ORDER theo mốc (docs/kho-syllabus.md §9.3): GV CG FAM 0 order in ấn (→ vận hành cơ sở), GV BD FAM 0 order đề mini test
     * (→ Học thuật duyệt). Mốc order mini test của CG FAM 0 để lại chưa order → nút ORDER của GV nhấp nháy.
     */
    private function seedOrders(): void
    {
        $place = function (string $classCode, string $email, string $kind, array $input): void {
            $class = ClassModel::where('code', $classCode)->first();
            $teacher = User::where('email', $email)->first();
            $assignment = $class && $teacher ? SyllabusAssignment::open()->where('class_id', $class->id)->first() : null;
            $milestone = $assignment ? app(SyllabusOrders::class)->milestones($assignment)->first(fn (array $m) => $m['kind'] === $kind && $m['due'] && ! $m['ordered']) : null;
            if ($milestone) {
                $this->asUser($teacher, TeacherOrderController::class, 'store', ['lesson_id' => $milestone['lesson']->id] + $input, ['class' => $class->id]);
            }
        };
        $place('DEMO-CG-FAM0', 'nguyenvanan@menglish.edu.vn', 'order_print', ['quantity' => 8, 'note' => 'In phiếu mini test Unit 1–2: 6 học viên + 2 bản dự phòng.']);
        $place('DEMO-BD-FAM0', 'gv.cohuu2@menglish.edu.vn', 'order_mini_test', ['note' => 'Đề mini test Unit 1–2, lớp làm đầu buổi Ôn tập.']);
    }

    /** Lệnh định kỳ của quy trình (idempotent): nhắc 24h chưa xác nhận, chuyển việc quá hạn + nhắc XỬ LÝ, báo mốc order đến hạn. */
    private function runReminders(): void
    {
        // Demo giao chặng ngay lúc seed: lùi lúc giao chặng BD FAM 1 (chưa xác nhận) về ngày mở chặng để có nhắc 24h; BD FAM 0 giữ chưa nhắc.
        SyllabusAssignment::open()->whereNull('confirmed_at')->whereNull('confirm_reminded_at')
            ->whereHas('classModel', fn ($q) => $q->where('code', 'DEMO-BD-FAM1'))->get()
            ->each(fn (SyllabusAssignment $a) => $a->update(['assigned_at' => $a->opened_at]));
        foreach (['syllabus:remind-unconfirmed', 'tasks:mark-overdue', 'syllabus:remind-overdue-tasks', 'syllabus:order-due-alerts'] as $command) {
            Artisan::call($command);
        }
    }

    /**
     * Báo cáo tuần Học thuật (docs/kho-syllabus.md §9.2): Học thuật HOÀN THÀNH việc chuẩn bị chặng của CG FAM 0, nộp báo cáo tuần
     * từ dòng gợi ý của hệ thống; Quản lý ghi chú, Học thuật trả lời.
     */
    private function seedAcademicWeekly(): void
    {
        $week = ReportPeriod::weekKey(now());
        $manager = User::where('email', 'manager@menglish.edu.vn')->first();
        if (! $manager || StaffReport::where('user_id', $this->lead->id)->where('kind', 'academic_weekly')->where('period_key', $week)->exists()) {
            return;
        }

        $task = WorkTask::where('kind', WorkTask::KIND_SYLLABUS_STAGE_END)->where('assignee_id', $this->lead->id)->where('status', 'new')
            ->whereHas('classModel', fn ($q) => $q->where('code', 'DEMO-CG-FAM0'))->first();
        if ($task) {
            $this->asUser($this->lead, WorkTaskController::class, 'accept', [], ['id' => $task->id]);
            $this->asUser($this->lead, WorkTaskController::class, 'completeTask', ['note' => 'Đã gửi đề Big Test chặng 1 cho Học vụ in, syllabus chặng 2 sẵn sàng giao.'], ['id' => $task->id]);
        }

        $rows = collect(WeeklyReportMetrics::academic($this->lead, $week)['suggestions'])
            ->map(fn (array $items) => collect($items)->map(fn (array $row) => Arr::only($row, ['content', 'detail', 'deadline', 'progress', 'link', 'source']))->all())
            ->all();
        $rows['planned'][] = ['content' => 'Giao chặng 2 cho lớp Pre Starters FAM 0 (CG) sau Big Test chặng 1', 'deadline' => today()->addWeek()->toDateString(), 'progress' => 0];
        $this->asUser($this->lead, StaffReportController::class, 'academicWeeklyStore', ['week' => $week, 'rows' => $rows]);

        $report = StaffReport::where('user_id', $this->lead->id)->where('kind', 'academic_weekly')->where('period_key', $week)->firstOrFail();
        $this->asUser($manager, StaffReportController::class, 'noteStore', ['note' => 'Việc chuẩn bị chặng lớp BD FAM 1 đã quá hạn — ưu tiên xử lý đầu tuần sau.'], ['id' => $report->id]);
        $this->asUser($this->lead, StaffReportController::class, 'noteStore', ['note' => 'Đã nhận, thứ Hai sẽ chốt đề Big Test BD FAM 1 với GV.'], ['id' => $report->id]);
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
