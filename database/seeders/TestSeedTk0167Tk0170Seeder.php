<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassScheduleConfig;
use App\Models\ClassSession;
use App\Models\ClassSessionFeedback;
use App\Models\CrmCustomer;
use App\Models\Holiday;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\MiniTestScore;
use App\Models\OperatingExpense;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherTimesheet;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Services\SessionScheduleService;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time TEST data for support tickets TK-2026-0167 (payroll GD-05/GD-06) and TK-2026-0170 (sections A-D).
 * Staging/local/testing only, idempotent (fixed keys), every row is named/noted "TESTSEED ...".
 * Touches only the Test GD branch (code GDT), *.gd@test.menglish / *.portal@test.menglish accounts and TESTSEED rows.
 * Never closes, locks or recalculates a payroll period. Section 14 (TK-2026-0165) is intentionally not seeded.
 * Run: php artisan db:seed --class=TestSeedTk0167Tk0170Seeder
 */
class TestSeedTk0167Tk0170Seeder extends Seeder
{
    public const ALLOWED_ENVIRONMENTS = ['staging', 'local', 'testing'];

    private const PASSWORD = 'Test@2026';

    private const PERIOD = 'PR-2026-10';

    /** @var array<string, mixed> ids/codes of seeded objects, printed at the end for the ticket comment */
    public array $summary = [];

    private Branch $branch;

    public function run(): void
    {
        if (! in_array(app()->environment(), self::ALLOWED_ENVIRONMENTS, true)) {
            throw new RuntimeException('TestSeedTk0167Tk0170Seeder chỉ chạy trên staging/local/testing, không chạy ở '.app()->environment().'.');
        }

        DB::transaction(function () {
            $this->branch = Branch::firstOrCreate(['code' => 'GDT'], ['name' => 'Chi nhánh Test GD', 'address' => 'Chi nhánh test lương (dữ liệu test, không dùng thật)', 'is_active' => true]);
            $this->payrollGd05();
            $this->payrollGd06();
            $this->epic9();
            $this->portal();
            $this->finance();
            $this->tuition();
        });

        $this->command?->info('TESTSEED summary: '.json_encode($this->summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ---------------------------------------------------------------- TK-0167

    private function payrollGd05(): void
    {
        $nnA = $this->staff('nn-a.gd@test.menglish', 'TESTSEED-NN-A', 'GD NN-A (test)', Roles::FOREIGN_TEACHER, 'Bán thời gian');
        $ptB = $this->staff('pt-b.gd@test.menglish', 'TESTSEED-PT-B', 'GD PT-B (test)', Roles::TEACHER_PARTTIME, 'Bán thời gian');
        $ptC = $this->staff('pt-c.gd@test.menglish', 'TESTSEED-PT-C', 'GD PT-C (test)', Roles::TEACHER_PARTTIME, 'Bán thời gian');
        $this->rate($nnA, 5000, TeacherHourlyRate::UNIT_MINUTE, 'foreign');
        $this->rate($ptB, 150000, TeacherHourlyRate::UNIT_SESSION, 'parttime');
        $this->rate($ptC, 170000, TeacherHourlyRate::UNIT_SESSION, 'parttime');

        // NN-A: 20 x 90 minutes in T10/2026 (= 1.800 min x 5.000 = 9.000.000) + 1 absent session without penalty.
        $nn = $this->klass('TESTSEED-NN-A', 'TESTSEED NN-A (GVNN dạy T10)', 'completed', '2026-10-01', '2026-10-31', [], ['foreign_teacher_id' => $nnA->id]);
        for ($i = 0; $i < 21; $i++) {
            $date = Carbon::create(2026, 10, 1)->addDays($i)->toDateString();
            $session = ClassSession::where('class_id', $nn->id)->whereDate('date', $date)->first() ?? ClassSession::create([
                'class_id' => $nn->id, 'date' => $date, 'start_time' => '18:00',
                'branch_id' => $this->branch->id, 'shift_name' => 'Ca tối', 'end_time' => '19:30', 'foreign_teacher_id' => $nnA->id,
                'status' => 'completed', 'notes' => 'TESTSEED GD-05',
            ]);
            $absent = $i === 20;
            TeacherTimesheet::updateOrCreate(['user_id' => $nnA->id, 'class_session_id' => $session->id], [
                'class_id' => $nn->id, 'teaching_date' => $date, 'scheduled_time' => '18:00-19:30', 'hours' => $absent ? 0 : 1.5,
                'type' => 'regular', 'source' => TeacherTimesheet::SOURCE_MANUAL, 'status' => $absent ? 'invalid' : 'valid',
                'notes' => $absent ? 'TESTSEED NN-A vắng (không phạt)' : 'TESTSEED NN-A',
            ]);
        }

        // PT-C substitutes PT-B on one session and checks in: PT-C +170.000, PT-B gets no timesheet for it.
        $sub = $this->klass('TESTSEED-PT-BC', 'TESTSEED PT-B/PT-C (dạy thay)', 'completed', '2026-10-12', '2026-10-12', [], ['teacher_id' => $ptB->id]);
        $session = ClassSession::where('class_id', $sub->id)->whereDate('date', '2026-10-12')->first() ?? ClassSession::create([
            'class_id' => $sub->id, 'date' => '2026-10-12', 'start_time' => '18:00',
            'branch_id' => $this->branch->id, 'shift_name' => 'Ca tối', 'end_time' => '19:30', 'teacher_id' => $ptB->id, 'status' => 'completed', 'notes' => 'TESTSEED GD-05 dạy thay',
        ]);
        TeacherTimesheet::updateOrCreate(['user_id' => $ptC->id, 'class_session_id' => $session->id], [
            'class_id' => $sub->id, 'teaching_date' => '2026-10-12', 'scheduled_time' => '18:00-19:30', 'checkin_time' => '18:00', 'checkout_time' => '19:30',
            'hours' => 1.5, 'type' => 'sub', 'source' => TeacherTimesheet::SOURCE_CHECKIN, 'status' => 'valid', 'notes' => 'TESTSEED PT-C dạy thay PT-B',
        ]);
        $this->summary['GD-05'] = ['NN-A' => $nnA->id, 'PT-B' => $ptB->id, 'PT-C' => $ptC->id, 'classes' => [$nn->code, $sub->code]];
    }

    private function payrollGd06(): void
    {
        $htA = User::where('email', 'ht-a.gd@test.menglish')->first();
        if (! $htA) {
            $this->command?->warn('ht-a.gd@test.menglish chưa có — bỏ qua GD-06.');

            return;
        }
        $record = PayrollRecord::where('user_id', $htA->id)->whereIn('payroll_period_id', PayrollPeriod::where('code', self::PERIOD)->select('id'))->first();
        if ($record) {
            // Manual inputs survive "Đồng bộ & Tính lại"; only fill what is still empty.
            $lines = (array) ($record->manual_lines ?? []);
            $hasSupport = collect($lines)->contains(fn ($l) => (float) ($l['amount'] ?? 0) === 300000.0 && ($l['kind'] ?? '') === 'earning');
            $record->forceFill([
                'kpi_manual_amount' => $record->kpi_manual_amount ?? 500000,
                'manual_lines' => $hasSupport ? $lines : [...$lines, ['kind' => 'earning', 'label' => 'TESTSEED Hỗ trợ HT-02', 'amount' => 300000]],
            ])->saveQuietly();
        }
        $penalty = Penalty::withoutViewerScope()->where('notes', 'TESTSEED GD-06 phạt 50.000')->first()
            ?? Penalty::withoutViewerScope()->create([
                'code' => Penalty::generateCode(), 'user_id' => $htA->id, 'violation_type' => 'operations', 'error_category' => 'Vi phạm nội quy trung tâm',
                'violation_date' => '2026-10-20', 'amount' => 50000, 'status' => 'fined', 'notes' => 'TESTSEED GD-06 phạt 50.000',
            ]);
        if ($record && ! $penalty->payroll_record_id) {
            $penalty->forceFill(['payroll_record_id' => $record->id])->saveQuietly();
        }
        $this->summary['GD-06'] = ['HT-A' => $htA->id, 'payroll_record' => $record?->id, 'penalty' => $penalty->code, 'note' => $record ? null : 'Chưa có bảng lương '.self::PERIOD.' của HT-A: bấm Đồng bộ & Tính lại rồi chạy lại seeder.'];
    }

    // ---------------------------------------------------------------- TK-0170 A

    private function epic9(): void
    {
        // 1) EP9-IM-05: two students with the same name and birthday.
        foreach (['TESTSEED-HS10', 'TESTSEED-HS11'] as $code) {
            $dup[] = $this->student($code, 'TESTSEED Trung Ten', '2014-03-03', 'waiting_start')->code;
        }
        // 2) EP9-LH-11 / 3) QL-08, 09, 15 / 4) PH-21
        $p1 = $this->room('TESTSEED P1', 'TESTSEED LH-11');
        $p2 = $this->room('TESTSEED P2', 'TESTSEED PH-21');
        $future = today()->addDays(14);
        $w = $this->klass('TESTSEED-W', 'TESTSEED W (chờ khai giảng, P1 Thứ 3)', 'upcoming', $future->toDateString(), $future->copy()->addMonths(2)->toDateString(), [['Thứ 3', '18:00', '19:30']], ['room_id' => $p1->id, 'room' => $p1->name]);
        $w2 = $this->klass('TESTSEED-W2', 'TESTSEED W2 (chờ khai giảng, P1 Thứ 4)', 'upcoming', $future->toDateString(), $future->copy()->addMonths(2)->toDateString(), [['Thứ 4', '18:00', '19:30']], ['room_id' => $p1->id, 'room' => $p1->name]);
        $z = $this->klass('TESTSEED-Z', 'TESTSEED Z (đang học, chưa gắn phòng, Thứ 3 trùng W)', 'active', today()->addDays(3)->toDateString(), today()->addMonths(3)->toDateString(), [['Thứ 3', '18:00', '19:30']]);
        $y = $this->klass('TESTSEED-Y', 'TESTSEED Y (đang học, P2 Thứ 2)', 'active', today()->subWeeks(1)->toDateString(), today()->addMonths(3)->toDateString(), [['Thứ 2', '18:00', '19:30']], ['room_id' => $p2->id, 'room' => $p2->name]);
        $act = $this->klass('TESTSEED-ACT', 'TESTSEED ACT (đang học, Thứ 5)', 'active', today()->subWeeks(1)->toDateString(), today()->addMonths(3)->toDateString(), [['Thứ 5', '18:00', '19:30']], ['room_id' => $p2->id, 'room' => $p2->name]);
        $end = $this->klass('TESTSEED-END', 'TESTSEED END (đã kết thúc, Thứ 6)', 'completed', today()->subMonths(3)->toDateString(), today()->subWeeks(2)->toDateString(), [['Thứ 6', '18:00', '19:30']]);

        // Class-specific holiday: branch-level (non system-wide) day that falls on a future Thursday session of ACT.
        $holidayDate = ClassSession::where('class_id', $act->id)->whereDate('date', '>', today()->addDays(3))->orderBy('date')->value('date');
        $holiday = Holiday::firstOrCreate(['code' => 'HOL-TESTSEED-01'], [
            'name' => 'TESTSEED Nghỉ riêng chi nhánh Test GD', 'start_date' => $holidayDate, 'end_date' => $holidayDate, 'is_system_wide' => false,
        ]);
        DB::table('holiday_branches')->updateOrInsert(['holiday_id' => $holiday->id, 'branch_id' => $this->branch->id], ['updated_at' => now(), 'created_at' => now()]);

        // PH-21: date E = first Wednesday at least 7 days ahead; the Monday session of Y moves there.
        $e = today()->addDays(7)->next(Carbon::WEDNESDAY);
        // IM-10: fresh student without any enrollment + two active classes on non-overlapping slots.
        $im = $this->student('TESTSEED-HS12', 'TEST EP9 IM10', '2013-04-05', 'waiting_start');
        $im1 = $this->klass('TESTSEED-IM10A', 'TESTSEED IM10 A (Thứ 7)', 'active', today()->subWeeks(1)->toDateString(), today()->addMonths(3)->toDateString(), [['Thứ 7', '10:00', '11:30']]);
        $im2 = $this->klass('TESTSEED-IM10B', 'TESTSEED IM10 B (Chủ nhật)', 'active', today()->subWeeks(1)->toDateString(), today()->addMonths(3)->toDateString(), [['Chủ nhật', '10:00', '11:30']]);

        $this->summary['A'] = [
            'IM-05' => $dup, 'LH-11' => [$w->code, $z->code, $w2->code, 'room' => $p1->name], 'QL-08/09/15' => [$act->code, $end->code, 'holiday' => $holiday->code.' '.Carbon::parse($holidayDate)->toDateString()],
            'PH-21' => ['class' => $y->code, 'ngày E (Thứ 4)' => $e->toDateString()], 'IM-10' => [$im->code, $im1->code, $im2->code],
            'job sinh buổi (OP-04/OP-06)' => 'không seed: giờ job xem routes/console.php',
        ];
    }

    // ---------------------------------------------------------------- TK-0170 B

    private function portal(): void
    {
        $gvA = $this->staff('gv-a.portal@test.menglish', 'TESTSEED-GV-A', 'PORTAL GV-A (test)', Roles::TEACHER_PARTTIME, 'Bán thời gian');
        $gvB = $this->staff('gv-b.portal@test.menglish', 'TESTSEED-GV-B', 'PORTAL GV-B (test)', Roles::TEACHER_PARTTIME, 'Bán thời gian');
        $p1 = $this->klass('TESTSEED-PORTAL-1', 'TESTSEED PORTAL-1', 'active', today()->subWeeks(4)->toDateString(), today()->addMonths(2)->toDateString(), [['Thứ 3', '18:00', '19:30'], ['Thứ 5', '18:00', '19:30']], ['teacher_id' => $gvA->id]);
        $p2 = $this->klass('TESTSEED-PORTAL-2', 'TESTSEED PORTAL-2 (lớp khác)', 'active', today()->subWeeks(4)->toDateString(), today()->addMonths(2)->toDateString(), [['Thứ 4', '18:00', '19:30']], ['teacher_id' => $gvB->id]);

        $s = [];
        foreach (['HS-A' => $p1, 'HS-B' => $p1, 'HS-C' => $p2, 'HS-X' => $p1, 'HS-Y' => $p1, 'HS-T' => $p2] as $label => $class) {
            $s[$label] = $this->student('TESTSEED-PORTAL-'.$label, 'TESTSEED PORTAL '.$label, '2014-01-15', 'studying', $class, strtolower($label).'.portal@test.menglish');
        }
        // AC-S06d: HS-T moved from PORTAL-1 (left) to PORTAL-2 (current).
        ClassEnrollment::firstOrCreate(['student_id' => $s['HS-T']->id, 'class_id' => $p1->id], ['enrolled_at' => $p1->start_date, 'left_at' => today()->subWeek(), 'status' => 'completed']);

        // Trial guest who has not booked a trial yet.
        $guest = CrmCustomer::firstOrCreate(['phone' => '0394000170'], [
            'code' => CrmCustomer::generateCode(), 'name' => 'TESTSEED Khách chưa đặt học thử', 'parent_name' => 'TESTSEED PH khách',
            'branch_id' => $this->branch->id, 'stage' => 'consulting', 'source' => 'Facebook',
        ]);

        // 6) quiz homework (HS-A on time, HS-B late) + workbook homework.
        $due = today()->subDays(2)->setTime(23, 59);
        $quiz = Homework::firstOrCreate(['class_id' => $p1->id, 'title' => 'TESTSEED Quiz HS tự nộp'], ['user_id' => $gvA->id, 'quizizz_url' => 'https://quizizz.com/testseed', 'due_date' => $due->toDateString(), 'due_at' => $due]);
        HomeworkSubmission::firstOrCreate(['homework_id' => $quiz->id, 'student_id' => $s['HS-A']->id, 'basket' => 'quizizz'], ['status' => 'submitted', 'submitted_at' => $due->copy()->subDay(), 'note' => 'TESTSEED nộp đúng hạn']);
        HomeworkSubmission::firstOrCreate(['homework_id' => $quiz->id, 'student_id' => $s['HS-B']->id, 'basket' => 'quizizz'], ['status' => 'submitted', 'submitted_at' => $due->copy()->addDay(), 'note' => 'TESTSEED nộp sau hạn']);
        $book = Homework::firstOrCreate(['class_id' => $p1->id, 'title' => 'TESTSEED Workbook'], ['user_id' => $gvA->id, 'description' => 'TESTSEED workbook PORTAL-1', 'due_date' => today()->addDays(7)->toDateString(), 'due_at' => today()->addDays(7)->setTime(23, 59)]);

        // 7+9) mini tests: HS-A/HS-B latest below 60%; HS-X one component below 60%; HS-Y all above. (AC-S06b/c interpreted this way.)
        foreach ([['HS-A', 'TESTSEED Mini 1', 80, 40], ['HS-B', 'TESTSEED Mini 1', 90, 50], ['HS-X', 'TESTSEED Mini 1', 85, 70], ['HS-Y', 'TESTSEED Mini 1', 90, 90]] as [$who, $name, $prev, $last]) {
            MiniTestScore::firstOrCreate(['class_id' => $p1->id, 'student_id' => $s[$who]->id, 'name' => $name.' (cũ)'], ['user_id' => $gvA->id, 'score' => $prev, 'max_score' => 100, 'test_date' => today()->subWeeks(3)]);
            MiniTestScore::firstOrCreate(['class_id' => $p1->id, 'student_id' => $s[$who]->id, 'name' => $name.' (gần nhất)'], [
                'user_id' => $gvA->id, 'score' => $last, 'max_score' => 100, 'test_date' => today()->subWeek(),
                'skill_scores' => $who === 'HS-X' ? ['listening' => 50, 'reading' => 90] : null,
            ]);
        }

        // 8) session feedback: GV-A first (ordinary), GV-B (substitute) later; HS-C is not in the class.
        $session = ClassSession::where('class_id', $p1->id)->where('status', 'completed')->orderByDesc('date')->first();
        if ($session) {
            $session->forceFill(['teacher_id' => $gvA->id])->saveQuietly();
            ClassSessionFeedback::firstOrCreate(['class_session_id' => $session->id, 'author_id' => $gvA->id, 'source' => 'staff'], ['class_id' => $p1->id, 'verdict' => 'good', 'content' => 'TESTSEED nhận xét GV chính']);
            $summaryFeedback = $session->id;
        }

        // 10) two new violation records for GV-A (Home widget should show 2).
        $pen = [];
        foreach (['TESTSEED GV-A vi phạm 1', 'TESTSEED GV-A vi phạm 2'] as $note) {
            $pen[] = (Penalty::withoutViewerScope()->where('notes', $note)->first() ?? Penalty::withoutViewerScope()->create([
                'code' => Penalty::generateCode(), 'user_id' => $gvA->id, 'violation_type' => 'academic', 'error_category' => 'Gửi nhận xét sau buổi học trễ',
                'violation_date' => today()->subDays(2)->toDateString(), 'amount' => 0, 'status' => 'pending', 'notes' => $note,
            ]))->code;
        }

        $this->summary['B'] = [
            'class' => [$p1->code, $p2->code], 'GV-A' => $gvA->email, 'GV-B' => $gvB->email, 'students' => collect($s)->map(fn ($x) => $x->code.' '.$x->email)->all(),
            'guest' => $guest->code, 'quiz' => $quiz->id, 'workbook' => $book->id, 'feedback_session' => $summaryFeedback ?? null, 'penalties GV-A' => $pen, 'password' => self::PASSWORD,
        ];
    }

    // ---------------------------------------------------------------- TK-0170 C / D

    private function finance(): void
    {
        $student = $this->student('TESTSEED-HS01', 'TESTSEED HS 01 (DT-06 phiếu thu ngày biên)', null, 'studying');
        $tuition = StudentTuition::updateOrCreate(['student_id' => $student->id, 'class_id' => null], [
            'branch_id' => $this->branch->id, 'session_count' => 24, 'total_amount' => 6000000, 'final_amount' => 6000000, 'paid_amount' => 6000000, 'debt_amount' => 0, 'status' => 'paid',
        ]);
        // Receipts / expenses on 01/10, 31/10 and 01/11 in the Test GD branch.
        $rows = [['2026-10-01', 1000000, 1], ['2026-10-31', 2000000, 2], ['2026-11-01', 3000000, 3]];
        $out = [];
        foreach ($rows as [$date, $amount, $n]) {
            $receipt = TuitionReceipt::firstOrCreate(['receipt_number' => sprintf('TESTSEED-PT-%04d', $n)], [
                'student_tuition_id' => $tuition->id, 'student_id' => $student->id, 'amount' => $amount, 'tuition_amount' => $amount, 'payment_method' => 'cash',
                'payer_name' => 'TESTSEED PH', 'payment_date' => $date, 'status' => TuitionReceipt::STATUS_APPROVED, 'approved_at' => $date.' 12:00:00', 'notes' => 'TESTSEED DT-06',
            ]);
            $expense = OperatingExpense::firstOrCreate(['title' => 'TEST DT-06 chi ngay '.Carbon::parse($date)->format('d/m')], [
                'expense_date' => $date, 'amount' => $amount, 'payment_method' => 'tien_mat', 'branch_id' => $this->branch->id, 'category' => 'khac', 'notes' => 'TESTSEED DT-06',
            ]);
            $out[$date] = ['receipt' => $receipt->receipt_number, 'expense' => $expense->id, 'amount' => $amount];
        }
        $this->summary['C'] = $out;
    }

    private function tuition(): void
    {
        // 12) HP-DH-02: unpaid tuition due 1, 3, 6, 7, 8 days ago.
        $out = [];
        foreach ([2 => 1, 3 => 3, 4 => 6, 5 => 7, 6 => 8] as $n => $days) {
            $st = $this->student(sprintf('TESTSEED-HS%02d', $n), sprintf('TESTSEED HS %02d (quá hạn %d ngày)', $n, $days), null, 'studying');
            StudentTuition::updateOrCreate(['student_id' => $st->id, 'class_id' => null], [
                'branch_id' => $this->branch->id, 'session_count' => 12, 'total_amount' => 2000000, 'final_amount' => 2000000, 'paid_amount' => 0, 'debt_amount' => 2000000,
                'due_date' => today()->subDays($days)->toDateString(), 'status' => 'unpaid',
            ]);
            $out[$st->code] = $days;
        }
        // 13) HP-LP-05: negative remaining money (paid more than the total) for one student.
        $neg = $this->student('TESTSEED-HS07', 'TESTSEED HS 07 (LP-05 tiền âm)', null, 'studying');
        StudentTuition::updateOrCreate(['student_id' => $neg->id, 'class_id' => null], [
            'branch_id' => $this->branch->id, 'session_count' => 10, 'total_amount' => 1000000, 'final_amount' => 1000000, 'paid_amount' => 1500000, 'debt_amount' => -500000, 'status' => 'unpaid',
        ]);
        $out[$neg->code] = 'debt -500.000';
        $this->summary['D'] = $out;
    }

    // ---------------------------------------------------------------- helpers

    private function staff(string $email, string $code, string $name, string $role, ?string $contract = null): User
    {
        $user = User::withTrashed()->where('email', $email)->first() ?? new User;
        if ($user->trashed()) {
            $user->restore();
        }
        if (! $user->exists) {
            $user->fill([
                'employee_code' => $code, 'name' => $name, 'email' => $email, 'phone' => '0391'.substr(sprintf('%06d', crc32($email) % 1000000), 0, 6),
                'branch_id' => $this->branch->id, 'password' => self::PASSWORD, 'is_active' => true, 'must_change_password' => false, 'email_verified_at' => now(),
                'contract_type' => $contract, 'base_salary' => 0,
            ])->save();
        }
        $user->syncRoles([$role]);

        return $user;
    }

    private function rate(User $user, float $rate, string $unit, string $type): void
    {
        // whereDate: effective_from is a date cast, so a plain key lookup can miss and hit the (user_id, effective_from) unique index.
        $row = TeacherHourlyRate::where('user_id', $user->id)->whereDate('effective_from', '2026-10-01')->first() ?? new TeacherHourlyRate(['user_id' => $user->id, 'effective_from' => '2026-10-01']);
        $row->fill(['hourly_rate' => $rate, 'rate_unit' => $unit, 'teacher_type' => $type, 'note' => 'TESTSEED GD-05'])->save();
    }

    private function room(string $name, string $description): Room
    {
        $type = RoomType::query()->first() ?? RoomType::create(['name' => 'TESTSEED Phòng học']);

        return Room::firstOrCreate(['branch_id' => $this->branch->id, 'name' => $name], ['room_type_id' => $type->id, 'capacity' => 10, 'description' => $description]);
    }

    /** @param  list<array{0:string,1:string,2:string}>  $slots  [day, start, end] */
    private function klass(string $code, string $name, string $status, string $from, string $to, array $slots, array $extra = []): ClassModel
    {
        $class = ClassModel::firstOrNew(['code' => $code]);
        $class->fill($extra + [
            'name' => $name, 'branch_id' => $this->branch->id, 'status' => $status, 'start_date' => $from, 'end_date' => $to, 'max_capacity' => 12, 'min_students' => 6,
            'tuition_fee' => 0, 'schedule_text' => collect($slots)->map(fn ($s) => "{$s[0]} {$s[1]}-{$s[2]}")->implode('; '), 'notes' => 'TESTSEED TK-2026-0167/0170',
        ])->save();
        if ($slots && ! $class->sessions()->exists()) {
            $slotDefs = array_map(fn ($s) => ['day' => $s[0], 'start' => $s[1], 'end' => $s[2], 'shift' => 'Ca tối'], $slots);
            foreach (app(SessionScheduleService::class)->generate($slotDefs, Carbon::parse($from), Carbon::parse($to), $this->branch->id) as $s) {
                ClassSession::create([
                    'class_id' => $class->id, 'branch_id' => $this->branch->id, 'date' => $s['date'], 'shift_name' => $s['shift'], 'start_time' => $s['start'], 'end_time' => $s['end'],
                    'room' => $class->room, 'teacher_id' => $class->teacher_id, 'foreign_teacher_id' => $class->foreign_teacher_id,
                    'status' => Carbon::parse($s['date'])->lt(today()) ? 'completed' : 'scheduled',
                ]);
            }
            $first = $slots[0];
            ClassScheduleConfig::firstOrCreate(['class_id' => $class->id], ['academic_year' => '2026 - 2027', 'slot1_day' => $first[0], 'slot1_start' => $first[1], 'slot1_end' => $first[2]]);
        }

        return $class;
    }

    private function student(string $code, string $name, ?string $dob, string $status, ?ClassModel $class = null, ?string $email = null): Student
    {
        $user = $email ? $this->staff($email, 'TS-'.$code, $name, Roles::STUDENT) : null;
        $student = Student::firstOrNew(['code' => $code]);
        if (! $student->exists) {
            $student->fill([
                'name' => $name, 'phone' => '0392'.substr(sprintf('%06d', crc32($code) % 1000000), 0, 6), 'parent_name' => 'TESTSEED PH', 'parent_phone' => '0393'.substr(sprintf('%06d', crc32($code) % 1000000), 0, 6),
                'dob' => $dob, 'branch_id' => $this->branch->id, 'current_class_id' => $class?->id, 'user_id' => $user?->id, 'email' => $email, 'total_lessons' => 36, 'status' => $status,
            ])->save();
        }
        if ($class) {
            ClassEnrollment::firstOrCreate(['student_id' => $student->id, 'class_id' => $class->id], ['enrolled_at' => $class->start_date, 'status' => 'completed', 'account_sent' => true, 'curriculum_delivered' => true]);
        }

        return $student;
    }
}
