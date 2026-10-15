<?php

namespace App\Services\Reports;

use App\Models\AcademicObservation;
use App\Models\AcademicProjectMilestone;
use App\Models\BigTestOrder;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\ClassReportStudentSupport;
use App\Models\ClassSession;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\MaterialOrder;
use App\Models\MiniTestScore;
use App\Models\PlacementTestSubmission;
use App\Models\QaObservation;
use App\Models\StaffAttendance;
use App\Models\StaffReport;
use App\Models\StudentAttendance;
use App\Models\SupportSession;
use App\Models\SupportTicket;
use App\Models\SyllabusAdjustmentRequest;
use App\Models\SyllabusChangeProposal;
use App\Models\TeacherTimesheet;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Số liệu tự động của báo cáo ngày (chủ dự án 15/10/2026: "mọi ô lấy từ dữ liệu hệ thống, chỉ ghi chú nhập tay" —
 * đề xuất /mnt/project-files/bao-cao-ngay-mapping/de-xuat-mapping.md).
 *
 * Phạm vi: dữ liệu người viết được xem (ClassModel / Student / CrmCustomer / MaterialOrder … ::visibleTo). Mỗi khối trả về
 * các mục chỉ đọc: `value` (số hiển thị), `info` (cách đếm, hiện ở icon "i"), `details` (danh sách chi tiết, mỗi dòng có thể
 * kèm nhãn `tag` / mức `tone` / link `url`). `fields` giữ các số chính theo khóa cũ (classes_planned, teachers, fees …) để
 * Tổng quan Học thuật và báo cáo đã nộp trước đây vẫn đọc được.
 */
class DailyReportMetrics
{
    public const AUDIENCE_ACADEMIC = 'Học thuật';

    /** Trạng thái phiếu thu tính là "đã thu trong ngày" (không tính nháp / trả về / hủy). */
    private const RECEIPT_STATUSES = [TuitionReceipt::STATUS_PENDING, TuitionReceipt::STATUS_APPROVED];

    /** Ô ghi chú nhập tay của từng khối. */
    public const NOTE_LABELS = [
        'operations' => 'Ghi chú vận hành lớp học',
        'enrollment' => 'Ghi chú tuyển sinh / học viên',
        'media' => 'Ghi chú truyền thông',
        'projects' => 'Ghi chú dự án',
        'materials' => 'Ghi chú học liệu & đề test',
        'syllabus' => 'Ghi chú giáo trình',
        'quality' => 'Ghi chú chất lượng giảng dạy',
    ];

    private Carbon $date;

    public function __construct(private readonly User $user, string $date)
    {
        $this->date = Carbon::parse($date)->startOfDay();
    }

    /** @return array{audience: string, sections: array<string, array>, fields: array<string, mixed>, computed_at: string} */
    public static function compute(User $user, string $date, string $audience): array
    {
        $metrics = new self($user, $date);
        [$sections, $fields] = $audience === self::AUDIENCE_ACADEMIC ? $metrics->academic() : $metrics->operations();

        return ['audience' => $audience, 'sections' => $sections, 'fields' => $fields, 'computed_at' => now()->toIso8601String()];
    }

    /** Bản chữ của số liệu (cột `content`, để màn Tổng hợp / thông báo đọc được như báo cáo thường). */
    public static function text(array $snapshot, array $notes = []): string
    {
        return collect($snapshot['sections'] ?? [])->map(function (array $section, string $key) use ($notes) {
            $lines = collect($section['items'])->map(function (array $item) {
                $line = '- '.$item['label'].($item['value'] !== null ? ': '.$item['value'] : '');
                $details = collect($item['details'] ?? [])->take(15)
                    ->map(fn (array $d) => '    · '.(isset($d['tag']) ? "[{$d['tag']}] " : '').$d['text']);

                return $details->isEmpty() ? $line : $line."\n".$details->implode("\n");
            });
            if (filled($notes[$key] ?? null)) {
                $lines->push('- Ghi chú: '.$notes[$key]);
            }

            return $section['title'].":\n".$lines->implode("\n");
        })->values()->implode("\n\n");
    }

    // ───────────────────────── HỌC VỤ ─────────────────────────

    private function operations(): array
    {
        $sessions = ClassSession::with([
            'classModel' => fn ($q) => $q->withTrashed()->select('id', 'name', 'code', 'teacher_id'),
            'teacher:id,name', 'foreignTeacher:id,name', 'assistant:id,name', 'holiday:id,name',
        ])
            ->whereDate('date', $this->date)
            ->where('type', '!=', ClassSession::TYPE_SUPPORT)
            ->whereIn('class_id', $this->classIds())
            ->orderBy('start_time')
            ->get();
        $running = $sessions->where('status', '!=', 'cancelled');
        $done = $running->where('status', 'completed');

        $teacherIds = $running->flatMap(fn (ClassSession $s) => [$s->teacher_id, $s->foreign_teacher_id])->filter()->unique();
        $assistantIds = $running->pluck('assistant_id')->filter()->unique();

        $support = SupportSession::with(['student:id,name', 'classModel:id,name', 'teacher:id,name'])
            ->whereDate('session_date', $this->date)
            ->where('status', '!=', 'cancelled')
            ->whereIn('class_id', $this->classIds())
            ->orderBy('start_time')
            ->get();
        $supportDone = $support->where('status', 'completed')->count();

        $leads = CrmCustomer::query()->visibleTo($this->user)->whereDate('created_at', $this->date)->get(['id', 'name', 'source']);
        $contacted = $leads->isEmpty() ? 0 : CrmCustomerHistory::whereIn('customer_id', $leads->pluck('id'))
            ->whereIn('type', CrmCustomerHistory::CONTACT_TYPES)->distinct()->count('customer_id');

        $tests = PlacementTestSubmission::with('customer:id,name')
            ->whereDate('created_at', $this->date)
            ->where(fn (Builder $q) => $q->whereHas('customer', fn (Builder $c) => $c->visibleTo($this->user))
                ->orWhereHas('student', fn (Builder $s) => $s->visibleTo($this->user)))
            ->orderBy('created_at')
            ->get();
        $booked = CrmCustomer::query()->visibleTo($this->user)
            ->whereBetween('appointment_at', [$this->date, $this->date->copy()->endOfDay()])->count();

        $receipts = TuitionReceipt::with('student:id,name,code')
            ->whereDate('payment_date', $this->date)
            ->whereIn('status', self::RECEIPT_STATUSES)
            ->whereHas('student', fn (Builder $s) => $s->visibleTo($this->user))
            ->orderBy('id')
            ->get();

        $sections = [
            'operations' => $this->section('Vận hành lớp học', 'school', [
                $this->item('classes_planned', 'Số lớp kế hoạch', $running->pluck('class_id')->unique()->count(),
                    'Số lớp có buổi học trong ngày theo lịch (gồm buổi học bù), không tính buổi đã hủy / nghỉ.'),
                $this->item('classes_actual', 'Số lớp thực tế', $done->pluck('class_id')->unique()->count(),
                    'Số lớp có buổi đã được giáo viên điểm danh trong ngày.'),
                $this->item('classes_list', 'Danh sách lớp trong ngày', null,
                    'Buổi học theo lịch: đã dạy (đã điểm danh), chưa điểm danh, nghỉ / hủy, học bù.', $this->sessionDetails($sessions)),
                $this->item('teachers', 'Số giáo viên', $teacherIds->count(),
                    'Số giáo viên (gồm GVNN) đứng lớp trong ngày theo lịch buổi học.', $this->teacherDetails($running)),
                $this->item('assistants', 'Số trợ giảng', $assistantIds->count(),
                    'Số trợ giảng trực lớp trong ngày theo lịch buổi học.', $this->assistantDetails($running, $assistantIds)),
                $this->item('class_situation', 'Tình hình lớp học', null,
                    'Học sinh vắng / đi muộn (theo điểm danh), sự vụ trong Nhật ký Học vụ và học sinh mới vào danh sách bổ trợ trong ngày.',
                    $this->situationDetails($running)),
                $this->item('facilities', 'Cơ sở vật chất', null,
                    'Ticket hỗ trợ loại "Cơ sở vật chất" đang mở trong ngày, order đạo cụ / in ấn cần dùng hôm nay hoặc đang quá hạn.',
                    $this->facilityDetails()),
            ]),
            'enrollment' => $this->section('Tuyển sinh / Học viên', 'group_add', [
                $this->item('support_students', 'Số học sinh bổ trợ', $support->isEmpty() ? '0' : "{$supportDone}/{$support->count()}",
                    'Buổi bổ trợ trong ngày: đã học / đã xếp (không tính buổi hủy).',
                    $support->map(fn (SupportSession $s) => [
                        'text' => trim(($s->student?->name ?? 'Học sinh').' · '.($s->classModel?->name ?? '').($s->teacher ? ' · GV '.$s->teacher->name : ''), ' ·'),
                        'tag' => $s->status_label,
                        'tone' => $s->status === 'completed' ? 'success' : null,
                    ])->values()->all()),
                $this->item('new_data', 'Data mới nhận', $leads->count(),
                    'Khách mới tạo trong CRM trong ngày. "Đã liên hệ" là khách đã có lần gọi / nhắn / gặp.',
                    $leads->isEmpty() ? [] : [
                        ...$leads->groupBy(fn ($c) => $c->source ?: 'Chưa rõ nguồn')->map(fn ($group, $source) => ['text' => "{$source}: {$group->count()}"])->values()->all(),
                        ['text' => "Đã liên hệ lần đầu: {$contacted}/{$leads->count()}", 'tone' => $contacted < $leads->count() ? 'warning' : 'success'],
                    ]),
                $this->item('test_students', 'Số học sinh test', "{$tests->count()} / {$booked} hẹn",
                    'Bài test đầu vào làm trong ngày trên số khách có lịch hẹn test trong ngày.',
                    $tests->map(fn (PlacementTestSubmission $t) => [
                        'text' => ($t->customer?->name ?? $t->candidate_name ?? 'Thí sinh')
                            .($t->overall_score !== null ? ' · '.$t->overall_score : '').($t->cefr_level ? ' · '.$t->cefr_level : ''),
                        'tag' => $t->status === PlacementTestSubmission::STATUS_GRADED ? 'Đã chấm' : 'Chờ chấm',
                        'url' => $t->customer_id ? $this->link('crm.customers.show', $t->customer_id) : null,
                    ])->values()->all(),
                    $tests->count()),
                $this->item('fees', 'Số lượng thu phí', $receipts->count(),
                    'Phiếu thu có ngày thu là ngày báo cáo, đang chờ duyệt hoặc đã duyệt (không tính nháp, trả về, đã hủy).',
                    $receipts->isEmpty() ? [] : [
                        ...$receipts->map(fn (TuitionReceipt $r) => [
                            'text' => ($r->student?->name ?? 'Học sinh').' · '.self::money($r->amount).' · '.(TuitionReceipt::METHOD_LABELS[$r->payment_method] ?? $r->payment_method),
                            'tag' => $r->status === TuitionReceipt::STATUS_APPROVED ? 'Đã duyệt' : 'Chờ duyệt',
                            'tone' => $r->status === TuitionReceipt::STATUS_APPROVED ? 'success' : null,
                        ])->values()->all(),
                        ['text' => 'Tổng tiền: '.self::money($receipts->sum('amount')), 'tone' => 'strong'],
                    ]),
            ]),
            'media' => $this->section('Truyền thông', 'campaign', [], 'Hệ thống chưa ghi nhận bài đăng truyền thông. Ghi bài đăng (kèm link) vào ô ghi chú.'),
        ];

        $fields = collect($sections)->flatMap(fn ($s) => collect($s['items'])->mapWithKeys(fn ($i) => [$i['key'] => $i['count']]))
            ->only(['classes_planned', 'classes_actual', 'teachers', 'assistants', 'new_data', 'test_students', 'fees'])
            ->put('support_students', $support->isEmpty() ? null : "{$supportDone}/{$support->count()}")
            ->all();

        return [$sections, $fields];
    }

    /** @param Collection<int, ClassSession> $sessions */
    private function sessionDetails(Collection $sessions): array
    {
        return $sessions->map(function (ClassSession $s) {
            [$tag, $tone] = match (true) {
                $s->status === 'cancelled' => [$s->holiday ? 'Nghỉ lễ' : 'Nghỉ / hủy', 'muted'],
                $s->status === 'completed' => ['Đã dạy', 'success'],
                default => ['Chưa điểm danh', 'warning'],
            };

            return [
                'text' => $this->className($s).' · '.$this->sessionTime($s).($s->room ? ' · '.(Str::startsWith(Str::lower($s->room), 'phòng') ? $s->room : 'Phòng '.$s->room) : '')
                    .($s->type === ClassSession::TYPE_MAKEUP ? ' · Học bù' : ''),
                'tag' => $tag,
                'tone' => $tone,
                'url' => $this->link('classes.show', $s->class_id),
            ];
        })->values()->all();
    }

    /** GV đi muộn / về sớm (bảng giờ dạy), chưa check-in ca đã bắt đầu, dạy thay (GV buổi khác GV chính của lớp). */
    private function teacherDetails(Collection $running): array
    {
        $sheets = TeacherTimesheet::with('teacher:id,name')
            ->whereIn('class_session_id', $running->pluck('id'))
            ->get()
            ->groupBy('class_session_id');
        $details = [];
        foreach ($running as $s) {
            $label = $this->className($s).' · '.$this->sessionTime($s);
            foreach ($sheets->get($s->id, collect()) as $sheet) {
                if ($sheet->late_minutes > 0) {
                    $details[] = ['text' => ($sheet->teacher?->name ?? 'GV')." · {$label} · trễ {$sheet->late_minutes} phút", 'tag' => 'Đi muộn', 'tone' => 'warning'];
                }
                if ($sheet->early_leave_minutes > 0) {
                    $details[] = ['text' => ($sheet->teacher?->name ?? 'GV')." · {$label} · về sớm {$sheet->early_leave_minutes} phút", 'tag' => 'Về sớm', 'tone' => 'warning'];
                }
            }
            $started = $s->start_time && $this->date->copy()->setTimeFrom($s->start_time)->isPast();
            if ($s->teacher_id && $started && ! $sheets->get($s->id, collect())->contains(fn ($t) => (int) $t->user_id === (int) $s->teacher_id && $t->checkin_time)) {
                $details[] = ['text' => ($s->teacher?->name ?? 'GV')." · {$label}", 'tag' => 'Chưa check-in', 'tone' => 'warning'];
            }
            $mainTeacher = $s->classModel?->teacher_id;
            if ($s->teacher_id && $mainTeacher && (int) $mainTeacher !== (int) $s->teacher_id) {
                $details[] = ['text' => ($s->teacher?->name ?? 'GV')." dạy thay · {$label}", 'tag' => 'Dạy thay'];
            }
        }

        return $details;
    }

    /** Buổi có trợ giảng nhưng chưa có báo cáo trực lớp, báo cáo chờ xác nhận / bị trả về, TA đi muộn (chấm công). */
    private function assistantDetails(Collection $running, Collection $assistantIds): array
    {
        $withTa = $running->filter(fn (ClassSession $s) => $s->assistant_id);
        $reports = ClassReport::whereIn('class_id', $withTa->pluck('class_id')->unique())
            ->whereDate('session_date', $this->date)
            ->get(['id', 'class_id', 'class_session_id', 'reporter_id', 'status']);
        $details = [];
        foreach ($withTa as $s) {
            $report = $reports->first(fn ($r) => (int) $r->class_session_id === (int) $s->id)
                ?? $reports->first(fn ($r) => ! $r->class_session_id && (int) $r->class_id === (int) $s->class_id);
            $label = ($s->assistant?->name ?? 'TA').' · '.$this->className($s).' · '.$this->sessionTime($s);
            if (! $report) {
                $details[] = ['text' => $label, 'tag' => 'Chưa báo cáo trực lớp', 'tone' => 'warning'];
            } elseif ($report->status !== ClassReport::STATUS_APPROVED) {
                $details[] = ['text' => $label, 'tag' => 'Báo cáo '.mb_strtolower($report->status_label), 'tone' => $report->status === ClassReport::STATUS_REJECTED ? 'error' : null];
            }
        }
        StaffAttendance::with('user:id,name')->whereIn('user_id', $assistantIds)->whereDate('work_date', $this->date)
            ->where('late_minutes', '>', 0)->get()
            ->each(function (StaffAttendance $a) use (&$details) {
                $details[] = ['text' => ($a->user?->name ?? 'TA')." · trễ {$a->late_minutes} phút".($a->late_excused ? ' (có phép)' : ''), 'tag' => 'Đi muộn', 'tone' => 'warning'];
            });

        return $details;
    }

    private function situationDetails(Collection $running): array
    {
        $details = StudentAttendance::with('student:id,name')
            ->whereIn('class_session_id', $running->pluck('id'))
            ->whereIn('status', ['absent', 'excused', 'late'])
            ->get()
            ->groupBy('class_session_id')
            ->map(function (Collection $rows, $sessionId) use ($running) {
                $session = $running->firstWhere('id', (int) $sessionId);
                $parts = collect(['absent' => 'vắng', 'excused' => 'vắng có phép', 'late' => 'đi muộn'])
                    ->map(fn ($label, $status) => ($names = $rows->where('status', $status)->map(fn ($r) => $r->student?->name)->filter())->isEmpty()
                        ? null : $label.' '.$names->count().' ('.$names->implode(', ').')')
                    ->filter();

                return ['text' => ($session ? $this->className($session) : 'Lớp').': '.$parts->implode('; '), 'tag' => 'Điểm danh',
                    'url' => $session ? $this->link('classes.show', $session->class_id) : null];
            })->values()->all();

        StaffReport::with(['classModel:id,name', 'user:id,name'])
            ->where('type', 'journal')
            ->whereDate('report_date', $this->date)
            ->where(fn (Builder $q) => $q->whereIn('class_id', $this->classIds())
                ->orWhere(fn (Builder $own) => $own->whereNull('class_id')->where('user_id', $this->user->id)))
            ->orderBy('created_at')
            ->get()
            ->each(function (StaffReport $r) use (&$details) {
                $details[] = [
                    'text' => ($r->classModel ? $r->classModel->name.': ' : '').Str::limit(trim((string) ($r->content ?: $r->title)), 160).($r->user ? ' — '.$r->user->name : ''),
                    'tag' => $r->serious_confirmed_at ? 'Lỗi nghiêm trọng' : 'Nhật ký',
                    'tone' => $r->serious_confirmed_at ? 'error' : null,
                    'url' => $this->link('reports.journal'),
                ];
            });

        ClassReportStudentSupport::with(['student:id,name', 'classModel:id,name'])
            ->whereIn('class_id', $this->classIds())
            ->whereDate('created_at', $this->date)
            ->get()
            ->each(function (ClassReportStudentSupport $s) use (&$details) {
                $details[] = [
                    'text' => ($s->student?->name ?? 'Học sinh').' · '.($s->classModel?->name ?? '').($s->reason ? ' · '.Str::limit($s->reason, 80) : ''),
                    'tag' => 'Cần bổ trợ · '.$s->source_label,
                    'url' => $this->link('tasks.support-sessions'),
                ];
            });

        return $details;
    }

    private function facilityDetails(): array
    {
        $end = $this->date->copy()->endOfDay();
        $tickets = SupportTicket::where('category', 'facilities')
            ->where('created_at', '<=', $end)
            ->where(fn (Builder $q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', $this->date))
            ->whereNotIn('status', $this->date->isToday() ? ['resolved', 'closed'] : [])
            ->when(! DataScope::isAll($this->user, 'class'), fn (Builder $q) => $q->whereHas('creator', fn (Builder $u) => $u->whereIn('branch_id', $this->user->branchIds())))
            ->orderBy('created_at')
            ->get(['id', 'code', 'title', 'status', 'created_at']);
        $orders = MaterialOrder::query()->visibleTo($this->user)
            ->with('classRoom:id,name')
            ->whereIn('category', [MaterialOrder::CATEGORY_PROPS, MaterialOrder::CATEGORY_PRINTING])
            ->where(fn (Builder $q) => $q->whereDate('use_date', $this->date)
                ->orWhere(fn (Builder $o) => $o->whereIn('status', MaterialOrder::OPEN_STATUSES)->where('due_at', '<', $end)->where('created_at', '<=', $end)))
            ->orderBy('due_at')
            ->get();

        return [
            ...$tickets->map(fn (SupportTicket $t) => [
                'text' => "{$t->code} · {$t->title}".($t->created_at->isSameDay($this->date) ? ' (mới)' : ''),
                'tag' => 'Ticket',
                'tone' => in_array($t->status, ['resolved', 'closed'], true) ? 'success' : 'warning',
                'url' => $this->link('tickets.show', $t->id),
            ])->all(),
            ...$orders->map(fn (MaterialOrder $o) => [
                'text' => "{$o->code} · {$o->title}".($o->classRoom ? ' · '.$o->classRoom->name : ''),
                'tag' => (MaterialOrder::CATEGORIES[$o->category] ?? 'Order').' · '.(MaterialOrder::STATUSES[$o->status] ?? $o->status),
                'tone' => $o->status === MaterialOrder::STATUS_OVERDUE ? 'error' : ($o->status === MaterialOrder::STATUS_DONE ? 'success' : null),
                'url' => $this->link('material-orders.show', $o->id),
            ])->all(),
        ];
    }

    // ───────────────────────── HỌC THUẬT ─────────────────────────

    private function academic(): array
    {
        $end = $this->date->copy()->endOfDay();
        $milestones = AcademicProjectMilestone::with(['project:id,name,code', 'assignee:id,name'])
            ->whereHas('project', fn (Builder $p) => $p->visibleTo($this->user))
            ->where(fn (Builder $q) => $q->where(fn (Builder $open) => $open->where('status', '!=', 'done')->whereDate('due_date', '<=', $this->date))
                ->orWhereBetween('completed_at', [$this->date, $end]))
            ->orderBy('due_date')
            ->get();
        $dueToday = $milestones->filter(fn ($m) => $m->status !== 'done' && $m->due_date?->isSameDay($this->date));
        $overdue = $milestones->filter(fn ($m) => $m->status !== 'done' && $m->due_date?->lt($this->date));
        $doneToday = $milestones->filter(fn ($m) => $m->completed_at?->isSameDay($this->date));
        $milestoneRow = fn (AcademicProjectMilestone $m) => [
            'text' => ($m->project?->name ?? 'Dự án').' · '.$m->title.($m->assignee ? ' · '.$m->assignee->name : '').($m->due_date ? ' · hạn '.$m->due_date->format('d/m') : ''),
            'url' => $m->academic_project_id ? $this->link('academic-projects.show', $m->academic_project_id) : null,
        ];

        $orders = MaterialOrder::query()->visibleTo($this->user)->with('classRoom:id,name')
            ->where('category', MaterialOrder::CATEGORY_ACADEMIC)
            ->where(fn (Builder $q) => $q->whereDate('created_at', $this->date)
                ->orWhereBetween('processed_at', [$this->date, $end])
                ->orWhere(fn (Builder $o) => $o->whereIn('status', MaterialOrder::OPEN_STATUSES)->where('due_at', '<', $end)))
            ->where('created_at', '<=', $end)
            ->orderBy('due_at')
            ->get();
        $bigTests = BigTestOrder::query()->visibleTo($this->user)->with(['classModel:id,name', 'teacher:id,name'])
            ->where('status', 'pending')->where('created_at', '<=', $end)
            ->orderBy('due_date')->get();

        $proposals = SyllabusChangeProposal::query()->visibleTo($this->user)->with(['proposer:id,name', 'curriculum:id,name'])
            ->where('created_at', '<=', $end)
            ->where(fn (Builder $q) => $q->where('status', 'pending')->orWhereDate('created_at', $this->date))
            ->latest()->get();
        $adjustments = SyllabusAdjustmentRequest::with(['classModel:id,name', 'teacher:id,name'])
            ->whereIn('class_id', $this->classIds())
            ->where('created_at', '<=', $end)
            ->where(fn (Builder $q) => $q->where('status', 'pending')->orWhereDate('created_at', $this->date))
            ->latest()->get();

        $observations = QaObservation::with(['classModel:id,name', 'teacher:id,name'])
            ->whereIn('class_id', $this->classIds())->whereDate('observed_on', $this->date)->get();
        $classObservations = AcademicObservation::with('classModel:id,name')
            ->whereIn('class_id', $this->classIds())->whereDate('observed_on', $this->date)->get();
        $scores = MiniTestScore::with(['classModel:id,name', 'student:id,name'])
            ->whereIn('class_id', $this->classIds())->whereDate('test_date', $this->date)->get();
        $low = $scores->filter(fn (MiniTestScore $s) => $s->score !== null && (float) $s->score / max((float) ($s->max_score ?: 10), 1) * 10 < 7);

        $sections = [
            'projects' => $this->section('Dự án học thuật', 'rocket_launch', [
                $this->item('milestones_due', 'Mốc đến hạn hôm nay', $dueToday->count(), 'Mốc của dự án học thuật bạn được xem có hạn là ngày báo cáo, chưa hoàn thành.',
                    $dueToday->map($milestoneRow)->values()->all()),
                $this->item('milestones_overdue', 'Mốc quá hạn', $overdue->count(), 'Mốc đã qua hạn mà chưa hoàn thành.',
                    $overdue->map(fn ($m) => [...$milestoneRow($m), 'tone' => 'error'])->values()->all()),
                $this->item('milestones_done', 'Mốc hoàn thành trong ngày', $doneToday->count(), 'Mốc được đánh dấu hoàn thành trong ngày.',
                    $doneToday->map(fn ($m) => [...$milestoneRow($m), 'tone' => 'success'])->values()->all()),
            ]),
            'materials' => $this->section('Học liệu & đề test', 'inventory_2', [
                $this->item('academic_orders', 'Order học liệu', $orders->count(),
                    'Order "Học liệu học thuật" tạo hoặc xử lý trong ngày, và order còn mở đã quá hạn.',
                    $orders->map(fn (MaterialOrder $o) => [
                        'text' => "{$o->code} · {$o->title}".($o->classRoom ? ' · '.$o->classRoom->name : ''),
                        'tag' => MaterialOrder::STATUSES[$o->status] ?? $o->status,
                        'tone' => $o->status === MaterialOrder::STATUS_OVERDUE ? 'error' : ($o->status === MaterialOrder::STATUS_DONE ? 'success' : null),
                        'url' => $this->link('material-orders.show', $o->id),
                    ])->values()->all()),
                $this->item('big_test_orders', 'Order đề test chờ duyệt', $bigTests->count(), 'Order đề Mini / Big Test đang chờ duyệt.',
                    $bigTests->map(fn (BigTestOrder $o) => [
                        'text' => ($o->code ? $o->code.' · ' : '').($o->classModel?->name ?? '').' · '.(BigTestOrder::TYPE_LABELS[$o->test_type] ?? $o->test_type)
                            .($o->due_date ? ' · hạn '.$o->due_date->format('d/m') : ''),
                        'tone' => $o->due_date?->lte($this->date) ? 'warning' : null,
                    ])->values()->all()),
            ]),
            'syllabus' => $this->section('Giáo trình', 'menu_book', [
                $this->item('syllabus_proposals', 'Đề xuất sửa giáo trình', $proposals->where('status', 'pending')->count(),
                    'Đề xuất sửa giáo trình đang chờ duyệt (kèm đề xuất mới gửi trong ngày).',
                    $proposals->map(fn (SyllabusChangeProposal $p) => [
                        'text' => ($p->curriculum?->name ?? 'Giáo trình').' · '.Str::limit((string) $p->reason, 100).($p->proposer ? ' — '.$p->proposer->name : ''),
                        'tag' => SyllabusChangeProposal::STATUS_LABELS[$p->status] ?? $p->status,
                    ])->values()->all()),
                $this->item('syllabus_adjustments', 'Yêu cầu điều chỉnh syllabus', $adjustments->where('status', 'pending')->count(),
                    'Yêu cầu giãn / điều chỉnh syllabus của lớp đang chờ duyệt (kèm yêu cầu mới trong ngày).',
                    $adjustments->map(fn (SyllabusAdjustmentRequest $r) => [
                        'text' => ($r->classModel?->name ?? 'Lớp').' · '.Str::limit((string) $r->reason, 100).($r->teacher ? ' — '.$r->teacher->name : ''),
                        'tag' => SyllabusAdjustmentRequest::STATUS_LABELS[$r->status] ?? $r->status,
                    ])->values()->all()),
            ]),
            'quality' => $this->section('Chất lượng giảng dạy', 'fact_check', [
                $this->item('observations', 'Buổi dự giờ', $observations->count() + $classObservations->count(), 'Phiếu dự giờ có ngày dự là ngày báo cáo.', [
                    ...$observations->map(fn (QaObservation $o) => ['text' => ($o->classModel?->name ?? 'Lớp').($o->teacher ? ' · GV '.$o->teacher->name : '').($o->rating ? ' · '.$o->rating : '')])->all(),
                    ...$classObservations->map(fn (AcademicObservation $o) => ['text' => ($o->classModel?->name ?? 'Lớp').($o->overall ? ' · '.Str::limit((string) $o->overall, 80) : '')])->all(),
                ]),
                $this->item('mini_tests', 'Kết quả mini test', $scores->isEmpty() ? '0' : $scores->count().' bài · '.$low->count().' dưới 7',
                    'Điểm mini test có ngày test là ngày báo cáo. "Dưới 7" quy về thang 10.',
                    $low->map(fn (MiniTestScore $s) => [
                        'text' => ($s->student?->name ?? 'Học sinh').' · '.($s->classModel?->name ?? '').' · '.$s->name.' · '.rtrim(rtrim((string) $s->score, '0'), '.').'/'.rtrim(rtrim((string) ($s->max_score ?: 10), '0'), '.'),
                        'tone' => 'warning',
                    ])->values()->all(),
                    $scores->count()),
            ]),
        ];

        return [$sections, []];
    }

    // ───────────────────────── HỖ TRỢ ─────────────────────────

    /** @var list<int>|null */
    private ?array $classIds = null;

    /** Lớp người viết được xem (cùng phạm vi màn Lớp học). */
    private function classIds(): array
    {
        return $this->classIds ??= ClassModel::query()->visibleTo($this->user)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function section(string $title, string $icon, array $items, ?string $empty = null): array
    {
        return ['title' => $title, 'icon' => $icon, 'items' => $items, 'empty' => $empty];
    }

    /** `count` là số dùng cho `fields` (ô tổng hợp ở Tổng quan); mặc định là `value` khi `value` là số. */
    private function item(string $key, string $label, int|string|null $value, string $info, array $details = [], ?int $count = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value === null ? null : (string) $value,
            'count' => $count ?? (is_int($value) ? $value : null),
            'info' => $info,
            'details' => array_values(array_map(fn (array $d) => array_filter($d, fn ($v) => $v !== null), $details)),
        ];
    }

    private function className(ClassSession $s): string
    {
        return $s->classModel?->name ?? 'Lớp #'.$s->class_id;
    }

    private function sessionTime(ClassSession $s): string
    {
        return $s->start_time && $s->end_time ? $s->start_time->format('H:i').'–'.$s->end_time->format('H:i') : ($s->shift_name ?: 'Ca học');
    }

    private function link(string $name, mixed $params = []): ?string
    {
        return Route::has($name) ? route($name, $params) : null;
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 0, ',', '.').'đ';
    }
}
