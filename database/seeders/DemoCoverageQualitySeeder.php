<?php

namespace Database\Seeders;

use App\Http\Controllers\ClassChecklistController;
use App\Http\Controllers\ObservationController;
use App\Http\Controllers\StaffReportController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\TeacherMeetingReportController;
use App\Http\Controllers\WorkTaskController;
use App\Models\AcademicObservation;
use App\Models\AdminNotification;
use App\Models\ClassChecklist;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\ClassSession;
use App\Models\KpiCriterion;
use App\Models\QaObservation;
use App\Models\StaffReport;
use App\Models\StaffReportFollowup;
use App\Models\SupportTicket;
use App\Models\TeacherMeetingReport;
use App\Models\User;
use App\Models\WorkTask;
use App\Support\MonthlyReportDue;
use App\Support\ReportPeriod;
use App\Support\Roles;
use Closure;
use Database\Seeders\Concerns\InvokesControllersAsUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Dữ liệu demo "Báo cáo & chất lượng": đủ trạng thái, cả đẹp lẫn xấu, dựng trên tài khoản UserSeeder và lớp DEMO-* của
 * DemoPhase1Seeder (lớp K28 của DemoPayrollSeeder có thì thêm vài dòng). Mốc thời gian tương đối với hôm nay
 * (L = tháng trước, C = tháng này); mọi thao tác đi qua controller thật với "đồng hồ" đặt đúng lúc trong quá khứ.
 * - Báo cáo định kỳ (Báo cáo của tôi): Học vụ nộp báo cáo NGÀY (phần lớn trước 09:00 hôm sau, 1 báo cáo trễ, 1 ngày bỏ trống)
 *   + báo cáo tuần KPI; Học thuật nộp báo cáo TUẦN (1 tuần nộp trễ) + báo cáo tháng L + báo cáo quý trước; giáo viên nộp báo
 *   cáo tháng theo lớp L (1 đúng hạn, 1 trễ sau mùng 2, 1 GV không nộp); trợ giảng nộp báo cáo tháng (1 đúng hạn, 1 trễ).
 *   Lệnh reports:remind-monthly chạy đúng ngày hạn tháng L → thông báo nhắc cho người chưa nộp. Báo cáo định kỳ không có
 *   trạng thái duyệt / nhận xét (chỉ "submitted"), nên không có ca "đã duyệt".
 * - Nhật ký sự vụ: đủ mức Bình thường / Quan trọng / Khẩn cấp, trạng thái Mới / Đang theo dõi / Đã xử lý, có follow-up của
 *   người ghi và Quản lý; 1 sự vụ khẩn cấp còn đang theo dõi.
 * - Dự giờ vận hành: đủ 4 xếp loại (Xuất sắc / Tốt / Đạt / Cần cải thiện) trên buổi học thật, GV chính và GVNN; 1 GV bị
 *   "Cần cải thiện" được dự giờ lại và lên "Đạt".
 * - Đánh giá dự giờ học thuật: lớp đã dự giờ với kết luận 4 mức, lớp chưa dự giờ chỉ có tỉ lệ, lớp tháng này còn chờ.
 * - Checklist học phí & Big Test: Có / Không / N-A, lớp đủ, lớp thiếu sót (đến hạn mà chưa làm), dòng chưa đánh hết.
 * - Báo cáo họp giáo viên: Đã xử lý / Đang xử lý / GV báo cáo sơ sài / GV chưa báo cáo / Báo cáo trùng lặp.
 * - Báo cáo trực lớp: 1 báo cáo không ảnh bị Admin trả về (đã duyệt / chờ duyệt có sẵn từ DemoPhase4Seeder).
 * - Ticket: đã đóng, mở lại sau khi đã giải quyết, ticket Khẩn cấp quá 3 ngày chưa ai nhận (sản phẩm chưa có SLA ticket — đây là
 *   ca "quá hạn" theo thực tế), ticket ưu tiên thấp mới tạo.
 *
 * Gọi từ DemoCoverageSeeder (php artisan demo:luong); chạy được trên dữ liệu db:seed thường. Idempotent: đã có sự vụ đầu tiên
 * (MARKER) thì bỏ qua. Toàn bộ trong 1 transaction.
 */
class DemoCoverageQualitySeeder extends Seeder
{
    use InvokesControllersAsUser;

    /** Tiêu đề sự vụ đầu tiên — dấu hiệu seeder đã chạy. */
    public const MARKER = '# PH phản ánh GV vào lớp muộn 10 phút (FAM 1 CG)';

    private const STAFF = [
        'admin' => 'admin@menglish.edu.vn',
        'manager_cg' => 'manager@menglish.edu.vn',
        'manager_bd' => 'manager.bd@menglish.edu.vn',
        'lead' => 'academiclead@menglish.edu.vn',
        'academic_cg' => 'nva@menglish.edu.vn',
        'academic_bd' => 'giaovu2@menglish.edu.vn',
        'teacher_cg' => 'nguyenvanan@menglish.edu.vn',
        'teacher_ft_cg' => 'gv.cohuu1@menglish.edu.vn',
        'teacher_bd' => 'gv.cohuu2@menglish.edu.vn',
        'native_cg' => 'gv.native1@menglish.edu.vn',
        'assistant_cg' => 'ta.tuan@menglish.edu.vn',
        'assistant_bd' => 'ta.yen@menglish.edu.vn',
        'student_bd' => 'hocvien3@menglish.edu.vn',
        'sales_cg' => 'tranmaia@menglish.edu.vn',
    ];

    /** Tài khoản / lớp K28 của DemoPayrollSeeder: có thì thêm dòng, không có thì bỏ qua. */
    private const OPTIONAL_STAFF = ['academic_dd' => 'giaovu.dd@menglish.edu.vn'];

    private const CLASSES = ['CG-FAM1' => 'DEMO-CG-FAM1', 'CG-FAM0' => 'DEMO-CG-FAM0', 'BD-FAM1' => 'DEMO-BD-FAM1', 'BD-FAM0' => 'DEMO-BD-FAM0'];

    private const OPTIONAL_CLASSES = ['DD-GT1' => 'DEMO-DD-GT1', 'DD-IE1' => 'DEMO-DD-IE1'];

    /**
     * Nhật ký sự vụ: [người ghi, lớp | null, mức, tiêu đề, nội dung, số ngày trước, follow-up [[người, nội dung, số ngày trước]],
     * trạng thái cuối]. Follow-up đầu tiên chuyển "Mới" → "Đang theo dõi".
     */
    private const JOURNALS = [
        ['academic_cg', 'CG-FAM1', 'important', self::MARKER, 'PH bé Minh Anh gọi lên phản ánh tối thứ Tư GV vào lớp muộn 10 phút, lớp ồn.', 9,
            [['academic_cg', 'Đã gọi xin lỗi PH, xác minh với TA: GV kẹt xe, có báo trong nhóm nhưng muộn.', 9], ['manager_cg', 'Đã nhắc GV đến trước 15 phút; theo dõi thêm 2 tuần.', 8]], 'resolved'],
        ['academic_bd', 'BD-FAM1', 'urgent', '# Học viên trượt ngã ở cầu thang khi tan học', 'Bé Trọng trượt chân bậc cuối, trầy đầu gối. Đã sơ cứu, gọi PH đón.', 3,
            [['academic_bd', 'PH đã đón, bé ổn. Đề nghị dán băng chống trơn cầu thang.', 3], ['manager_bd', 'Đã báo tòa nhà, chờ thợ dán băng chống trơn — chưa xong.', 2]], 'following'],
        ['manager_cg', null, 'normal', '# Điều hòa phòng 203 kêu to, chảy nước', 'Phòng 203 dùng cho FAM 1, điều hòa chảy nước xuống bàn GV.', 1, [], 'open'],
        ['assistant_cg', 'CG-FAM0', 'normal', '# Học viên để quên bình nước và áo khoác', 'Đã cất ở quầy lễ tân, nhắn PH qua Zalo.', 6,
            [['assistant_cg', 'PH đã nhận lại đồ.', 5]], 'resolved'],
        ['lead', 'CG-FAM0', 'urgent', '# GV ốm đột xuất, cần dạy thay buổi tối', 'GV An báo sốt lúc 15:00, lớp FAM 0 học 18:00.', 12,
            [['lead', 'Đã xếp GV dạy thay, báo PH qua nhóm lớp.', 12]], 'resolved'],
        ['teacher_cg', 'CG-FAM1', 'important', '# PH xin chuyển lớp vì trùng lịch học bơi', 'PH bé Bảo Ngọc muốn chuyển sang lớp cuối tuần từ tháng sau.', 2, [], 'open'],
    ];

    /** Dự giờ vận hành: [lớp, vai GV ('teacher' | 'foreign'), người dự giờ, buổi thứ n tính lùi từ hôm qua, xếp loại, nhận xét [nội dung, tác phong, chuẩn bị, kỹ thuật, góp ý]]. */
    private const QA = [
        ['CG-FAM1', 'teacher', 'academic_cg', 3, 'good', ['Unit 4 Animals, đúng tiến độ.', 'Đúng giờ, thân thiện.', 'Có flashcard, slide.', 'Gọi đều HV, có game cuối giờ.', 'Tăng thời gian luyện nói theo cặp.']],
        ['CG-FAM1', 'foreign', 'manager_cg', 5, 'excellent', ['Speaking: hỏi đáp về con vật.', 'Năng lượng tốt, HV hào hứng.', 'Chuẩn bị đồ dùng thật.', 'TPR, sửa phát âm tức thì.', 'Chia sẻ hoạt động cho GV Việt.']],
        ['BD-FAM1', 'teacher', 'academic_bd', 2, 'pass', ['Ôn tập Unit 3.', 'Đúng giờ.', 'Giáo án sơ lược.', 'Chủ yếu GV nói, ít tương tác.', 'Thêm hoạt động nhóm, kiểm tra hiểu bài.']],
        ['CG-FAM0', 'teacher', 'academic_cg', 3, 'needs_improvement', ['Bài 2: Colors, chưa hết nội dung.', 'Vào lớp muộn 5 phút.', 'Không chuẩn bị tranh màu.', 'Lớp mất trật tự, HV nhỏ mất tập trung.', 'Dự giờ lại sau 2 tuần; chuẩn bị luật lớp + đồ dùng.']],
        ['BD-FAM0', 'teacher', 'academic_bd', 1, 'good', ['Bài 3: Numbers 1–10.', 'Nhẹ nhàng, kiên nhẫn.', 'Có bài hát, thẻ số.', 'Tương tác tốt với HV nhỏ.', 'Giữ nhịp, thêm phần thưởng sticker.']],
        ['CG-FAM0', 'teacher', 'academic_cg', 1, 'pass', ['Bài 4: Shapes, đủ nội dung.', 'Đúng giờ.', 'Đã có tranh, luật lớp.', 'Lớp trật tự hơn, còn ít hoạt động nói.', 'Tiến bộ so với lần trước, duy trì.']],
        ['DD-GT1', 'teacher', 'academic_dd', 2, 'excellent', ['Chủ đề Free time.', 'Chuyên nghiệp.', 'Slide + worksheet đầy đủ.', 'Role-play hiệu quả.', 'Có thể làm GV mẫu.']],
        ['DD-IE1', 'teacher', 'academic_dd', 1, 'needs_improvement', ['Reading: True/False/Not given.', 'Đúng giờ.', 'Đề luyện chưa sửa lỗi in.', 'Giảng giải dài, HV ít làm bài.', 'Chia nhỏ hoạt động, dành 50% thời gian cho HV.']],
    ];

    /** Đánh giá dự giờ học thuật: [lớp, tháng (-1 L, 0 C), dự giờ?, % chuyên cần, % đạt, kết luận (null = chưa dự giờ)]. */
    private const ACADEMIC = [
        ['CG-FAM1', -1, true, 92.5, 85, 'Tốt'],
        ['BD-FAM1', -1, true, 96, 92, 'Xuất sắc'],
        ['CG-FAM0', -1, false, 88, 70, null],
        ['CG-FAM0', 0, true, 81, 60, 'Cần cải thiện'],
        ['BD-FAM0', 0, true, 90, 78, 'Đạt'],
        ['DD-IE1', 0, true, 94, 80, 'Tốt'],
    ];

    private const ACADEMIC_NOTES = [
        'Xuất sắc' => ['Bài giảng mạch lạc, đúng chuẩn đầu ra.', 'Đủ nội dung, có mở rộng.', 'HV chủ động, phản hồi tốt.', 'Chuẩn mực.', '95% HV đạt mục tiêu buổi.', 'Xếp loại: Xuất sắc — đề xuất chia sẻ kinh nghiệm cho tổ.', 'Mời GV chia sẻ trong buổi họp chuyên môn.'],
        'Tốt' => ['Rõ ràng, đúng tiến độ.', 'Đủ nội dung.', 'Tương tác đều, đôi lúc GV nói nhiều.', 'Tốt.', 'Đa số HV làm được bài.', 'Xếp loại: Tốt.', 'Tăng hoạt động nói theo cặp.'],
        'Đạt' => ['Đạt yêu cầu, còn đọc slide nhiều.', 'Đủ nội dung cơ bản.', 'Tương tác ít.', 'Ổn.', 'Khoảng 70% HV đạt mục tiêu.', 'Xếp loại: Đạt.', 'Gửi GV mẫu giáo án có hoạt động nhóm.'],
        'Cần cải thiện' => ['Chưa kiểm soát lớp.', 'Thiếu phần luyện tập.', 'HV mất tập trung.', 'Vào lớp muộn.', 'Dưới 60% HV đạt mục tiêu.', 'Xếp loại: Cần cải thiện — dự giờ lại trong tháng.', 'Kèm cặp 1-1 với Trưởng Học thuật, dự giờ lại sau 2 tuần.'],
    ];

    /** Checklist: [lớp, tháng, người lưu, [6 mục theo ClassChecklist::ITEMS], ghi chú học phí]. */
    private const CHECKLISTS = [
        ['CG-FAM1', -1, 'academic_cg', ['yes', 'yes', 'yes', 'no', 'na', 'na'], 'Đã thu đủ 12/12 HV.'],
        ['BD-FAM1', -1, 'academic_bd', ['yes', 'yes', 'no', 'yes', 'yes', 'yes'], '2 HV chưa đóng, PH hẹn cuối tháng.'],
        ['CG-FAM1', 0, 'academic_cg', ['no', 'na', 'na', 'yes', 'no', 'na'], null],
        ['CG-FAM0', 0, 'academic_cg', ['yes', 'no', 'no', 'na', 'na', 'na'], 'Quên nhắc theo quy trình, PH chưa đóng đợt 2.'],
        ['BD-FAM1', 0, 'academic_bd', ['no', 'na', 'na', 'no', 'na', 'na'], null],
        ['BD-FAM0', 0, 'academic_bd', ['yes', 'yes', 'yes', null, null, null], 'Thu đủ.'],
    ];

    /** Họp GV: [số tuần trước, GV, lớp | null, trạng thái, ghi chú tình hình lớp, đề xuất]. */
    private const MEETINGS = [
        [3, 'teacher_cg', 'CG-FAM1', 'handled', 'Lớp đều, 2 HV phát âm yếu.', 'Kèm 15 phút cuối giờ với TA.'],
        [2, 'native_cg', 'CG-FAM1', 'sketchy', 'OK.', 'GV báo cáo 1 dòng, yêu cầu bổ sung theo mẫu.'],
        [2, 'teacher_cg', 'CG-FAM1', 'duplicate', 'Lớp đều, 2 HV phát âm yếu.', 'Trùng nội dung báo cáo tuần trước.'],
        [1, 'teacher_bd', 'BD-FAM1', 'in_progress', '3 HV chưa làm bài tập về nhà 2 tuần liền.', 'Học vụ gọi PH, GV gửi bài bổ trợ.'],
        [1, 'teacher_cg', 'CG-FAM0', 'handled', 'Lớp trật tự hơn sau khi đặt luật lớp.', 'Duy trì sticker thưởng.'],
        [1, 'teacher_ft_cg', null, 'not_reported', 'GV chưa gửi báo cáo tuần, chưa tham gia họp.', 'Nhắc GV nộp trước thứ Hai.'],
        [1, 'teacher_dd', 'DD-GT1', 'handled', 'Lớp sôi nổi, tiến độ tốt.', 'Không.'],
    ];

    /** @var array<string, User> */
    private array $staff = [];

    /** @var array<string, ClassModel> */
    private array $classes = [];

    /** @var list<array{0: Carbon, 1: int, 2: Closure}> */
    private array $events = [];

    private Carbon $realNow;

    private Carbon $lastMonth;

    private Carbon $thisMonth;

    public function run(): void
    {
        $missing = collect(self::STAFF)->reject(fn (string $email) => User::where('email', $email)->exists())
            ->merge(collect(self::CLASSES)->reject(fn (string $code) => ClassModel::where('code', $code)->exists()));
        if ($missing->isNotEmpty()) {
            $this->command?->warn('DemoCoverageQualitySeeder: thiếu tài khoản / lớp demo ('.$missing->implode(', ').') — bỏ qua.');

            return;
        }
        if (StaffReport::where('title', self::MARKER)->exists()) {
            $this->command?->info('DemoCoverageQualitySeeder: đã có dữ liệu demo báo cáo & chất lượng — bỏ qua.');

            return;
        }

        $this->staff = collect(self::STAFF + self::OPTIONAL_STAFF)->map(fn (string $email) => User::where('email', $email)->first())->filter()->all();
        $this->classes = collect(self::CLASSES + self::OPTIONAL_CLASSES)->map(fn (string $code) => ClassModel::where('code', $code)->first())->filter()->all();
        if (isset($this->classes['DD-GT1']) && $this->classes['DD-GT1']->teacher) {
            $this->staff['teacher_dd'] = $this->classes['DD-GT1']->teacher;
        }

        $previousTestNow = Carbon::getTestNow();
        $originalRequest = app('request');
        $this->realNow = now()->copy();
        $this->thisMonth = $this->realNow->copy()->startOfMonth();
        $this->lastMonth = $this->thisMonth->copy()->subMonthNoOverflow();

        try {
            DB::transaction(function () {
                $this->planEvents();
                $this->runEvents();
            });
        } finally {
            Carbon::setTestNow($previousTestNow);
            app()->instance('request', $originalRequest);
            Auth::forgetUser();
        }

        $this->printSummary();
    }

    // ── Lịch sự kiện ────────────────────────────────────────────────────────────

    private function at(int $daysAgo, int $hour, int $minute = 0): Carbon
    {
        return $this->realNow->copy()->subDays($daysAgo)->setTime($hour, $minute);
    }

    /** Ngày $day của tháng (-1 L, 0 C), giờ $hour; ngày vượt cuối tháng → ngày cuối tháng. */
    private function monthDay(int $month, int $day, int $hour, int $minute = 0): Carbon
    {
        $start = $month < 0 ? $this->lastMonth : $this->thisMonth;

        return $start->copy()->setDay(min($day, $start->daysInMonth))->setTime($hour, $minute);
    }

    private function event(Carbon $at, Closure $callback): void
    {
        $this->events[] = [$at->copy(), count($this->events), $callback];
    }

    /** Chạy theo thời gian; mốc rơi vào sau "bây giờ − 30 phút" được kéo về mốc đó (giữ thứ tự khai báo). */
    private function runEvents(): void
    {
        $ceiling = $this->realNow->copy()->subMinutes(30);
        $events = array_map(fn (array $e) => [$e[0]->lt($ceiling) ? $e[0] : $ceiling->copy(), $e[1], $e[2]], $this->events);
        usort($events, fn (array $a, array $b) => [$a[0]->getTimestamp(), $a[1]] <=> [$b[0]->getTimestamp(), $b[1]]);
        foreach ($events as [$at, , $callback]) {
            Carbon::setTestNow($at);
            $callback();
        }
    }

    private function planEvents(): void
    {
        $this->planPeriodicReports();
        $this->planJournals();
        $this->planObservations();
        $this->planChecklistsAndMeetings();
        $this->planClassReport();
        $this->planTickets();
    }

    // ── Báo cáo định kỳ ─────────────────────────────────────────────────────────

    private function planPeriodicReports(): void
    {
        // Học vụ CG: báo cáo ngày của 7 ngày làm việc gần nhất (trừ Chủ nhật) — nộp 17:40 cùng ngày; ngày thứ 3 nộp trễ sáng
        // hôm sau (sau hạn 09:00), ngày thứ 5 bỏ trống. Học vụ BD: 3 ngày gần nhất đúng hạn, hôm qua chưa nộp.
        $workdays = collect(range(1, 9))->map(fn (int $d) => $this->at($d, 17, 40))->reject(fn (Carbon $day) => $day->isSunday())->take(7)->values();
        foreach ($workdays as $i => $day) {
            if ($i === 4) {
                continue;
            }
            $late = $i === 2;
            $this->event($late ? $day->copy()->addDay()->setTime(10, 20) : $day, fn () => $this->periodic('academic_cg', 'Báo cáo ngày '.$day->format('d/m'), $day,
                "- Lớp FAM 1 / FAM 0 CG: điểm danh đủ, 1 HV nghỉ có phép.\n- Gọi nhắc học phí 3 PH, 1 PH hẹn chuyển khoản.\n- Xếp học bù cho 2 HV."
                .($late ? "\n- (Nộp bù: tối qua mất mạng ở cơ sở.)" : '')));
        }
        foreach ([4, 3, 2] as $d) {
            $day = $this->at($d, 18, 5);
            if (! $day->isSunday()) {
                $this->event($day, fn () => $this->periodic('academic_bd', 'Báo cáo ngày '.$day->format('d/m'), $day,
                    "- FAM 1 / FAM 0 BD: lớp ổn định.\n- Tư vấn 2 PH học thử, 1 PH đăng ký.\n- Theo dõi sự vụ cầu thang."));
            }
        }

        // Học vụ CG: báo cáo tuần KPI 2 tuần trước + tuần trước (Học vụ BD chưa nộp).
        foreach ([2, 1] as $w) {
            $saturday = $this->realNow->copy()->startOfWeek()->subWeeks($w)->addDays(5)->setTime(16, 30);
            $this->event($saturday, fn () => $this->weeklyKpi('academic_cg', ReportPeriod::weekKey($saturday), $w));
        }

        // Học thuật: báo cáo tuần 3 tuần gần nhất (nộp thứ Bảy), tuần giữa nộp trễ sang thứ Ba tuần sau.
        foreach ([3, 2, 1] as $w) {
            $saturday = $this->realNow->copy()->startOfWeek()->subWeeks($w)->addDays(5)->setTime(17, 30);
            $submitAt = $w === 2 ? $saturday->copy()->addDays(3)->setTime(9, 0) : $saturday;
            $this->event($submitAt, fn () => $this->periodic('lead', 'Báo cáo tuần '.$saturday->copy()->startOfWeek()->format('d/m').' – '.$saturday->copy()->endOfWeek()->format('d/m'), $saturday,
                "- Dự giờ 2 lớp, 1 GV cần kèm thêm.\n- Duyệt giáo án tuần tới các lớp K26–K28.\n- Họp chuyên môn GV part-time."));
        }

        // Học thuật: báo cáo tháng L (mùng 1 tháng này) + báo cáo quý trước (3 ngày đầu quý này).
        $this->event($this->monthDay(0, 1, 16), fn () => $this->asUser($this->staff['lead'], StaffReportController::class, 'academicMonthlyStore', [
            'month' => $this->lastMonth->format('Y-m'),
            'narrative' => [
                'test_syllabus_review' => 'Rà soát xong bộ mini test Unit 1–6 Starters, sửa 4 câu đáp án sai.',
                'materials_transfers' => 'Đã chuẩn bị giáo trình Movers cho lớp FAM 2 khai giảng; 2 HV chuyển lớp đã có lộ trình bù.',
                'overall' => 'Chất lượng ổn định; GV FAM 0 CG cần kèm thêm về quản lý lớp nhỏ tuổi.',
                'teacher_notes' => null,
            ],
        ]));
        $quarterStart = $this->realNow->copy()->startOfQuarter();
        $previousQuarter = $quarterStart->copy()->subMonthNoOverflow();
        $this->event($quarterStart->copy()->addDays(2)->setTime(10, 0), fn () => $this->asUser($this->staff['lead'], StaffReportController::class, 'academicQuarterlyStore', [
            'quarter' => $previousQuarter->year.'-Q'.$previousQuarter->quarter,
            'narrative' => [
                'academic_work' => 'Hoàn thành khung chương trình IELTS Foundation, bộ test đầu vào Starters.',
                'teacher_staffing' => 'Tuyển thêm 2 GV part-time, 1 GV nghỉ việc.',
                'next_plan' => 'Pilot IELTS Foundation K28, chuẩn hóa rubric Speaking.',
                'program_progress' => 'Đúng tiến độ 90%.',
                'implementation_quality' => 'Khá; lớp nhỏ tuổi cần cải thiện quản lý lớp.',
                'improvement_priorities' => 'Đào tạo quản lý lớp, dự giờ chéo.',
            ],
        ]));

        // Báo cáo tháng L của GV / trợ giảng — hạn Chủ nhật cuối tháng (nhắc), KPI tính hạn 12:00 mùng 2 tháng sau.
        $due = MonthlyReportDue::dueDate($this->lastMonth);
        $this->event($due->copy()->subDays(2)->setTime(19, 0), fn () => $this->periodic('assistant_cg', 'Báo cáo tháng '.$this->lastMonth->format('m/Y'), $due->copy()->subDays(2),
            "- Trực 14 buổi FAM 1 / FAM 0 CG, nộp đủ báo cáo trực lớp.\n- Kèm 3 HV phát âm yếu.\n- Đề xuất thêm bộ thẻ từ vựng Unit 5."));
        $this->event($due->copy()->subDay()->setTime(20, 30), fn () => $this->teacherMonthly('teacher_cg', [
            'progress' => 'FAM 1 xong Unit 4, FAM 0 xong bài 3 — đúng tiến độ.',
            'difficulties' => 'Lớp FAM 0 nhỏ tuổi, khó giữ trật tự.',
            'proposals' => 'Xin thêm sticker thưởng và tranh màu.',
        ], ['CG-FAM1' => [false, 'Lớp đều, 2 HV phát âm yếu.'], 'CG-FAM0' => [false, 'Đã đặt luật lớp, tuần sau đánh giá lại.']]));
        // Reminder đúng ngày hạn: nhắc người chưa nộp (Trần Bảo Ngọc, ThS. Nguyễn Quốc Anh, Lê Hải Yến, ...).
        $this->event($due->copy()->setTime(7, 0), fn () => Artisan::call('reports:remind-monthly'));
        // Nộp trễ sang tháng này (sau hạn 12:00 mùng 2).
        $this->event($this->monthDay(0, 3, 10), fn () => $this->periodic('assistant_bd', 'Báo cáo tháng '.$this->lastMonth->format('m/Y'), $this->lastMonth->copy()->endOfMonth(),
            "- Trực FAM 1 / FAM 0 BD.\n- (Nộp muộn do quên hạn.)"));
        $this->event($this->monthDay(0, 4, 15), fn () => $this->teacherMonthly('teacher_bd', [
            'progress' => 'FAM 1 BD chậm 1 buổi do nghỉ lễ.',
            'difficulties' => '3 HV không làm bài tập về nhà.',
            'proposals' => null,
        ], ['BD-FAM1' => [true, '3 HV chưa làm BTVN 2 tuần liền.', 'Nhờ Học vụ gọi PH, hỗ trợ bài bổ trợ.']]));
        // ThS. Nguyễn Quốc Anh (gv.cohuu1) không nộp báo cáo tháng L — ca "thiếu báo cáo".
    }

    private function periodic(string $who, string $title, Carbon $reportDate, string $content): void
    {
        $this->asUser($this->staff[$who], StaffReportController::class, 'reportStore', [
            'title' => '# '.$title, 'content' => $content, 'report_date' => $reportDate->toDateString(),
        ]);
    }

    private function weeklyKpi(string $who, string $week, int $seed): void
    {
        $counts = KpiCriterion::forRole(Roles::ACADEMIC_STAFF)->active()->ordered()->get()
            ->mapWithKeys(fn (KpiCriterion $c, int $i) => [$c->id => ($i + $seed) % 4 === 0 ? 1 : 0])->all();
        $this->asUser($this->staff[$who], StaffReportController::class, 'weeklyKpiStore', [
            'week' => $week, 'counts' => $counts,
            'metrics' => ['classes_running' => 4, 'students' => 46 + $seed, 'teachers' => 3, 'new_leads' => 6 + $seed, 'new_closed' => 2,
                'transfers' => $seed - 1, 'makeups' => 3, 'care_due_classes' => 2, 'upcoming_test_classes' => 1],
        ]);
    }

    /** @param array<string, array{0: bool, 1: string, 2?: string}> $classes  lớp => [cần hỗ trợ, tình hình, ghi chú hỗ trợ] */
    private function teacherMonthly(string $who, array $general, array $classes): void
    {
        $rows = [];
        foreach ($classes as $key => $row) {
            $rows[$this->classes[$key]->id] = ['situation' => $row[1], 'attention' => $row[0] ? 'Cần theo dõi sát.' : null,
                'solution' => $row[0] ? 'Phối hợp Học vụ.' : 'Duy trì.', 'need_support' => $row[0], 'support_note' => $row[2] ?? null];
        }
        $this->asUser($this->staff[$who], StaffReportController::class, 'teacherMonthlyStore', [
            'month' => $this->lastMonth->format('Y-m'), 'general' => $general, 'classes' => $rows,
        ]);
    }

    // ── Nhật ký sự vụ ───────────────────────────────────────────────────────────

    private function planJournals(): void
    {
        foreach (self::JOURNALS as [$who, $class, $severity, $title, $content, $daysAgo, $followups, $status]) {
            $this->event($this->at($daysAgo, 19, 15), fn () => $this->asUser($this->staff[$who], StaffReportController::class, 'journalStore', [
                'title' => $title, 'content' => $content, 'severity' => $severity, 'report_date' => $this->at($daysAgo, 0)->toDateString(),
                'class_id' => $class ? $this->classes[$class]->id : null,
            ]));
            foreach ($followups as $i => [$by, $text, $ago]) {
                $this->event($this->at($ago, 21, 10 * $i), fn () => $this->asUser($this->staff[$by], StaffReportController::class, 'journalFollowup',
                    ['content' => $text], ['id' => $this->journalId($title)]));
            }
            if ($status === 'resolved') {
                $by = end($followups)[0];
                $this->event($this->at(end($followups)[2], 21, 30), fn () => $this->asUser($this->staff[$by], StaffReportController::class, 'journalStatus',
                    ['status' => 'resolved'], ['id' => $this->journalId($title)]));
            }
        }
    }

    private function journalId(string $title): int
    {
        return (int) StaffReport::where('type', 'journal')->where('title', $title)->latest('id')->value('id');
    }

    // ── Dự giờ ──────────────────────────────────────────────────────────────────

    /** Buổi học đã diễn ra (trước hôm nay) của lớp, mới nhất trước; giới hạn trong khoảng ngày nếu có. */
    private function pastSessions(string $classKey, ?Carbon $from = null, ?Carbon $to = null)
    {
        $before = $this->realNow->copy()->startOfDay();
        $to = $to && $to->lt($before) ? $to : $before->copy()->subDay();

        return ClassSession::where('class_id', $this->classes[$classKey]->id)
            ->where('status', '!=', 'cancelled')->where('type', '!=', ClassSession::TYPE_SUPPORT)
            ->whereDate('date', '<=', $to->toDateString())
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from->toDateString()))
            ->orderByDesc('date')->orderByDesc('start_time')->get();
    }

    private function sessionEnd(ClassSession $session): Carbon
    {
        $end = $session->end_time instanceof \DateTimeInterface ? $session->end_time->format('H:i:s') : (string) $session->end_time;

        return $session->date->copy()->setTimeFromTimeString($end)->addMinutes(20);
    }

    private function planObservations(): void
    {
        $used = [];
        foreach (self::QA as [$class, $role, $observer, $nth, $rating, $notes]) {
            if (! isset($this->classes[$class], $this->staff[$observer])) {
                continue;
            }
            $sessions = $this->pastSessions($class, $this->realNow->copy()->subDays(30));
            $session = $sessions->get(min($nth, $sessions->count()) - 1);
            $teacherId = $role === 'foreign' ? $this->classes[$class]->foreign_teacher_id : $this->classes[$class]->teacher_id;
            // Lớp mới khai giảng ít buổi: không dự giờ 2 lần cùng một buổi của cùng GV.
            if (! $session || ! $teacherId || isset($used[$session->id.'-'.$teacherId])) {
                continue;
            }
            $used[$session->id.'-'.$teacherId] = true;
            $this->event($this->sessionEnd($session), fn () => $this->asUser($this->staff[$observer], ObservationController::class, 'storeOperations', [
                'observed_on' => $session->date->toDateString(), 'teacher_id' => $teacherId, 'class_id' => $this->classes[$class]->id, 'rating' => $rating,
            ] + array_combine(array_keys(QaObservation::NOTE_FIELDS), $notes)));
        }

        foreach (self::ACADEMIC as [$class, $month, $observed, $attendance, $pass, $level]) {
            if (! isset($this->classes[$class])) {
                continue;
            }
            [$from, $to] = ReportPeriod::monthRange(($month < 0 ? $this->lastMonth : $this->thisMonth)->format('Y-m'));
            if (! ClassModel::whereKey($this->classes[$class]->id)->runningBetween($from, $to)->exists()) {
                continue;
            }
            $session = $observed ? $this->pastSessions($class, $from, $to)->first() : null;
            if ($observed && ! $session) {
                continue;
            }
            $at = $session ? $this->sessionEnd($session)->addHour() : $to->copy()->setTime(17, 0);
            $notes = $level ? array_combine([...array_keys(AcademicObservation::CRITERIA), 'action_notes'], self::ACADEMIC_NOTES[$level]) : [];
            $this->event($at, fn () => $this->asUser($this->staff['lead'], ObservationController::class, 'saveAcademic', [
                'class_id' => $this->classes[$class]->id, 'month' => $from->format('Y-m'), 'attendance_rate' => $attendance, 'pass_rate' => $pass,
                'observed' => $observed ? 1 : 0, 'observed_on' => $session?->date->toDateString(), 'observer_id' => $observed ? $this->staff['lead']->id : null,
            ] + $notes));
        }
    }

    // ── Checklist + họp GV ──────────────────────────────────────────────────────

    private function planChecklistsAndMeetings(): void
    {
        foreach (self::CHECKLISTS as [$class, $month, $who, $answers, $note]) {
            [$from, $to] = ReportPeriod::monthRange(($month < 0 ? $this->lastMonth : $this->thisMonth)->format('Y-m'));
            if (! ClassModel::whereKey($this->classes[$class]->id)->runningBetween($from, $to)->exists()) {
                continue;
            }
            $at = $month < 0 ? $to->copy()->setTime(16, 0) : $this->at(1, 16, 30);
            $this->event($at, fn () => $this->asUser($this->staff[$who], ClassChecklistController::class, 'save', [
                'month' => $from->format('Y-m'),
                'rows' => [['class_id' => $this->classes[$class]->id, 'tuition_note' => $note] + array_combine(array_keys(ClassChecklist::ITEMS), $answers)],
            ]));
        }

        foreach (self::MEETINGS as [$weeksAgo, $teacher, $class, $status, $classNote, $recommendation]) {
            if (! isset($this->staff[$teacher]) || ($class && ! isset($this->classes[$class]))) {
                continue;
            }
            $weekStart = $this->realNow->copy()->startOfWeek()->subWeeks($weeksAgo);
            $this->event($weekStart->copy()->addDays(4)->setTime(17, $weeksAgo * 5), fn () => $this->asUser($this->staff['lead'], TeacherMeetingReportController::class, 'store', [
                'week_start' => $weekStart->toDateString(), 'teacher_id' => $this->staff[$teacher]->id, 'class_id' => $class ? $this->classes[$class]->id : null,
                'syllabus_note' => $status === 'not_reported' ? null : 'Đúng tiến độ syllabus.',
                'scores_note' => $status === 'not_reported' ? null : 'Mini test trung bình 7.5/10.',
                'class_note' => $classNote,
                'academic_order' => $status === 'in_progress' ? 'Order bộ bài tập bổ trợ Unit 3.' : null,
                'recommendation' => $recommendation, 'status' => $status,
            ]));
        }
    }

    // ── Báo cáo trực lớp bị trả về ──────────────────────────────────────────────

    private function planClassReport(): void
    {
        // Buổi FAM 1 CG gần đây mà trợ giảng không có đầu việc "Trực lớp" đang mở (controller sẽ tự gắn và đổi trạng thái việc đó).
        $ta = $this->staff['assistant_cg'];
        $class = $this->classes['CG-FAM1'];
        $session = $this->pastSessions('CG-FAM1', $this->realNow->copy()->subDays(20))->slice(1)->first(fn (ClassSession $s) => ! WorkTask::where('assignee_id', $ta->id)
            ->where('class_id', $class->id)->whereDate('due_date', $s->date->toDateString())
            ->whereIn('status', ['new', 'in_progress', 'overdue', 'blocked'])->exists()
            && ! ClassReport::where('class_session_id', $s->id)->exists());
        if (! $session) {
            return;
        }
        $this->event($this->sessionEnd($session), fn () => $this->asUser($ta, WorkTaskController::class, 'storeClassReport', [
            'class_id' => $class->id, 'class_session_id' => $session->id,
            'hom_nay_hoc_gi' => 'Ôn tập', 'nhat_ky_day' => null,
        ]));
        $this->event($session->date->copy()->addDay()->setTime(9, 15), fn () => $this->asUser($this->staff['admin'], WorkTaskController::class, 'rejectClassReport',
            ['reason' => 'Báo cáo quá sơ sài: ghi rõ nội dung đã học (Unit, từ vựng, mẫu câu), nhật ký lớp và đính kèm ảnh bảng.'],
            ['id' => (int) ClassReport::where('class_session_id', $session->id)->where('reporter_id', $ta->id)->latest('id')->value('id')]));
    }

    // ── Ticket ─────────────────────────────────────────────────────────────────

    private function planTickets(): void
    {
        $handler = $this->staff['manager_bd'];

        // Đã đóng: GV BD xin file nghe → Quản lý BD gửi → giải quyết → đóng.
        $closed = '# Xin bổ sung file nghe Unit 6 Movers';
        $this->event($this->at(12, 10), fn () => $this->asUser($this->staff['teacher_bd'], SupportTicketController::class, 'store', [
            'title' => $closed, 'category' => 'curriculum', 'priority' => 'medium',
            'description' => 'Bộ audio Movers trên drive thiếu track 6.2 và 6.3, cần trước buổi thứ Sáu.',
        ]));
        $this->event($this->at(12, 11), fn () => $this->asUser($this->staff['admin'], SupportTicketController::class, 'assign', ['assignee_id' => $handler->id], ['id' => $this->ticketId($closed)]));
        $this->event($this->at(11, 9), fn () => $this->asUser($handler, SupportTicketController::class, 'storeMessage', ['message' => 'Đã upload đủ track 6.1–6.5 lên thư mục Movers, cô kiểm tra giúp.'], ['id' => $this->ticketId($closed)]));
        $this->event($this->at(11, 9, 5), fn () => $this->asUser($handler, SupportTicketController::class, 'updateStatus', ['status' => 'resolved'], ['id' => $this->ticketId($closed)]));
        $this->event($this->at(9, 8), fn () => $this->asUser($handler, SupportTicketController::class, 'updateStatus', ['status' => 'closed'], ['id' => $this->ticketId($closed)]));

        // Mở lại: học viên báo lỗi bài tập → đã giải quyết → học viên mở lại vì vẫn lỗi.
        $reopened = '# Không mở được bài tập về nhà trên điện thoại';
        $this->event($this->at(6, 20), fn () => $this->asUser($this->staff['student_bd'], SupportTicketController::class, 'store', [
            'title' => $reopened, 'category' => 'technical_issue', 'priority' => 'high',
            'description' => 'Bấm vào bài tập Unit 5 thì màn hình trắng, máy Android.',
        ]));
        $this->event($this->at(5, 8, 30), fn () => $this->asUser($this->staff['admin'], SupportTicketController::class, 'assign', ['assignee_id' => $handler->id], ['id' => $this->ticketId($reopened)]));
        $this->event($this->at(5, 9), fn () => $this->asUser($handler, SupportTicketController::class, 'storeMessage', ['message' => 'Bạn xóa cache trình duyệt rồi đăng nhập lại giúp trung tâm nhé.'], ['id' => $this->ticketId($reopened)]));
        $this->event($this->at(5, 9, 5), fn () => $this->asUser($handler, SupportTicketController::class, 'updateStatus', ['status' => 'resolved'], ['id' => $this->ticketId($reopened)]));
        $this->event($this->at(2, 19, 30), fn () => $this->asUser($this->staff['student_bd'], SupportTicketController::class, 'reopen', [], ['id' => $this->ticketId($reopened)]));
        $this->event($this->at(2, 19, 32), fn () => $this->asUser($this->staff['student_bd'], SupportTicketController::class, 'storeMessage', ['message' => 'Em đã xóa cache nhưng vẫn trắng màn hình ở bài Unit 5.'], ['id' => $this->ticketId($reopened)]));

        // Khẩn cấp quá hạn: Học vụ báo không lưu được điểm danh, 3 ngày chưa ai nhận / phản hồi.
        $this->event($this->at(3, 18, 40), fn () => $this->asUser($this->staff['academic_cg'], SupportTicketController::class, 'store', [
            'title' => '# KHẨN: Không lưu được điểm danh ca tối', 'category' => 'technical_issue', 'priority' => 'urgent',
            'description' => 'Bấm Lưu điểm danh lớp FAM 1 CG báo lỗi, đã thử 3 lần. Cần xử lý trước khi chốt công GV.',
        ]));

        // Ưu tiên thấp, mới tạo.
        $this->event($this->at(1, 15), fn () => $this->asUser($this->staff['sales_cg'], SupportTicketController::class, 'store', [
            'title' => '# Đề xuất thêm mẫu tin nhắn nhắc lịch học thử', 'category' => 'other', 'priority' => 'low',
            'description' => 'Mong có mẫu Zalo nhắc PH trước buổi học thử 1 ngày.',
        ]));
    }

    private function ticketId(string $title): int
    {
        return (int) SupportTicket::where('title', $title)->latest('id')->value('id');
    }

    private function printSummary(): void
    {
        $countBy = fn ($query, string $column) => $query->selectRaw("{$column} as k, count(*) as c")->groupBy($column)->pluck('c', 'k')
            ->map(fn ($c, $k) => "{$k}: {$c}")->implode(', ');

        $this->command?->table(['Báo cáo & chất lượng', 'Số liệu'], [
            ['staff_reports theo loại', $countBy(StaffReport::query(), 'type')],
            ['nhật ký theo trạng thái', $countBy(StaffReport::where('type', 'journal'), 'status')],
            ['follow-up sự vụ', (string) StaffReportFollowup::count()],
            ['nhắc báo cáo tháng', (string) AdminNotification::where('type', 'monthly_report_due')->count()],
            ['dự giờ vận hành', $countBy(QaObservation::query(), 'rating')],
            ['dự giờ học thuật (đã dự / chưa)', $countBy(AcademicObservation::query(), 'observed')],
            ['checklist lớp', (string) ClassChecklist::count()],
            ['họp GV', $countBy(TeacherMeetingReport::query(), 'status')],
            ['báo cáo trực lớp', $countBy(ClassReport::query(), 'status')],
            ['ticket', $countBy(SupportTicket::query(), 'status')],
        ]);
    }
}
