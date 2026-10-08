<?php

namespace Database\Seeders;

use App\Http\Controllers\AcademicProjectController;
use App\Http\Controllers\ClassManagementController;
use App\Http\Controllers\KpiController;
use App\Http\Controllers\StudentPortalController;
use App\Http\Controllers\StudentProfileController;
use App\Http\Controllers\SurveyController;
use App\Http\Controllers\SyllabusController;
use App\Http\Controllers\TeacherPortalController;
use App\Http\Controllers\WorkTaskController;
use App\Models\AcademicProject;
use App\Models\AcademicProjectUpdate;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassReportStudentSupport;
use App\Models\ClassSession;
use App\Models\Room;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SupportSession;
use App\Models\Survey;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusChangeProposal;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\SyllabusLesson;
use App\Models\User;
use App\Services\AdjustmentSlaService;
use App\Services\SessionScheduleService;
use App\Services\SupportListService;
use Closure;
use Database\Seeders\Concerns\InvokesControllersAsUser;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dữ liệu demo "toàn case" khu Đào tạo (lớp, học viên, buổi học, giáo trình, khảo sát, dự án học thuật), cả tốt lẫn xấu.
 * Mốc thời gian tương đối với hôm nay (R); mọi sự kiện đều đã xảy ra trước R và đi qua controller / service thật:
 * - Lớp DEMO-CG-FAM1-K24 (Starters, Cầu Giấy) ĐÃ KẾT THÚC: tạo lớp kèm TKB T7/CN ~4 tháng trước, 6 HV xếp lớp + bàn giao,
 *   điểm danh + nhận xét đủ mọi buổi (vài buổi vắng / muộn, Học vụ duyệt điểm danh), 3 chặng giáo trình mở → đóng lần lượt,
 *   GV xin giãn tiến độ +2 buổi được Admin duyệt (lịch tự nối thêm 2 buổi), 1 buổi dạy thay (GV khác đứng lớp, đã duyệt),
 *   1 đề nghị dạy thay bị từ chối, 1 buổi bổ trợ đã xếp rồi bị HỦY. Cuối khóa: 4 HV Hoàn thành, 1 HV Thôi học giữa khóa
 *   (lượt xếp lớp → dropped), 1 HV Nghỉ hè (vắng có phép các buổi cuối); khảo sát cuối khóa đã đóng, có điểm thấp.
 * - Lớp DEMO-BD-FAM2-K29 (Movers, Ba Đình) ĐÃ HỦY vì không đủ sĩ số: 1 HV chuyển sang lớp DEMO-BD-FAM2 (xếp lớp mới, lượt cũ
 *   ghi ngày rời lớp), 1 HV Thôi học.
 * - Lớp DEMO-DD-FAM0-K29 (Pre Starters, Đống Đa) CHỜ XẾP LỊCH: 3 HV Chờ khai giảng (2 bàn giao xong, 1 còn chờ bàn giao).
 * - Dạy thay (bảng substitute_sessions, chưa có màn hình / model): đã duyệt, đã từ chối, đang chờ duyệt.
 * - Tài liệu giáo trình (PDF / ảnh) với phạm vi xem khác nhau + lượt "đã xem" của GV / trợ giảng; đề xuất sửa giáo trình chờ
 *   duyệt / đã duyệt / bị từ chối (1 đề xuất có file đính kèm); xin giãn tiến độ: đã duyệt, bị từ chối, chờ duyệt quá hạn SLA
 *   (đã báo Admin), chờ duyệt trong hạn.
 * - Khảo sát: 1 đợt đã đóng (cuối khóa K24), 1 đợt đang mở có phản hồi PH/HV (có 1–2 sao), 1 đợt hết hạn hôm nay chưa ai làm.
 * - Dự án học thuật: thêm thành viên + cập nhật tiến độ cho 2 dự án của DemoAcademicKpiSeeder (nếu có, không đổi mốc), và
 *   3 dự án mới: Hoàn thành (1 mốc trễ 2 ngày), Tạm dừng (mốc dở dang, có khó khăn), Đã hủy khi còn lên kế hoạch.
 *
 * Gọi từ DemoCoverageSeeder (php artisan demo:luong); chạy được trên dữ liệu db:seed thuần lẫn sau demo:luong. Idempotent:
 * đã có lớp DEMO-CG-FAM1-K24 thì bỏ qua toàn bộ. Chỉ thêm dữ liệu (học viên / lớp / buổi mới); bản ghi có sẵn chỉ được thêm
 * dòng liên quan (yêu cầu giãn tiến độ, thành viên + cập nhật dự án, HV chuyển vào lớp DEMO-BD-FAM2).
 */
class DemoCoverageTrainingSeeder extends Seeder
{
    use InvokesControllersAsUser;

    /** Mã lớp đã kết thúc — dấu hiệu seeder đã chạy. */
    public const MARKER = 'DEMO-CG-FAM1-K24';

    public const CANCELLED_CLASS = 'DEMO-BD-FAM2-K29';

    public const PENDING_CLASS = 'DEMO-DD-FAM0-K29';

    /** Tài khoản mẫu (UserSeeder) theo vai. */
    private const STAFF = [
        'admin' => 'admin@menglish.edu.vn',
        'lead' => 'academiclead@menglish.edu.vn',
        'manager_cg' => 'manager@menglish.edu.vn',
        'manager_bd' => 'manager.bd@menglish.edu.vn',
        'academic_cg' => 'nva@menglish.edu.vn',
        'academic_bd' => 'giaovu2@menglish.edu.vn',
        'teacher_k24' => 'gv.cohuu1@menglish.edu.vn',
        'teacher_cg' => 'nguyenvanan@menglish.edu.vn',
        'teacher_bd' => 'gv.cohuu2@menglish.edu.vn',
        'teacher_dd' => 'gv.banthoigian1@menglish.edu.vn',
        'native' => 'gv.native1@menglish.edu.vn',
        'assistant' => 'ta.tuan@menglish.edu.vn',
        'student1' => 'hocvien1@menglish.edu.vn',
        'student2' => 'hocvien2@menglish.edu.vn',
        'student3' => 'hocvien3@menglish.edu.vn',
    ];

    /** Học vụ Đống Đa (có khi đã chạy demo:luong); không có thì Admin làm thay. */
    private const ACADEMIC_DD = 'giaovu.dd@menglish.edu.vn';

    /** Lớp mẫu sẵn có (DemoPhase1Seeder) dùng làm lớp chuyển đến / lớp xin giãn tiến độ. */
    private const EXISTING_CLASSES = ['DEMO-CG-FAM1', 'DEMO-CG-FAM0', 'DEMO-BD-FAM1', 'DEMO-BD-FAM0', 'DEMO-BD-FAM2'];

    /** Học viên mới: [mã, tên, giới tính, năm sinh, tên PH, ghi chú]. */
    private const K24_STUDENTS = [
        ['HV-DEMO-K24-01', 'Nguyễn Gia Bảo', 'Nam', 2017, 'Nguyễn Văn Hùng', null],
        ['HV-DEMO-K24-02', 'Trần Khánh Vy', 'Nữ', 2017, 'Trần Thu Hà', null],
        ['HV-DEMO-K24-03', 'Lê Minh Đức', 'Nam', 2016, 'Lê Thị Lan', null],
        ['HV-DEMO-K24-04', 'Phạm Bảo Châu', 'Nữ', 2017, 'Phạm Quốc Tuấn', null],
        ['HV-DEMO-K24-05', 'Hoàng An Nhiên', 'Nữ', 2016, 'Hoàng Mai Anh', null],
        ['HV-DEMO-K24-06', 'Vũ Đức Thịnh', 'Nam', 2016, 'Vũ Minh Quang', null],
    ];

    private const BD_STUDENTS = [
        ['HV-DEMO-K29BD-01', 'Đỗ Quang Huy', 'Nam', 2015, 'Đỗ Thanh Bình', 'PH muốn học ca sáng Chủ nhật.'],
        ['HV-DEMO-K29BD-02', 'Ngô Phương Thảo', 'Nữ', 2015, 'Ngô Văn Lực', null],
    ];

    private const DD_STUDENTS = [
        ['HV-DEMO-K29DD-01', 'Bùi Tuấn Kiệt', 'Nam', 2019, 'Bùi Thị Hạnh', null],
        ['HV-DEMO-K29DD-02', 'Đinh Ngọc Hân', 'Nữ', 2019, 'Đinh Công Sơn', null],
        ['HV-DEMO-K29DD-03', 'Lương Gia Hưng', 'Nam', 2018, 'Lương Thị Thủy', 'Chưa nhận giáo trình, PH hẹn lên lấy cuối tuần.'],
    ];

    /** HV lớp K24 nghỉ hè từ buổi thứ (index) / thôi học sau buổi thứ (index) — 0-based theo thứ tự buổi. */
    private const K24_SUMMER = [4, 20];

    private const K24_DROP = [5, 9];

    /** Điểm danh khác "có mặt" của lớp K24: [buổi, HV] => [trạng thái, ghi chú]. */
    private const K24_ATTENDANCE = [
        '2:0' => ['late', null],
        '3:1' => ['absent', 'Ốm sốt, PH báo muộn sau giờ học'],
        '6:2' => ['excused', 'PH xin nghỉ đi đám cưới ở quê'],
        '7:3' => ['late', null],
        '9:5' => ['absent', 'Nghỉ không báo, Học vụ gọi PH không nghe máy'],
        '11:1' => ['late', null],
        '12:0' => ['excused', 'Đi thi Violympic cấp quận'],
        '16:3' => ['absent', 'Nghỉ không báo trước'],
        '19:2' => ['late', null],
    ];

    /** Khảo sát cuối khóa K24 (Học vụ nhập từ phiếu giấy): HV => [sao, ý kiến]. */
    private const K24_SURVEY = [
        0 => [5, 'Con tiến bộ rõ, tự tin nói tiếng Anh hơn. Cảm ơn thầy Quốc Anh.'],
        1 => [4, 'Giáo viên nhiệt tình; mong có thêm bài tập về nhà dạng trò chơi.'],
        2 => [5, 'Lớp ít học viên nên con được nói nhiều, rất hài lòng.'],
        3 => [3, 'Bổ trợ cho buổi con vắng bị hủy, không được xếp lại. Phòng học buổi chiều hơi nóng.'],
        4 => [4, 'Con nghỉ hè 2 tuần cuối, mong trung tâm gửi tài liệu ôn để con theo kịp lớp mới.'],
        5 => [2, 'Lịch cuối tuần khó sắp xếp, con theo không kịp nên gia đình cho nghỉ. Học vụ phản hồi chậm.'],
    ];

    private Carbon $realNow;

    /** @var array<string, User> */
    private array $staff = [];

    /** @var array<string, ClassModel> */
    private array $classes = [];

    /** @var array<string, Branch> */
    private array $branches = [];

    /** @var array<string, mixed> Tham chiếu dùng chung giữa các sự kiện (lớp mới, học viên, id vừa tạo). */
    private array $refs = [];

    /** @var list<array{0: Carbon, 1: int, 2: Closure}> Hàng đợi sự kiện theo thời gian. */
    private array $queue = [];

    private int $sequence = 0;

    public function run(): void
    {
        if (ClassModel::withTrashed()->where('code', self::MARKER)->exists()) {
            $this->command?->info('DemoCoverageTrainingSeeder: đã có dữ liệu demo Đào tạo — bỏ qua.');

            return;
        }
        if (! $this->loadPrerequisites()) {
            return;
        }

        $previousTestNow = Carbon::getTestNow();
        $originalRequest = app('request');
        $this->realNow = now()->copy();

        try {
            DB::transaction(function () {
                $this->planCompletedClass();
                $this->planCancelledClass();
                $this->planPendingClass();
                $this->planPendingSubstitute();
                $this->planDocuments();
                $this->planProposals();
                $this->planAdjustments();
                $this->planSurveys();
                $this->planProjects();
                $this->planExistingProjects();
                $this->runQueue();
            });
        } finally {
            Carbon::setTestNow($previousTestNow);
            app()->instance('request', $originalRequest);
            Auth::forgetUser();
        }

        $this->printSummary();
    }

    private function loadPrerequisites(): bool
    {
        foreach (self::STAFF as $key => $email) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                $this->command?->warn("DemoCoverageTrainingSeeder: thiếu tài khoản mẫu {$email} — bỏ qua.");

                return false;
            }
            $this->staff[$key] = $user;
        }
        $this->staff['academic_dd'] = User::where('email', self::ACADEMIC_DD)->where('is_active', true)->first() ?? $this->staff['admin'];

        foreach (['CG', 'BD', 'DD'] as $code) {
            $branch = Branch::where('code', $code)->first();
            if (! $branch) {
                $this->command?->warn("DemoCoverageTrainingSeeder: thiếu chi nhánh {$code} — bỏ qua.");

                return false;
            }
            $this->branches[$code] = $branch;
        }

        foreach (self::EXISTING_CLASSES as $code) {
            $class = ClassModel::where('code', $code)->first();
            if (! $class) {
                $this->command?->warn("DemoCoverageTrainingSeeder: thiếu lớp mẫu {$code} (DemoPhase1Seeder) — bỏ qua.");

                return false;
            }
            $this->classes[$code] = $class;
        }

        if (SyllabusCurriculum::whereIn('code', ['DEMO-SYL-STARTERS', 'DEMO-SYL-MOVERS'])->count() < 2
            || ! Room::where('branch_id', $this->branches['CG']->id)->where('name', 'Phòng 101')->exists()) {
            $this->command?->warn('DemoCoverageTrainingSeeder: thiếu giáo trình / phòng học mẫu (DemoPhase1–2Seeder) — bỏ qua.');

            return false;
        }

        return true;
    }

    // ── Hàng đợi sự kiện (đặt đồng hồ về đúng thời điểm rồi gọi controller) ──────────────────────

    /** Lên lịch sự kiện; sự kiện chưa tới (sau "bây giờ" thật) thì bỏ. */
    private function at(Carbon $at, Closure $callback): void
    {
        if ($at->lt($this->realNow)) {
            $this->queue[] = [$at->copy(), $this->sequence++, $callback];
        }
    }

    private function runQueue(): void
    {
        while ($this->queue !== []) {
            usort($this->queue, fn (array $a, array $b) => [$a[0]->getTimestamp(), $a[1]] <=> [$b[0]->getTimestamp(), $b[1]]);
            [$at, , $callback] = array_shift($this->queue);
            Carbon::setTestNow($at);
            $callback();
        }
        Carbon::setTestNow($this->realNow);
    }

    /** Ngày lệch $days ngày so với hôm nay thật, lúc $time. */
    private function daysAgo(int|float $days, string $time = '09:00'): Carbon
    {
        return $this->realNow->copy()->startOfDay()->subDays((int) $days)->setTimeFromTimeString($time);
    }

    // ── Học viên ─────────────────────────────────────────────────────────────

    /** Hồ sơ học viên (chỉ tạo khi chốt từ CRM — không có service riêng nên tạo trực tiếp như DemoPhase1Seeder). */
    private function createStudent(array $spec, Branch $branch, int $phoneSeed, string $target): Student
    {
        [$code, $name, $gender, $year, $parent, $notes] = $spec;

        return Student::create([
            'code' => $code,
            'name' => '# '.$name,
            'phone' => sprintf('0397%06d', $phoneSeed),
            'parent_name' => $parent,
            'parent_phone' => sprintf('0396%06d', $phoneSeed),
            'gender' => $gender,
            'dob' => Carbon::create($year, ($phoneSeed % 12) + 1, ($phoneSeed % 27) + 1)->toDateString(),
            'branch_id' => $branch->id,
            'target' => $target,
            'total_lessons' => 24,
            'status' => Student::INITIAL_STATUS,
            'notes' => $notes,
        ]);
    }

    /** Xếp lớp ở màn Học viên (lượt "pending"); đổi lớp chính khi HV đang có lớp = chuyển lớp. */
    private function enroll(User $actor, Student $student, ClassModel $class): ClassEnrollment
    {
        $this->asUser($actor, StudentProfileController::class, 'storeEnrollment', ['student_id' => $student->id, 'class_id' => $class->id]);

        return ClassEnrollment::where('student_id', $student->id)->where('class_id', $class->id)->latest('id')->firstOrFail();
    }

    /** Bàn giao (giáo trình + nhóm Zalo); đủ cả hai → lượt xếp lớp "completed" (lớp đã khai giảng → HV Đang học). */
    private function handoff(User $actor, ClassEnrollment $enrollment, bool $curriculum = true, bool $zalo = true): void
    {
        $this->asUser($actor, StudentProfileController::class, 'updateEnrollmentHandoff', array_filter([
            'curriculum_delivered' => $curriculum ? '1' : null,
            'zalo_group_added' => $zalo ? '1' : null,
        ]), ['id' => $enrollment->id]);
    }

    private function changeStatus(User $actor, Student $student, string $status, ?string $note = null): void
    {
        if ($note) {
            $student->refresh();
            $this->asUser($actor, StudentProfileController::class, 'updateStudent', [
                'name' => $student->name, 'phone' => $student->phone, 'parent_name' => $student->parent_name,
                'parent_phone' => $student->parent_phone, 'target' => $student->target, 'notes' => $note,
            ], ['id' => $student->id]);
        }
        $this->asUser($actor, StudentProfileController::class, 'updateStudentStatus', ['status' => $status], ['id' => $student->id]);
    }

    /** Sửa lớp (đổi trạng thái / ghi chú) qua form Sửa lớp — gửi lại các trường bắt buộc như form. */
    private function updateClass(User $actor, ClassModel $class, array $changes): void
    {
        $class->refresh();
        $this->asUser($actor, ClassManagementController::class, 'update', $changes + [
            'ten_lop' => $class->name,
            'ma_lop' => $class->code,
            'chi_nhanh' => $class->branch_id,
            'chuong_trinh' => $class->program,
            'cap_do' => $class->level,
            'si_so_toi_da' => $class->max_capacity,
            'min_students' => $class->min_students,
        ], ['id' => $class->id]);
    }

    // ── Lớp đã kết thúc K24 (Cầu Giấy) ──────────────────────────────────────

    private function planCompletedClass(): void
    {
        $t0 = $this->realNow->copy()->startOfWeek()->subWeeks(16);
        $firstSat = $t0->copy()->addDays(5);
        $lastSun = $t0->copy()->addWeeks(10)->addDays(6);
        $cg = $this->branches['CG'];

        $this->at($t0->copy()->subDays(12)->setTime(10, 0), function () use ($firstSat, $lastSun, $cg) {
            $slots = [
                ['day' => 'Thứ 7', 'start' => '14:00', 'end' => '15:30', 'shift' => 'Ca chiều'],
                ['day' => 'Chủ nhật', 'start' => '14:00', 'end' => '15:30', 'shift' => 'Ca chiều'],
            ];
            $sessions = collect(app(SessionScheduleService::class)->generate($slots, $firstSat, $lastSun, $cg->id))
                ->map(fn (array $s) => ['date' => $s['date'], 'shift' => $s['shift'], 'start' => $s['start'], 'end' => $s['end']])->values()->all();
            $this->asUser($this->staff['manager_cg'], ClassManagementController::class, 'store', [
                'ten_lop' => '# Starters FAM 1 · K24 (CG)',
                'ma_lop' => self::MARKER,
                'chi_nhanh' => $cg->id,
                'chuong_trinh' => '# Starters (FAM 1)',
                'cap_do' => 'STARTERS (FAM 1)',
                'si_so_toi_da' => 10,
                'min_students' => 5,
                'room_id' => Room::where('branch_id', $cg->id)->where('name', 'Phòng 101')->value('id'),
                'giao_vien_chinh' => $this->staff['teacher_k24']->id,
                'hoc_phi' => 9000000,
                'ghi_chu' => 'Lớp cuối tuần ca chiều, 24 buổi (12 tuần).',
                'schedule_sessions_json' => json_encode($sessions),
            ]);
            $class = ClassModel::where('code', self::MARKER)->firstOrFail();
            $this->refs['k24'] = $class;
            foreach ($class->sessions()->orderBy('date')->get()->values() as $index => $session) {
                $this->planK24Session($session, $index);
            }
        });

        // Tuyển sinh: tạo hồ sơ + xếp lớp trước khai giảng; ngày khai giảng Học vụ bàn giao giáo trình + nhóm Zalo.
        foreach (self::K24_STUDENTS as $i => $spec) {
            $this->at($t0->copy()->subDays(10 - $i)->setTime(9, 30), function () use ($i, $spec, $cg) {
                $student = $this->createStudent($spec, $cg, 2400 + $i, 'Starters (Pre-A1)');
                $this->refs['k24_students'][$i] = $student;
                $this->refs['k24_enrollments'][$i] = $this->enroll($this->staff['academic_cg'], $student, $this->refs['k24']);
            });
        }
        $this->at($firstSat->copy()->setTime(8, 0), function () use ($firstSat) {
            $this->asUser($this->staff['lead'], SyllabusController::class, 'storeAssignment', [
                'class_id' => $this->refs['k24']->id, 'start_date' => $firstSat->toDateString(),
            ]);
        });
        $this->at($firstSat->copy()->setTime(9, 0), function () {
            foreach ($this->refs['k24_enrollments'] as $enrollment) {
                $this->handoff($this->staff['academic_cg'], $enrollment);
            }
        });
    }

    /** Một buổi lớp K24: điểm danh + nhận xét của người đứng lớp, Học vụ duyệt điểm danh; các mốc giữa khóa. */
    private function planK24Session(ClassSession $session, int $index): void
    {
        $start = $session->startsAt();
        $day = $session->date->copy();

        if ($index === 8) {
            $this->planSubstitute($session, approved: true);
        } elseif ($index === 14) {
            $this->planSubstitute($session, approved: false);
        }

        $this->at($start->copy()->addMinutes(95), fn () => $this->k24Attendance($session->fresh(), $index));
        $this->at($day->copy()->setTime(20, 30), function () use ($session, $index) {
            $session->refresh();
            $class = $this->refs['k24'];
            $remarks = $class->rosterStudents()->values()->mapWithKeys(fn (Student $s, int $j) => [$s->id => [
                'monsters_group' => '+'.(($index + $j) % 4 + 2),
                'monsters_bonus' => ($index + $j) % 3 === 0 ? '+1' : '',
                'grammar' => ['Tốt', 'Khá', 'Cần cố gắng'][($index + $j) % 3],
                'attitude' => ['Hăng hái', 'Tập trung', 'Hơi mất tập trung'][($index * 2 + $j) % 3],
                'result' => ['Đạt mục tiêu buổi', 'Đạt 8/10 bài tập', 'Cần ôn lại từ vựng'][($index + 2 * $j) % 3],
                'comment' => 'Buổi '.($index + 1).': '.trim(Str::after($s->name, '#')).' '.['phát âm tốt, chủ động giơ tay.', 'làm bài nhanh, cần viết cẩn thận hơn.', 'cần luyện thêm từ vựng ở nhà.'][($index + $j) % 3],
            ]])->all();
            $this->asUser(User::find($session->teacher_id), TeacherPortalController::class, 'remarksStore', [
                'class_session_id' => $session->id, 'remarks' => $remarks,
            ], ['classId' => $class->id]);
        });
        // Học vụ duyệt điểm danh 2 ngày sau buổi (cập nhật số buổi đã học của HV).
        $this->at($day->copy()->addDays(2)->setTime(10, 0), function () use ($session) {
            foreach (StudentAttendance::where('class_session_id', $session->id)->where('review_status', 'pending_review')->get() as $attendance) {
                $this->asUser($this->staff['academic_cg'], KpiController::class, 'reviewAttendance', ['decision' => 'approved'], ['id' => $attendance->id]);
            }
        });

        $nextDay = $day->copy()->addDay();
        match ($index) {
            3 => $this->planCancelledSupport($day),
            7, 15 => $this->at($nextDay->copy()->setTime(9, 0), fn () => $this->closeK24Stage('Hoàn thành chặng; Big Test chặng làm trên giấy tại lớp, điểm đã nhập sổ.')),
            self::K24_DROP[1] => $this->at($nextDay->copy()->setTime(10, 0), fn () => $this->changeStatus(
                $this->staff['academic_cg'], $this->refs['k24_students'][self::K24_DROP[0]], Student::STATUS_DROPPED,
                'Thôi học giữa khóa: gia đình chuyển công tác vào TP.HCM (PH báo qua Zalo).'
            )),
            16 => $this->planK24Extension($nextDay),
            self::K24_SUMMER[1] - 1 => $this->at($nextDay->copy()->setTime(10, 0), fn () => $this->changeStatus(
                $this->staff['academic_cg'], $this->refs['k24_students'][self::K24_SUMMER[0]], 'summer_break',
                'Nghỉ hè: về quê cùng gia đình 2 tuần cuối khóa, giữ chỗ lên lớp Movers.'
            )),
            default => null,
        };
    }

    private function k24Attendance(ClassSession $session, int $index): void
    {
        $class = $this->refs['k24'];
        $students = collect($this->refs['k24_students']);
        $statuses = [];
        $notes = [];
        foreach ($class->rosterStudents() as $student) {
            $j = $students->search(fn (Student $s) => $s->id === $student->id);
            [$status, $note] = match (true) {
                $j === self::K24_SUMMER[0] && $index >= self::K24_SUMMER[1] => ['excused', 'Nghỉ hè (về quê cùng gia đình)'],
                default => self::K24_ATTENDANCE["{$index}:{$j}"] ?? ['present', null],
            };
            $statuses[$student->id] = $status;
            if ($note) {
                $notes[$student->id] = $note;
            }
        }
        $this->asUser(User::find($session->teacher_id), TeacherPortalController::class, 'attendanceStore', [
            'class_session_id' => $session->id, 'status' => $statuses, 'note' => $notes,
        ], ['classId' => $class->id]);
    }

    /** Đóng chặng đang mở của lớp K24 (Học thuật), tự mở chặng kế tiếp; chặng cuối → hoàn thành giáo trình. */
    private function closeK24Stage(string $reason): void
    {
        $assignment = SyllabusAssignment::open()->where('class_id', $this->refs['k24']->id)->first();
        if ($assignment) {
            $this->asUser($this->staff['lead'], SyllabusController::class, 'closeAssignment', ['reason' => $reason, 'open_next' => '1'], ['id' => $assignment->id]);
        }
    }

    /** Buổi vắng → danh sách bổ trợ; Học vụ xếp buổi bổ trợ với trợ giảng, hôm sau PH báo bận → hủy buổi. */
    private function planCancelledSupport(Carbon $day): void
    {
        $this->at($day->copy()->addDays(2)->setTime(9, 30), function () use ($day) {
            $student = $this->refs['k24_students'][1];
            $item = ClassReportStudentSupport::where('class_id', $this->refs['k24']->id)->where('student_id', $student->id)
                ->where('source', SupportListService::SOURCE_ATTENDANCE)->whereDoesntHave('supportSession')->firstOrFail();
            $this->asUser($this->staff['academic_cg'], WorkTaskController::class, 'storeSupportSession', [
                'class_report_student_support_id' => $item->id, 'class_id' => $this->refs['k24']->id, 'student_id' => $student->id,
                'teacher_id' => $this->staff['assistant']->id, 'session_date' => $day->copy()->addDays(4)->toDateString(),
                'start_time' => '15:30', 'end_time' => '16:30', 'room' => 'Phòng bổ trợ', 'reason' => $item->reason,
            ]);
            $this->refs['k24_support'] = SupportSession::where('class_report_student_support_id', $item->id)->firstOrFail();
        });
        // Không có thao tác "Hủy buổi bổ trợ" trên giao diện: đổi trạng thái chính buổi bổ trợ seeder vừa xếp.
        $this->at($day->copy()->addDays(3)->setTime(18, 0), function () {
            $support = $this->refs['k24_support'];
            $support->update(['status' => 'cancelled', 'completion_note' => 'PH báo con bận thi học kỳ ở trường, hủy buổi bổ trợ; GV gửi phiếu ôn tập về nhà.']);
            $support->classSession?->update(['status' => 'cancelled', 'notes' => $support->classSession->notes.' — Đã hủy: PH báo bận.']);
        });
    }

    /**
     * Dạy thay (bảng substitute_sessions — chưa có model / màn hình): GV chính xin nghỉ, Học vụ duyệt và đổi người đứng
     * buổi (người dạy thay điểm danh + nhận xét buổi đó), hoặc từ chối.
     */
    private function planSubstitute(ClassSession $session, bool $approved): void
    {
        $original = $this->staff['teacher_k24'];
        $substitute = $approved ? $this->staff['teacher_cg'] : $this->staff['native'];
        $requested = $session->date->copy()->subDays($approved ? 3 : 2)->setTime(20, 0);
        $reviewed = $requested->copy()->addDay()->setTime(9, 0);

        $this->at($requested, function () use ($session, $original, $substitute, $approved) {
            $this->refs['substitute'][$session->id] = DB::table('substitute_sessions')->insertGetId([
                'class_id' => $session->class_id,
                'original_teacher_id' => $original->id,
                'substitute_teacher_id' => $substitute->id,
                'session_date' => $session->date->toDateString(),
                'scheduled_time' => $session->start_time->format('H:i').'-'.$session->end_time->format('H:i'),
                'reason' => $approved ? 'GV chính đi tập huấn Cambridge cả ngày, nhờ thầy An dạy thay.' : 'GV chính bận việc gia đình, đề xuất GVNN dạy thay buổi này.',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $this->at($reviewed, function () use ($session, $substitute, $approved) {
            DB::table('substitute_sessions')->where('id', $this->refs['substitute'][$session->id])->update([
                'status' => $approved ? 'approved' : 'rejected',
                'reviewer_id' => $this->staff['academic_cg']->id,
                'review_note' => $approved ? 'Đã duyệt, đã báo PH qua nhóm Zalo lớp.' : 'GVNN chưa nắm tiến độ Starters K24; đề nghị GV chính sắp xếp đứng lớp.',
                'updated_at' => now(),
            ]);
            if ($approved) {
                $session->update(['teacher_id' => $substitute->id, 'notes' => 'Dạy thay: '.$substitute->name.' (GV chính đi tập huấn).']);
            }
        });
    }

    /** GV xin giãn tiến độ +2 buổi (chặng 3), Admin duyệt hôm sau → lịch tự nối 2 buổi; lên lịch các buổi thêm + kết thúc khóa. */
    private function planK24Extension(Carbon $day): void
    {
        $this->at($day->copy()->setTime(20, 0), function () {
            $this->asUser($this->staff['teacher_k24'], SyllabusController::class, 'storeAdjustmentRequest', [
                'class_id' => $this->refs['k24']->id,
                'reason' => 'Nhiều HV còn yếu phần Reading & Writing (mock test dưới 60%); cần thêm 2 buổi luyện đề trước Big Test cuối khóa.',
                'extra_sessions' => 2,
            ]);
        });
        $this->at($day->copy()->addDay()->setTime(15, 0), function () {
            $class = $this->refs['k24'];
            $before = $class->sessions()->pluck('id');
            $request = SyllabusAdjustmentRequest::where('class_id', $class->id)->where('status', 'pending')->firstOrFail();
            $this->asUser($this->staff['admin'], SyllabusController::class, 'approveAdjustmentRequest', [], ['id' => $request->id]);
            $all = $class->sessions()->where('type', ClassSession::TYPE_REGULAR)->orderBy('date')->get()->values();
            foreach ($all as $index => $session) {
                if (! $before->contains($session->id)) {
                    $this->planK24Session($session, $index);
                }
            }
            $this->planK24Closing(Carbon::parse($all->last()->date));
        });
    }

    /** Kết thúc khóa: lớp → Đã kết thúc, HV đang học → Hoàn thành, đóng chặng cuối; khảo sát cuối khóa rồi đóng. */
    private function planK24Closing(Carbon $lastDay): void
    {
        $this->at($lastDay->copy()->addDay()->setTime(10, 0), function () {
            $this->updateClass($this->staff['manager_cg'], $this->refs['k24'], [
                'status' => 'completed',
                'ghi_chu' => 'Đã kết thúc khóa: 4/6 HV hoàn thành, 1 HV nghỉ hè (giữ chỗ lên Movers), 1 HV thôi học giữa khóa.',
            ]);
            foreach ($this->refs['k24_students'] as $student) {
                if ($student->fresh()->status === 'studying') {
                    $this->asUser($this->staff['academic_cg'], StudentProfileController::class, 'updateStudentStatus', ['status' => 'completed'], ['id' => $student->id]);
                }
            }
            $this->closeK24Stage('Kết thúc khóa K24; Big Test cuối khóa đã chấm và gửi PH.');
        });

        $title = '# Khảo sát cuối khóa Starters K24 (Cầu Giấy)';
        $this->at($lastDay->copy()->addDay()->setTime(14, 0), fn () => $this->asUser($this->staff['academic_cg'], SurveyController::class, 'store', [
            'title' => $title,
            'description' => 'Phụ huynh đánh giá giáo viên, lịch học, cơ sở vật chất sau khóa Starters K24 (phiếu giấy, Học vụ nhập lại).',
            'deadline' => $lastDay->copy()->addDays(8)->toDateString(),
        ]));
        foreach (self::K24_SURVEY as $i => [$rating, $feedback]) {
            $this->at($lastDay->copy()->addDays(2 + $i % 4)->setTime(17, 10 + $i), fn () => $this->asUser($this->staff['academic_cg'], StudentPortalController::class, 'submitSurvey', [
                'student_id' => $this->refs['k24_students'][$i]->id, 'survey_title' => $title, 'feedback' => $feedback, 'rating' => $rating,
            ]));
        }
        $this->at($lastDay->copy()->addDays(9)->setTime(9, 0), function () use ($title) {
            $survey = Survey::where('title', $title)->firstOrFail();
            $this->asUser($this->staff['academic_cg'], SurveyController::class, 'update', [
                'title' => $survey->title, 'description' => $survey->description, 'deadline' => $survey->deadline->toDateString(), 'is_active' => '0',
            ], ['survey' => $survey]);
        });
    }

    // ── Lớp đã hủy K29 (Ba Đình) ────────────────────────────────────────────

    private function planCancelledClass(): void
    {
        $bd = $this->branches['BD'];
        $actor = $this->staff['academic_bd'];
        $this->at($this->daysAgo(42, '10:00'), function () use ($bd) {
            $this->asUser($this->staff['manager_bd'], ClassManagementController::class, 'store', [
                'ten_lop' => '# Movers FAM 2 · K29 (BD)',
                'ma_lop' => self::CANCELLED_CLASS,
                'chi_nhanh' => $bd->id,
                'chuong_trinh' => '# Movers (FAM 2)',
                'cap_do' => 'MOVERS (FAM 2)',
                'si_so_toi_da' => 10,
                'min_students' => 6,
                'giao_vien_chinh' => $this->staff['teacher_bd']->id,
                'hoc_phi' => 9500000,
                'ghi_chu' => 'Mở thêm ca sáng Chủ nhật cho HV Movers, chờ đủ 6 HV để xếp lịch.',
            ]);
            $this->refs['bd29'] = ClassModel::where('code', self::CANCELLED_CLASS)->firstOrFail();
        });
        foreach (self::BD_STUDENTS as $i => $spec) {
            $this->at($this->daysAgo(40 - 7 * $i, '15:00'), function () use ($i, $spec, $bd, $actor) {
                $student = $this->createStudent($spec, $bd, 2900 + $i, 'Movers (A1)');
                $this->refs['bd29_students'][$i] = $student;
                $enrollment = $this->enroll($actor, $student, $this->refs['bd29']);
                if ($i === 0) {
                    $this->handoff($actor, $enrollment);
                }
            });
        }
        $this->at($this->daysAgo(18, '09:00'), fn () => $this->updateClass($this->staff['manager_bd'], $this->refs['bd29'], [
            'status' => 'cancelled',
            'ghi_chu' => 'Hủy lớp: sau 3 tuần tuyển sinh chỉ có 2/6 HV, không đủ ngưỡng khai giảng. Học vụ liên hệ PH chuyển lớp K27.',
        ]));
        // Chuyển lớp = xếp vào lớp mới khi đang có lớp chính (lượt cũ ghi ngày rời lớp), rồi bàn giao ở lớp mới.
        $this->at($this->daysAgo(17, '10:00'), function () use ($actor) {
            $this->refs['bd29_transfer'] = $this->enroll($actor, $this->refs['bd29_students'][0], $this->classes['DEMO-BD-FAM2']);
        });
        $this->at($this->daysAgo(16, '16:00'), fn () => $this->handoff($actor, $this->refs['bd29_transfer']));
        $this->at($this->daysAgo(17, '11:00'), fn () => $this->changeStatus($actor, $this->refs['bd29_students'][1], Student::STATUS_DROPPED,
            'Thôi học: lớp K29 bị hủy, PH không sắp xếp được lịch tối T3/T5 của lớp K27.'));
    }

    // ── Lớp chờ xếp lịch K29 (Đống Đa) ──────────────────────────────────────

    private function planPendingClass(): void
    {
        $dd = $this->branches['DD'];
        $actor = $this->staff['academic_dd'];
        $this->at($this->daysAgo(12, '10:00'), function () use ($dd, $actor) {
            $this->asUser($actor, ClassManagementController::class, 'store', [
                'ten_lop' => '# Pre Starters FAM 0 · K29 (ĐĐ)',
                'ma_lop' => self::PENDING_CLASS,
                'chi_nhanh' => $dd->id,
                'chuong_trinh' => '# Pre Starters (FAM 0)',
                'cap_do' => 'PRE STARTERS (FAM 0)',
                'si_so_toi_da' => 10,
                'min_students' => 6,
                'giao_vien_chinh' => $this->staff['teacher_dd']->id,
                'hoc_phi' => 8000000,
                'ghi_chu' => 'Lớp mới tại Đống Đa, dự kiến tối T3/T5; chờ chốt phòng và ca học.',
            ]);
            $this->refs['dd29'] = ClassModel::where('code', self::PENDING_CLASS)->firstOrFail();
        });
        foreach (self::DD_STUDENTS as $i => $spec) {
            $this->at($this->daysAgo(11 - 3 * $i, '14:00'), function () use ($i, $spec, $dd, $actor) {
                $student = $this->createStudent($spec, $dd, 2950 + $i, 'Pre Starters');
                $this->refs['dd29_enrollments'][$i] = $this->enroll($actor, $student, $this->refs['dd29']);
            });
            if ($i < 2) {
                $this->at($this->daysAgo(9 - 3 * $i, '10:30'), fn () => $this->handoff($actor, $this->refs['dd29_enrollments'][$i]));
            }
        }
    }

    /** Đề nghị dạy thay đang chờ duyệt cho buổi sắp tới của lớp DEMO-CG-FAM1 (chỉ thêm dòng, không đổi buổi học). */
    private function planPendingSubstitute(): void
    {
        $class = $this->classes['DEMO-CG-FAM1'];
        $session = $class->sessions()->where('type', ClassSession::TYPE_REGULAR)->where('status', 'scheduled')
            ->whereDate('date', '>=', $this->realNow->copy()->addDays(2)->toDateString())->orderBy('date')->first();
        if (! $session) {
            return;
        }
        $this->at($this->realNow->copy()->subHours(14), fn () => DB::table('substitute_sessions')->insert([
            'class_id' => $class->id,
            'original_teacher_id' => $session->teacher_id ?? $class->teacher_id,
            'substitute_teacher_id' => $this->staff['teacher_k24']->id,
            'session_date' => $session->date->toDateString(),
            'scheduled_time' => $session->start_time?->format('H:i').'-'.$session->end_time?->format('H:i'),
            'reason' => 'GV chính có lịch khám sức khỏe định kỳ, nhờ thầy Quốc Anh dạy thay.',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    // ── Giáo trình: tài liệu, đề xuất sửa, giãn tiến độ ─────────────────────

    private function planDocuments(): void
    {
        // [giáo trình, chặng (vị trí), tiêu đề, loại file, cho GV, cho trợ giảng, cho tải, ngày trước, người xem => ngày trước].
        $documents = [
            ['DEMO-SYL-STARTERS', 1, 'Student Book Starters — Unit 1–2', 'pdf', true, true, true, 34, ['teacher_cg' => 33, 'teacher_k24' => 30, 'assistant' => 29]],
            ['DEMO-SYL-STARTERS', 1, 'Slide bài giảng Chặng 1 (chỉ xem trực tuyến)', 'pdf', true, false, false, 33, ['teacher_cg' => 31, 'teacher_bd' => 20]],
            ['DEMO-SYL-STARTERS', 2, 'Flashcard chủ đề Animals', 'png', true, true, true, 26, ['assistant' => 25]],
            ['DEMO-SYL-STARTERS', 1, 'Đáp án Big Test chặng 1 (nội bộ Học thuật)', 'pdf', false, false, false, 25, []],
            ['DEMO-SYL-MOVERS', 1, 'Movers — Audio script Unit Daily routines', 'pdf', true, false, true, 12, ['teacher_bd' => 10]],
            ['DEMO-SYL-MOVERS', 2, 'Movers — Bộ đề mock Listening (bản nháp)', 'pdf', true, true, true, 2, []],
        ];
        foreach ($documents as $i => [$code, $position, $title, $type, $teachers, $assistants, $download, $ago, $viewers]) {
            $this->at($this->daysAgo($ago, '10:'.(10 + $i)), function () use ($code, $position, $title, $type, $teachers, $assistants, $download) {
                $curriculum = SyllabusCurriculum::where('code', $code)->firstOrFail();
                $this->asUser($this->staff['lead'], SyllabusController::class, 'storeDocument', [
                    'curriculum_id' => $curriculum->id,
                    'stage_id' => $curriculum->stages()->where('position', $position)->value('id'),
                    'title' => '# '.$title,
                    'file' => $this->demoFile($title, $type),
                    'visible_to_teachers' => $teachers ? '1' : '0',
                    'visible_to_assistants' => $assistants ? '1' : '0',
                    'downloadable' => $download ? '1' : '0',
                ]);
            });
            foreach ($viewers as $who => $viewedAgo) {
                $this->at($this->daysAgo($viewedAgo, '21:'.(10 + $i)), function () use ($title, $who) {
                    $doc = SyllabusDocument::where('title', '# '.$title)->firstOrFail();
                    $this->asUser($this->staff[$who], SyllabusController::class, 'markDocumentViewed', [], ['id' => $doc->id]);
                });
            }
        }
    }

    private function planProposals(): void
    {
        // [GV, giáo trình, tên buổi (null = chung), loại, nội dung cũ, đề xuất, lý do, file?, ngày trước, kết quả (null = chờ), ghi chú duyệt].
        $proposals = [
            ['teacher_k24', 'DEMO-SYL-STARTERS', 'Can / can\'t', 'Bổ sung hoạt động / trò chơi tương tác', 'Bài tập điền can/can\'t trên giấy (10 câu).',
                'Thêm trò chơi "Simon says — can you jump?" 10 phút trước bài tập viết.', 'HV 6–7 tuổi mất tập trung khi làm bài viết dài.', false, 20, 'approved', 'Đồng ý, cập nhật vào giáo án Unit 3 từ khóa K28.'],
            ['teacher_bd', 'DEMO-SYL-MOVERS', 'Quá khứ đơn (bất quy tắc)', 'Thay đổi độ dài / thời gian bài tập', 'Học 20 động từ bất quy tắc trong 1 buổi.',
                'Tách thành 2 buổi, mỗi buổi 10 động từ.', 'HV nhớ không kịp, mini test Unit 3 điểm thấp.', false, 12, 'rejected', 'Không tách buổi được vì ảnh hưởng tiến độ Big Test; GV dùng thêm flashcard và bài tập về nhà.'],
            ['teacher_cg', 'DEMO-SYL-STARTERS', 'Màu sắc & số 1–10', 'Cập nhật file audio / video bị lỗi', 'Audio track 07 bị rè từ giây 40.',
                'Thay bằng bản ghi âm mới (đính kèm).', 'HV không nghe rõ phần đếm số.', true, 2, null, null],
            ['native', 'DEMO-SYL-STARTERS', null, 'Khác', null,
                'Bổ sung phần phát âm âm cuối /s/ /z/ vào mỗi Unit (5 phút đầu giờ).', 'HV Việt hay bỏ âm cuối, nên luyện đều từ Starters.', false, 0.25, null, null],
        ];
        foreach ($proposals as $i => [$who, $code, $lessonTitle, $type, $old, $new, $reason, $withFile, $ago, $decision, $note]) {
            $sentAt = $ago < 1 ? $this->realNow->copy()->subHours(6) : $this->daysAgo($ago, '21:30');
            $this->at($sentAt, function () use ($i, $who, $code, $lessonTitle, $type, $old, $new, $reason, $withFile) {
                $curriculum = SyllabusCurriculum::where('code', $code)->firstOrFail();
                $this->asUser($this->staff[$who], SyllabusController::class, 'storeProposal', array_filter([
                    'curriculum_id' => $curriculum->id,
                    'lesson_id' => $lessonTitle ? SyllabusLesson::where('curriculum_id', $curriculum->id)->where('title', $lessonTitle)->value('id') : null,
                    'proposal_type' => $type,
                    'old_content' => $old,
                    'new_content' => $new,
                    'reason' => $reason,
                    'attachment' => $withFile ? $this->demoFile('Ban ghi am thay the track 07', 'pdf') : null,
                ]));
                $this->refs['proposals'][$i] = SyllabusChangeProposal::where('user_id', $this->staff[$who]->id)->latest('id')->firstOrFail();
            });
            if ($decision) {
                $this->at($sentAt->copy()->addDays(2)->setTime(10, 0), fn () => $this->asUser(
                    $this->staff['admin'], SyllabusController::class, $decision === 'approved' ? 'approveProposal' : 'rejectProposal',
                    ['review_note' => $note], ['id' => $this->refs['proposals'][$i]->id]
                ));
            }
        }
    }

    /** Xin giãn tiến độ ở lớp đang học: bị từ chối, chờ duyệt quá hạn SLA (đã báo Admin), chờ duyệt trong hạn. */
    private function planAdjustments(): void
    {
        $requests = [
            ['teacher_bd', 'DEMO-BD-FAM1', 1, 'Nhiều HV nghỉ ốm tuần trước, xin thêm 1 buổi ôn Unit 2.', $this->daysAgo(9, '20:00'), 'Lớp còn 2 buổi đệm trước Big Test chặng 2; GV dùng buổi bổ trợ cho HV vắng thay vì giãn cả lớp.'],
            ['teacher_cg', 'DEMO-CG-FAM1', 2, 'Lớp mất 1 buổi do nghỉ đột xuất của cơ sở, xin thêm 2 buổi để kịp nội dung Chặng 2.', $this->daysAgo(5, '21:00'), null],
            ['teacher_bd', 'DEMO-BD-FAM0', 1, 'HV mới vào giữa chặng chưa theo kịp, xin 1 buổi ôn trước mini test.', $this->realNow->copy()->subHours(5), null],
        ];
        foreach ($requests as $i => [$who, $code, $extra, $reason, $sentAt, $rejection]) {
            $this->at($sentAt, function () use ($i, $who, $code, $extra, $reason) {
                $this->asUser($this->staff[$who], SyllabusController::class, 'storeAdjustmentRequest', [
                    'class_id' => $this->classes[$code]->id, 'reason' => $reason, 'extra_sessions' => $extra,
                ]);
                $this->refs['adjustments'][$i] = SyllabusAdjustmentRequest::where('user_id', $this->staff[$who]->id)->latest('id')->firstOrFail();
            });
            if ($rejection) {
                $this->at($sentAt->copy()->addDay()->setTime(11, 0), fn () => $this->asUser($this->staff['admin'], SyllabusController::class,
                    'rejectAdjustmentRequest', ['rejection_reason' => $rejection], ['id' => $this->refs['adjustments'][$i]->id]));
            }
        }
        // Lệnh hằng giờ syllabus:notify-adjustment-sla: yêu cầu chờ quá 3 ngày → báo Admin 1 lần (sla_notified_at).
        $this->at($this->daysAgo(1, '08:20'), fn () => app(AdjustmentSlaService::class)->notifyBreaches());
    }

    // ── Khảo sát ─────────────────────────────────────────────────────────────

    private function planSurveys(): void
    {
        $month = $this->realNow->format('m/Y');
        $open = "# Khảo sát chất lượng giảng dạy tháng {$month}";
        $this->at($this->daysAgo(6, '09:00'), fn () => $this->asUser($this->staff['academic_cg'], SurveyController::class, 'store', [
            'title' => $open,
            'description' => 'Phụ huynh / học viên chấm 1–5 sao về giáo viên, lịch học và chăm sóc của Học vụ trong tháng.',
            'deadline' => $this->realNow->copy()->addDays(7)->toDateString(),
        ]));
        // [người gửi (null = Học vụ nhập phiếu giấy), mã HV, sao, ý kiến, ngày trước].
        $responses = [
            ['student1', 'HV-DEMO-CG-01', 5, 'Cô giáo dạy dễ hiểu, con rất thích các trò chơi trên lớp.', 5],
            ['student2', 'HV-DEMO-CG-08', 4, 'Lịch học ổn, mong có thêm bài tập online.', 4],
            ['student3', 'HV-DEMO-BD-01', 1, 'GV giao quá nhiều bài tập về nhà, PH đi làm về muộn không kèm con được; Học vụ chưa phản hồi tin nhắn.', 3],
            [null, 'HV-DEMO-CG-03', 2, 'Phòng học điều hòa yếu, buổi chiều rất nóng. Con hay bị đổi chỗ ngồi.', 2],
            [null, 'HV-DEMO-CG-04', 5, 'Cảm ơn trung tâm, con tiến bộ nhiều.', 1],
        ];
        foreach ($responses as [$who, $code, $rating, $feedback, $ago]) {
            $this->at($this->daysAgo($ago, '20:15'), function () use ($who, $code, $rating, $feedback, $open) {
                $student = Student::where('code', $code)->first();
                if ($student) {
                    $this->asUser($who ? $this->staff[$who] : $this->staff['academic_cg'], StudentPortalController::class, 'submitSurvey', [
                        'student_id' => $student->id, 'survey_title' => $open, 'feedback' => $feedback, 'rating' => $rating,
                    ]);
                }
            });
        }
        // Đợt hết hạn hôm nay, chưa có phản hồi (nhãn "Hôm nay" trên Cổng PH/HS).
        $this->at($this->daysAgo(2, '16:00'), fn () => $this->asUser($this->staff['academic_bd'], SurveyController::class, 'store', [
            'title' => '# Khảo sát nhanh: lịch học bù dịp nghỉ lễ',
            'description' => 'PH chọn khung giờ học bù phù hợp cho các buổi trùng ngày nghỉ.',
            'deadline' => $this->realNow->toDateString(),
        ]));
    }

    // ── Dự án học thuật ─────────────────────────────────────────────────────

    /**
     * Dự án mới: [khóa, tên, loại, mô tả, thành viên, bắt đầu (tuần trước), deadline (tuần, âm = đã qua), chốt?, kết thúc (action, tuần trước),
     * mốc: [tên, người nhận, hạn (tuần lệch R), khối lượng, đơn vị, số ngày trễ khi xong (null = chưa xong), khối lượng dở dang]].
     */
    private function projectSpecs(): array
    {
        return [
            'workbook' => ['# Workbook Pre Starters (FAM 0) — bản 2', 'book', 'Viết lại Workbook FAM 0 theo phản hồi GV khóa K23–K24: thêm trang tô màu, bớt bài viết.',
                ['teacher_k24', 'teacher_cg'], 15, -4, true, ['complete', 4], [
                    ['Workbook Unit 1–4', 'teacher_k24', -11, 4, 'unit', 0, null],
                    ['Workbook Unit 5–8', 'teacher_cg', -8, 4, 'unit', 2, null],
                    ['Audio + đáp án Workbook', 'lead', -6, 8, 'bài', 0, null],
                    ['Duyệt in & bàn giao kho', 'lead', -5, 1, 'lần', 0, null],
                ]],
            'movers' => ['# Bộ đề luyện thi Movers 2027', 'book', 'Soạn 5 đề Movers mô phỏng format Cambridge 2027 cho lớp FAM 2.',
                ['teacher_bd', 'native'], 9, 3, true, ['pause', 2], [
                    ['Đề Listening 1–5', 'native', -6, 5, 'đề', 1, null],
                    ['Đề Reading & Writing 1–5', 'teacher_bd', -3, 5, 'đề', null, 3],
                    ['Speaking cards (40 thẻ)', 'native', 3, 40, 'thẻ', null, null],
                ]],
            'speaking' => ['# Chương trình Speaking Club Teens', 'curriculum', 'Khung chương trình Speaking Club cho HV 12–15 tuổi tại Cầu Giấy.',
                ['native'], 6, 6, false, ['cancel', 4], [
                    ['Khung 12 chủ đề + rubric', 'native', 1, 12, 'chủ đề', null, null],
                ]],
        ];
    }

    private function planProjects(): void
    {
        foreach ($this->projectSpecs() as $key => [$name, $type, $description, $members, $startWeeks, $deadlineWeeks, $lock, [$action, $endWeeks], $milestones]) {
            $start = $this->realNow->copy()->startOfDay()->subWeeks($startWeeks);
            $this->at($start->copy()->setTime(9, 0), function () use ($key, $name, $type, $description, $members, $start, $deadlineWeeks, $milestones) {
                $lead = $this->staff['lead'];
                $this->asUser($lead, AcademicProjectController::class, 'store', [
                    'name' => $name, 'type' => $type, 'description' => $description, 'owner_id' => $lead->id,
                    'member_ids' => collect($members)->map(fn (string $m) => $this->staff[$m]->id)->all(),
                    'start_date' => $start->toDateString(),
                    'deadline' => $this->realNow->copy()->addWeeks($deadlineWeeks)->toDateString(),
                    'kickoff_notes' => 'Họp thống nhất phạm vi, chia mốc và người phụ trách từng phần.',
                ]);
                $project = AcademicProject::where('name', $name)->firstOrFail();
                $this->refs['projects'][$key] = $project;
                foreach ($milestones as [$title, $assignee, $dueWeeks, $quantity, $unit]) {
                    $due = $this->realNow->copy()->startOfDay()->addWeeks($dueWeeks);
                    $this->asUser($lead, AcademicProjectController::class, 'storeMilestone', [
                        'title' => $title, 'assignee_id' => $this->staff[$assignee]->id,
                        'start_date' => $start->toDateString(), 'due_date' => $due->toDateString(),
                        'target_quantity' => $quantity, 'unit' => $unit,
                    ], ['id' => $project->id]);
                }
            });
            if ($lock) {
                $this->at($start->copy()->addDay()->setTime(17, 0), fn () => $this->asUser($this->staff['lead'], AcademicProjectController::class, 'lock', [], ['id' => $this->refs['projects'][$key]->id]));
            }
            foreach ($milestones as $m => [$title, $assignee, $dueWeeks, $quantity, $unit, $lateDays, $partial]) {
                $due = $this->realNow->copy()->startOfDay()->addWeeks($dueWeeks);
                if ($partial !== null) {
                    $this->at($due->copy()->subDays(6)->setTime(18, 0), fn () => $this->projectUpdate($key, $assignee, $title, [
                        'quantity_done' => $partial,
                        'content' => "Xong {$partial}/{$quantity} {$unit}, đang soạn tiếp.",
                        'difficulties' => 'Cambridge chưa công bố mẫu đề 2027 phần Writing; chờ NXB cập nhật format trước khi làm tiếp.',
                    ]));
                }
                if ($lateDays !== null) {
                    $this->at($due->copy()->addDays($lateDays)->setTime(16, 30), fn () => $this->projectUpdate($key, $assignee, $title, [
                        'quantity_done' => $quantity,
                        'content' => $lateDays > 0 ? "Hoàn thành {$title} (trễ {$lateDays} ngày do chờ minh họa)." : "Hoàn thành {$title}, đã đưa lên thư mục dự án.",
                        'links_text' => 'https://drive.google.com/demo/'.Str::slug($title),
                        'marks_complete' => '1',
                    ]));
                }
            }
            if ($key === 'speaking') {
                $this->at($this->daysAgo($endWeeks * 7 + 1, '17:00'), fn () => $this->projectUpdate($key, 'lead', null, [
                    'content' => 'Tạm không triển khai: chưa tuyển đủ GVNN cho khung giờ tối, Ban Giám đốc quyết định hủy dự án.',
                ]));
            }
            $this->at($this->daysAgo($endWeeks * 7, '10:00'), fn () => $this->asUser($this->staff['lead'], AcademicProjectController::class,
                'changeStatus', ['action' => $action], ['id' => $this->refs['projects'][$key]->id]));
        }
        // Học thuật phản hồi khó khăn của dự án đề Movers trước khi tạm dừng.
        $this->at($this->daysAgo(15, '09:30'), function () {
            $update = AcademicProjectUpdate::where('academic_project_id', $this->refs['projects']['movers']->id)->whereNotNull('difficulties')->latest('id')->first();
            if ($update) {
                $this->asUser($this->staff['lead'], AcademicProjectController::class, 'respond', [
                    'response' => 'Đã liên hệ NXB, dự kiến có format mới trong tháng sau; tạm dừng dự án tới khi có mẫu đề.',
                ], ['updateId' => $update->id]);
            }
        });
    }

    private function projectUpdate(string $key, string $who, ?string $milestoneTitle, array $input): void
    {
        $project = $this->refs['projects'][$key];
        $milestoneId = $milestoneTitle ? $project->milestones()->where('title', $milestoneTitle)->value('id') : null;
        $this->asUser($this->staff[$who], AcademicProjectController::class, 'storeUpdate', array_filter(['milestone_id' => $milestoneId] + $input), ['id' => $project->id]);
    }

    /**
     * 2 dự án của DemoAcademicKpiSeeder (nếu có): thêm thành viên + cập nhật tiến độ không ghi khối lượng (không đổi mốc / KPI),
     * 1 khó khăn đã được phản hồi, 1 cập nhật chưa phản hồi.
     */
    private function planExistingProjects(): void
    {
        $specs = [
            '# Giáo trình Starters K28 (Book 1–2)' => [['native', 'teacher_k24'], [
                ['teacher_k24', null, 'Đã góp ý bản thảo Unit 4–6: chỉnh lại thứ tự hoạt động warm-up cho phù hợp lớp 6 tuổi.',
                    'Thiếu tranh minh họa Unit 5, họa sĩ chưa gửi.', 4, 'Đã nhắc họa sĩ, hạn gửi tranh thứ 6 tuần này.'],
                ['native', null, 'Thu âm xong audio Unit 1–3 bản chỉnh (giọng Anh–Anh).', null, 1, null],
            ]],
            '# Chương trình IELTS Foundation 2027' => [['teacher_cg'], [
                ['teacher_cg', null, 'Rà soát rubric Speaking Foundation, đề xuất thêm tiêu chí phát âm âm cuối.', null, 2, null],
            ]],
        ];
        foreach ($specs as $name => [$members, $updates]) {
            $project = AcademicProject::where('name', $name)->first();
            if (! $project) {
                continue;
            }
            $this->at($this->daysAgo(6, '08:30'), fn () => $project->members()->syncWithoutDetaching(collect($members)->map(fn (string $m) => $this->staff[$m]->id)->all()));
            foreach ($updates as [$who, $milestone, $content, $difficulties, $ago, $response]) {
                $this->at($this->daysAgo($ago, '18:00'), function () use ($project, $who, $content, $difficulties) {
                    $this->asUser($this->staff[$who], AcademicProjectController::class, 'storeUpdate', array_filter([
                        'content' => $content, 'difficulties' => $difficulties,
                    ]), ['id' => $project->id]);
                });
                if ($response) {
                    $this->at($this->daysAgo($ago - 1, '09:00'), function () use ($project, $who, $response) {
                        $update = AcademicProjectUpdate::where('academic_project_id', $project->id)->where('user_id', $this->staff[$who]->id)->latest('id')->firstOrFail();
                        $this->asUser($this->staff['lead'], AcademicProjectController::class, 'respond', ['response' => $response], ['updateId' => $update->id]);
                    });
                }
            }
        }
    }

    // ── Tiện ích ─────────────────────────────────────────────────────────────

    /** File mẫu nhỏ (PDF 1 trang có tiêu đề, hoặc ảnh PNG) để upload qua form thật. */
    private function demoFile(string $title, string $type): UploadedFile
    {
        $name = Str::slug($title);
        $path = tempnam(sys_get_temp_dir(), 'syl');
        if ($type === 'png') {
            file_put_contents($path, $this->demoPng($title));

            return new UploadedFile($path, "{$name}.png", 'image/png', null, true);
        }
        file_put_contents($path, $this->demoPdf($title));

        return new UploadedFile($path, "{$name}.pdf", 'application/pdf', null, true);
    }

    private function demoPdf(string $title): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], Str::ascii($title));
        $stream = "BT /F1 20 Tf 60 760 Td ({$text}) Tj ET\nBT /F1 11 Tf 60 730 Td (M English - tai lieu demo) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function demoPng(string $title): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        }
        $image = imagecreatetruecolor(480, 320);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 243, 214));
        imagefilledrectangle($image, 20, 20, 460, 300, imagecolorallocate($image, 255, 255, 255));
        imagestring($image, 5, 40, 140, Str::ascii($title), imagecolorallocate($image, 30, 64, 120));
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function printSummary(): void
    {
        $k24 = ClassModel::where('code', self::MARKER)->first();
        $this->command?->info('DemoCoverageTrainingSeeder: lớp K24 '.($k24?->sessions()->count() ?? 0).' buổi / '
            .StudentAttendance::where('class_id', $k24?->id)->count().' lượt điểm danh; '
            .Student::where('code', 'like', 'HV-DEMO-K2%')->count().' HV mới; '
            .DB::table('substitute_sessions')->count().' dạy thay; '
            .SyllabusDocument::count().' tài liệu; '
            .SyllabusChangeProposal::count().' đề xuất; '
            .SyllabusAdjustmentRequest::count().' giãn tiến độ; '
            .Survey::count().' khảo sát; '
            .AcademicProject::count().' dự án học thuật.');
    }
}
