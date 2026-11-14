<?php

namespace Database\Seeders;

use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\CrmCustomer;
use App\Models\CrmTrialBooking;
use App\Models\PracticeExam;
use App\Models\QuestionBankQuestion;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusDocument;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Services\SessionScheduleService;
use App\Services\SyllabusProgressionService;
use App\Support\Practice\SchoolGrade;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Dữ liệu test cho các ca BA đang bị chặn (TK-2026-0114). CHỈ staging/local/testing, idempotent (khóa cố định TS-* / @test.menglish).
 * Dựng trên dữ liệu DemoPhase1 (lớp DEMO-CG-FAM1 = "Starters FAM 1 · K26 (CG)") — thiếu thì dừng và báo.
 * Không đụng tài khoản golden (ft-a/pt-a/ht-a.gd) hay kỳ lương.
 */
class StagingTestDataSeeder extends Seeder
{
    public const ALLOWED_ENVIRONMENTS = ['staging', 'local', 'testing'];

    /** Lớp demo có sẵn dùng làm nền (học thử, học viên). */
    private const BASE_CLASS = 'DEMO-CG-FAM1';

    private string $password = 'Test@2026';

    private Carbon $birthday;

    public function configure(?string $password, ?string $birthday): static
    {
        if ($password !== null && $password !== '') {
            $this->password = $password;
        }
        $this->birthday = $birthday ? Carbon::parse($birthday) : today();

        return $this;
    }

    public function run(): void
    {
        if (! in_array(app()->environment(), self::ALLOWED_ENVIRONMENTS, true)) {
            throw new RuntimeException('Seeder dữ liệu test chỉ chạy trên staging/local/testing, không chạy ở '.app()->environment().'.');
        }
        $this->birthday ??= today();

        $base = ClassModel::where('code', self::BASE_CLASS)->first();
        if (! $base) {
            throw new RuntimeException('Chưa có lớp '.self::BASE_CLASS.' — chạy DemoPhase1Seeder trước.');
        }
        $sales = User::role(Roles::SALES_CONSULTANT)->where('branch_id', $base->branch_id)->orderBy('id')->first()
            ?? User::role(Roles::SALES_CONSULTANT)->orderBy('id')->first();
        if (! $sales) {
            throw new RuntimeException('Chưa có tài khoản Tư vấn tuyển sinh để đặt lịch học thử.');
        }

        DB::transaction(function () use ($base, $sales) {
            $curriculum = $this->curriculumFor($base);
            $lead = $this->staff('hocthuat-gv.test@test.menglish', 'TS-GV-HT', 'HT-GV (test)', Roles::ACADEMIC_LEAD, $base, ['academic_teaching' => true]);
            $gvnn = $this->staff('nn-x.gd@test.menglish', 'TS-GV-NN', 'GD NN-X (test)', Roles::FOREIGN_TEACHER, $base);
            $this->seedAudio($curriculum, $lead);

            $active = $this->seedClass('TS-CG-ACTIVE', '# Test GVNN + Học thuật (CG)', $base, 'active', today()->subWeeks(3), $lead, $gvnn);
            $upcoming = $this->seedClass('TS-CG-UPCOMING', '# Test Chờ khai giảng (CG)', $base, 'upcoming', today()->addDays(10), $lead, null);
            $this->openStage($upcoming, $curriculum, $lead);
            $this->seedGvnnTimesheets($active, $gvnn);

            // 1 + 6: dưới 10 tuổi, đăng nhập dùng được ngay, có đề luyện thi (khối 6 qua grade_adjust vì kho đề chỉ có khối 6–9).
            $young = $this->student('TS-HS-01', 'hs-tk-02.test@test.menglish', 'HS Dưới 10 (test)', now()->subYears(8)->toDateString(), $base, $base, 'studying', true);
            $young->update(['grade_adjust' => SchoolGrade::adjustFor($young, 6)]);
            $this->seedPracticeExam();

            // 2: sinh nhật đúng ngày chạy (hoặc --birthday=).
            $this->student('TS-HS-02', 'hs-tb-04.test@test.menglish', 'HS Sinh nhật (test)', $this->birthday->copy()->setYear(now()->year - 9)->toDateString(), $base, $base, 'studying', true);

            // 5: Chờ khai giảng trong lớp đã mở chặng.
            $this->student('TS-HS-03', 'hs-bn-03.test@test.menglish', 'HS Chờ khai giảng (test)', now()->subYears(9)->toDateString(), $base, $upcoming, 'waiting_start', true);

            // 9: học phí sắp đến hạn + học viên nghỉ hè còn buổi dư.
            $due = $this->student('TS-HS-04', null, 'HS Sắp đến hạn (test)', now()->subYears(9)->toDateString(), $base, $base, 'studying', false);
            $this->tuition($due, $base, 12, 0, today()->addDays(7)->toDateString());
            $summer = $this->student('TS-HS-05', null, 'HS Nghỉ hè còn buổi (test)', now()->subYears(9)->toDateString(), $base, $base, 'summer_break', false);
            $this->tuition($summer, $base, 36, 9000000, today()->addMonths(2)->toDateString());

            // 4: khách học thử do CM đặt vào buổi tới của lớp Starters FAM 1 CG.
            $this->trialGuest($base, $sales);
        });

        $this->command?->info('Đã tạo/cập nhật dữ liệu test (mật khẩu: '.$this->password.'). Xem docs/staging-test-data.md.');
    }

    private function curriculumFor(ClassModel $base): SyllabusCurriculum
    {
        $level = $base->course?->level ?? CourseLevel::where('code', 'DEMO-STARTERS')->first();
        $curriculum = $level?->syllabus_curriculum_id ? SyllabusCurriculum::find($level->syllabus_curriculum_id) : null;
        if (! $curriculum) {
            $curriculum = SyllabusCurriculum::firstOrCreate(['code' => 'TS-SYL-STARTERS'], ['title' => '# Giáo trình test Starters', 'stage_name' => 'Chặng 1']);
            $level?->update(['syllabus_curriculum_id' => $curriculum->id]);
        }

        return $curriculum;
    }

    private function staff(string $email, string $code, string $name, string $role, ClassModel $base, array $extra = []): User
    {
        $user = User::withTrashed()->where('email', $email)->first() ?? new User;
        if ($user->trashed()) {
            $user->restore();
        }
        $user->fill($extra + [
            'employee_code' => $code, 'name' => $name, 'email' => $email, 'phone' => '0391'.substr(sprintf('%06d', crc32($email) % 1000000), 0, 6),
            'branch_id' => $base->branch_id, 'password' => $this->password, 'is_active' => true, 'must_change_password' => false, 'email_verified_at' => now(),
        ])->save();
        $user->syncRoles([$role]);

        return $user;
    }

    private function seedClass(string $code, string $name, ClassModel $base, string $status, Carbon $from, User $teacher, ?User $foreign): ClassModel
    {
        $class = ClassModel::firstOrCreate(['code' => $code], [
            'name' => $name, 'course_id' => $base->course_id, 'program' => $base->program, 'level' => $base->level, 'branch_id' => $base->branch_id,
            'teacher_id' => $teacher->id, 'foreign_teacher_id' => $foreign?->id, 'room' => $base->room, 'room_id' => $base->room_id,
            'max_capacity' => 12, 'min_students' => 6, 'tuition_fee' => $base->tuition_fee, 'status' => $status,
            'start_date' => $from->toDateString(), 'end_date' => $from->copy()->addWeeks(8)->toDateString(),
            'schedule_text' => 'Thứ 3 18:00-19:30; Thứ 5 18:00-19:30', 'notes' => 'Lớp dữ liệu test TK-2026-0114.',
        ]);
        if (! $class->sessions()->exists()) {
            $slots = [
                ['day' => 'Thứ 3', 'start' => '18:00', 'end' => '19:30', 'shift' => 'Ca tối'],
                ['day' => 'Thứ 5', 'start' => '18:00', 'end' => '19:30', 'shift' => 'Ca tối'],
            ];
            foreach (app(SessionScheduleService::class)->generate($slots, $class->start_date, $class->end_date, $class->branch_id) as $s) {
                ClassSession::create([
                    'class_id' => $class->id, 'branch_id' => $class->branch_id, 'date' => $s['date'], 'shift_name' => $s['shift'],
                    'start_time' => $s['start'], 'end_time' => $s['end'], 'room' => $class->room, 'teacher_id' => $class->teacher_id,
                    'foreign_teacher_id' => $class->foreign_teacher_id, 'status' => Carbon::parse($s['date'])->lt(today()) ? 'completed' : 'scheduled',
                ]);
            }
        }

        return $class;
    }

    private function openStage(ClassModel $class, SyllabusCurriculum $curriculum, User $lead): void
    {
        if (SyllabusAssignment::open()->where('class_id', $class->id)->exists()) {
            return;
        }
        app(SyllabusProgressionService::class)->open($class, $curriculum->stages()->orderBy('position')->firstOrFail(), $lead->id, $lead, 'Dữ liệu test TK-2026-0114');
    }

    /** GVNN có công cho mọi buổi đã qua của lớp (khóa user + buổi nên chạy lại không nhân đôi). */
    private function seedGvnnTimesheets(ClassModel $class, User $gvnn): void
    {
        foreach ($class->sessions()->where('status', 'completed')->get() as $session) {
            TeacherTimesheet::updateOrCreate(['user_id' => $gvnn->id, 'class_session_id' => $session->id], [
                'class_id' => $class->id, 'teaching_date' => $session->date->toDateString(), 'scheduled_time' => '18:00-19:30',
                'hours' => 1.5, 'type' => 'regular', 'status' => 'valid', 'notes' => 'staging:seed-test-data',
            ]);
        }
    }

    /** Học viên + (tuỳ chọn) tài khoản portal đăng nhập được ngay, ghi danh vào lớp. */
    private function student(string $code, ?string $email, string $name, string $dob, ClassModel $base, ClassModel $class, string $status, bool $login): Student
    {
        $user = $login && $email ? $this->staff($email, 'TS-'.$code, $name, Roles::STUDENT, $base, ['date_of_birth' => $dob]) : null;
        $student = Student::firstOrNew(['code' => $code]);
        $student->fill([
            'name' => $name, 'phone' => '0392'.substr(sprintf('%06d', crc32($code) % 1000000), 0, 6), 'parent_name' => 'PH '.$name,
            'parent_phone' => '0393'.substr(sprintf('%06d', crc32($code) % 1000000), 0, 6), 'email' => $email, 'dob' => $dob,
            'branch_id' => $base->branch_id, 'current_class_id' => $class->id, 'user_id' => $user?->id, 'total_lessons' => 36, 'status' => $status,
        ])->save();
        ClassEnrollment::firstOrCreate(['student_id' => $student->id, 'class_id' => $class->id], [
            'enrolled_at' => $class->start_date, 'status' => 'completed', 'account_sent' => true, 'curriculum_delivered' => true,
        ]);

        return $student;
    }

    private function tuition(Student $student, ClassModel $class, int $sessions, float $paid, string $dueDate): void
    {
        $total = (float) $class->tuition_fee * $sessions / 36;
        StudentTuition::updateOrCreate(['student_id' => $student->id, 'class_id' => $class->id], [
            'branch_id' => $class->branch_id, 'session_count' => $sessions, 'total_amount' => $total, 'final_amount' => $total,
            'paid_amount' => $paid, 'debt_amount' => $total - $paid, 'due_date' => $dueDate, 'status' => $paid >= $total ? 'paid' : 'unpaid',
        ]);
    }

    private function seedPracticeExam(): void
    {
        $exam = PracticeExam::firstOrCreate(['code' => 'TS-601'], [
            'grade' => 6, 'term' => 'gk1', 'title' => 'Đề test TS-601', 'duration_minutes' => 15, 'status' => PracticeExam::PUBLISHED, 'published_at' => now(),
        ]);
        foreach ([1 => 'A', 2 => 'B', 3 => 'C'] as $no => $answer) {
            QuestionBankQuestion::firstOrCreate(['source_key' => "TS-601-Q{$no}"], [
                'exam_id' => $exam->id, 'grade' => 6, 'term' => 'gk1', 'position' => $no, 'skill' => 'Ngữ âm', 'type' => 'Phát âm',
                'instruction' => 'Mark the letter', 'stem' => "Câu {$no}", 'options' => ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'],
                'answer' => $answer, 'status' => 'approved',
            ]);
        }
    }

    /** Audio mẫu (WAV 1 giây im lặng) trong giáo trình của chặng học viên đang học. */
    private function seedAudio(SyllabusCurriculum $curriculum, User $uploader): void
    {
        $path = SyllabusDocument::DIRECTORY.'/ts-audio-mau.wav';
        $disk = Storage::disk(SyllabusDocument::DISK);
        if (! $disk->exists($path)) {
            $data = str_repeat("\0\0", 8000);
            $disk->put($path, 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($data)).$data);
        }
        SyllabusDocument::firstOrCreate(['curriculum_id' => $curriculum->id, 'title' => 'TS Audio mẫu'], [
            'file_path' => $path, 'original_name' => 'ts-audio-mau.wav', 'extension' => 'wav', 'mime_type' => 'audio/wav',
            'size_bytes' => $disk->size($path), 'visible_to_teachers' => true, 'visible_to_assistants' => true, 'downloadable' => true, 'uploaded_by' => $uploader->id,
        ]);
    }

    private function trialGuest(ClassModel $base, User $sales): void
    {
        $session = $base->sessions()->where('status', 'scheduled')->whereDate('date', '>=', today())->orderBy('date')->orderBy('start_time')->first();
        if (! $session) {
            throw new RuntimeException('Lớp '.$base->code.' không còn buổi sắp tới để đặt học thử.');
        }
        $customer = CrmCustomer::firstOrCreate(['phone' => '0394000001'], [
            'code' => CrmCustomer::generateCode(), 'name' => 'Khách học thử (test)', 'parent_name' => 'PH khách học thử',
            'branch_id' => $base->branch_id, 'stage' => 'consulting', 'assigned_user_id' => $sales->id, 'source' => 'Facebook',
        ]);
        CrmTrialBooking::firstOrCreate(['customer_id' => $customer->id, 'class_id' => $base->id], [
            'class_session_id' => $session->id, 'booked_by' => $sales->id, 'status' => 'scheduled', 'notes' => 'Dữ liệu test TK-2026-0114',
        ]);
    }
}
