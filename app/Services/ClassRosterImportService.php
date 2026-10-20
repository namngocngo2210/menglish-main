<?php

namespace App\Services;

use App\Imports\RawRowsImport;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassScheduleConfig;
use App\Models\ClassSession;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Student;
use App\Models\User;
use App\Services\Concerns\ReadsImportRows;
use App\Services\TeachingShifts\TeachingShiftRules;
use App\Support\DataScope;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Nhập danh sách lớp học viên từ hệ thống cũ (Excel):
 * - sheet Lớp (tùy chọn): tạo lớp mới kèm giáo viên, phòng, lịch 2 buổi/tuần và sinh buổi học theo lịch;
 * - sheet Học viên: tạo hồ sơ học viên (giữ mã cũ), xếp vào lớp (lớp trong sheet Lớp hoặc lớp đã có).
 * Nhận cả file đã chuẩn hóa (tiêu đề như TEMPLATE_*) lẫn file xuất gốc của hệ thống cũ (cột "Lớp" ghi kèm lịch,
 * "Điện thoại PH" ghi nhiều số "a / b"). Chạy lại không nhân đôi: lớp khớp theo mã, học viên theo mã học viên.
 *
 * 1. parse(): đọc + kiểm tra → bản xem trước (lỗi chặn dòng, cảnh báo chỉ để biết).
 * 2. import(): ghi các dòng hợp lệ, mỗi lớp / mỗi học viên một transaction.
 */
class ClassRosterImportService
{
    use ReadsImportRows;

    public const MAX_ROWS = 3000;

    public const DEFAULT_CAPACITY = 15;

    /** Loại phòng gán cho phòng học tạo tự động khi nhập (giống migration chuyển phòng cũ sang bảng rooms). */
    public const DEFAULT_ROOM_TYPE = 'Phòng tiêu chuẩn';

    /** @var array<string, Room> phòng đã tìm / tạo trong lượt nhập, theo "chi nhánh|tên đã bỏ dấu" */
    private array $roomCache = [];

    public const CLASS_HEADINGS = [
        'branch' => 'Cơ sở',
        'class_code' => 'Mã lớp',
        'class_name' => 'Tên lớp',
        'teacher' => 'Giáo viên',
        'slot1_day' => 'Buổi 1 thứ',
        'slot1_start' => 'Buổi 1 bắt đầu',
        'slot1_end' => 'Buổi 1 kết thúc',
        'slot1_room' => 'Buổi 1 phòng',
        'slot2_day' => 'Buổi 2 thứ',
        'slot2_start' => 'Buổi 2 bắt đầu',
        'slot2_end' => 'Buổi 2 kết thúc',
        'slot2_room' => 'Buổi 2 phòng',
        'start_date' => 'Ngày bắt đầu khóa',
        'end_date' => 'Ngày kết thúc khóa',
        'tuition_fee' => 'Học phí',
        'notes' => 'Ghi chú',
    ];

    public const STUDENT_HEADINGS = [
        'branch' => 'Cơ sở',
        'class_code' => 'Mã lớp',
        'code' => 'Mã học viên',
        'name' => 'Họ tên',
        'phone' => 'SĐT học viên',
        'dob' => 'Ngày sinh',
        'gender' => 'Giới tính',
        'email' => 'Email',
        'facebook' => 'Facebook',
        'id_card' => 'CCCD',
        'school' => 'Trường',
        'parent_name' => 'Phụ huynh',
        'parent_phone' => 'SĐT phụ huynh',
        'parent_email' => 'Email phụ huynh',
        'parent_facebook' => 'Facebook phụ huynh',
        'parent2_name' => 'Phụ huynh 2',
        'parent2_phone' => 'SĐT phụ huynh 2',
        'address' => 'Địa chỉ',
        'enrolled_at' => 'Ngày nhập học',
        'total_lessons' => 'Số buổi',
        'attended_lessons' => 'Đã học',
        'notes' => 'Ghi chú',
    ];

    /** Tiêu đề (đã slug) của sheet Lớp => key; gồm cả bảng "DS lớp - lịch" gốc (LỚP, LỊCH HỌC nhiều dòng). */
    private const CLASS_ALIASES = [
        'co_so' => 'branch', 'chi_nhanh' => 'branch',
        'ma_lop' => 'class_code', 'lop' => 'class_code',
        'ten_lop' => 'class_name', 'ten_lop_goc' => 'class_name',
        'giao_vien' => 'teacher', 'giao_vien_chinh' => 'teacher',
        'buoi_1_thu' => 'slot1_day', 'buoi_1_bat_dau' => 'slot1_start', 'buoi_1_ket_thuc' => 'slot1_end', 'buoi_1_phong' => 'slot1_room',
        'buoi_2_thu' => 'slot2_day', 'buoi_2_bat_dau' => 'slot2_start', 'buoi_2_ket_thuc' => 'slot2_end', 'buoi_2_phong' => 'slot2_room',
        'lich_hoc' => 'schedule_text', 'lich_hoc_thu_gio_phong' => 'schedule_text',
        'ngay_bat_dau_khoa' => 'start_date', 'ngay_khai_giang' => 'start_date', 'ngay_bat_dau' => 'start_date',
        'ngay_ket_thuc_khoa' => 'end_date', 'ngay_ket_thuc' => 'end_date',
        'hoc_phi' => 'tuition_fee',
        'ghi_chu' => 'notes',
    ];

    /** Tiêu đề (đã slug) của sheet Học viên => key; gồm cả file xuất gốc từng lớp. */
    private const STUDENT_ALIASES = [
        'co_so' => 'branch', 'chi_nhanh' => 'branch',
        'ma_lop' => 'class_code', 'lop' => 'class_text', 'lop_goc' => 'class_text',
        'ma_hoc_vien' => 'code', 'ma_hv' => 'code', 'ma_hoc_sinh' => 'code',
        'ho_ten' => 'name', 'ho_va_ten' => 'name', 'ten_hoc_vien' => 'name',
        'sdt_hoc_vien' => 'phone', 'dien_thoai' => 'phone', 'so_dien_thoai' => 'phone', 'sdt' => 'phone',
        'ngay_sinh' => 'dob', 'gioi_tinh' => 'gender', 'email' => 'email', 'facebook' => 'facebook',
        'cccd' => 'id_card', 'so_cccd' => 'id_card', 'cccd_ma_dinh_danh' => 'id_card',
        'truong' => 'school', 'truong_hoc' => 'school',
        'phu_huynh' => 'parent_name', 'ten_phu_huynh' => 'parent_name',
        'sdt_phu_huynh' => 'parent_phone', 'dien_thoai_ph' => 'parent_phone', 'sdt_ph' => 'parent_phone',
        'email_phu_huynh' => 'parent_email', 'email_ph' => 'parent_email',
        'facebook_phu_huynh' => 'parent_facebook', 'facebook_ph' => 'parent_facebook',
        'phu_huynh_2' => 'parent2_name', 'sdt_phu_huynh_2' => 'parent2_phone',
        'dia_chi' => 'address', 'ngay_nhap_hoc' => 'enrolled_at',
        'so_buoi' => 'total_lessons', 'da_hoc' => 'attended_lessons',
        'ghi_chu' => 'notes',
    ];

    /** Thứ trong tuần (đã bỏ dấu, khoảng trắng) => tên ngày dùng trong TKB. */
    private const DAYS = [
        't2' => 'Thứ 2', 'thu2' => 'Thứ 2', 't3' => 'Thứ 3', 'thu3' => 'Thứ 3', 't4' => 'Thứ 4', 'thu4' => 'Thứ 4',
        't5' => 'Thứ 5', 'thu5' => 'Thứ 5', 't6' => 'Thứ 6', 'thu6' => 'Thứ 6', 't7' => 'Thứ 7', 'thu7' => 'Thứ 7',
        'cn' => 'Chủ nhật', 'chunhat' => 'Chủ nhật',
    ];

    public function __construct(
        private readonly SessionScheduleService $schedule,
        private readonly TeachingShiftRules $shiftRules,
    ) {}

    /**
     * @return array{classes: list<array>, students: list<array>, missing: ?string}
     */
    public function parse(UploadedFile $file, ?int $defaultBranchId, User $user): array
    {
        $classSheet = null;
        $studentSheet = null;
        foreach (RawRowsImport::allSheets($file) as $rows) {
            if ($studentSheet === null && ($sheet = $this->locate($rows, self::STUDENT_ALIASES, ['name'])) && isset(array_flip($sheet['columns'])['code'])) {
                $studentSheet = $sheet;
            } elseif ($classSheet === null && ($sheet = $this->locate($rows, self::CLASS_ALIASES, ['class_code'])) && array_intersect($sheet['columns'], ['teacher', 'slot1_day', 'schedule_text'])) {
                $classSheet = $sheet;
            }
        }

        if ($studentSheet === null) {
            return ['classes' => [], 'students' => [], 'missing' => 'Không tìm thấy sheet học viên (cần các cột "Mã học viên" và "Họ tên").'];
        }

        $branches = Branch::query()->get(['id', 'code', 'name']);
        $allowed = fn (?int $id) => $id !== null && DataScope::coversBranch($user, 'class', $id);
        $resolveBranch = function (mixed $value, array &$errors) use ($branches, $defaultBranchId, $allowed): ?Branch {
            $branch = filled($value) ? $this->findBranch($branches, (string) $value) : $branches->firstWhere('id', $defaultBranchId);
            if (! $branch) {
                $errors[] = filled($value) ? "Chưa có cơ sở \"{$value}\" trong hệ thống — tạo cơ sở trước khi nhập." : 'Thiếu cơ sở: chọn cơ sở mặc định hoặc điền cột "Cơ sở".';
            } elseif (! $allowed($branch->id)) {
                $errors[] = "Bạn không quản lý cơ sở {$branch->name}.";
                $branch = null;
            }

            return $branch;
        };

        $existingClasses = ClassModel::withTrashed()->get(['id', 'code', 'name', 'branch_id', 'teacher_id', 'room_id', 'status', 'deleted_at'])
            ->keyBy(fn (ClassModel $c) => self::classKey((string) $c->code));
        $teachers = User::query()->role([...Roles::TEACHING, Roles::ACADEMIC_LEAD])->where('is_active', true)->with('roles')->get(['id', 'name']);
        $rooms = Room::query()->get(['id', 'branch_id', 'name']);

        // ── Lớp ──
        $classes = [];
        foreach ($classSheet['rows'] ?? [] as $line => $data) {
            $errors = [];
            $warnings = [];
            $branch = $resolveBranch($data['branch'] ?? null, $errors);
            $code = self::cleanCode((string) ($data['class_code'] ?? ''));
            $key = self::classKey($code);
            if ($code === '') {
                continue; // dòng tiêu đề phụ / dòng trống của bảng lịch
            }
            if (isset($classes[$key])) {
                $errors[] = "Mã lớp {$code} lặp lại trong sheet Lớp.";
            }

            $existing = $existingClasses->get($key);
            if ($existing?->trashed()) {
                $errors[] = "Mã lớp {$code} thuộc một lớp đã xóa — đổi mã hoặc khôi phục lớp cũ.";
            } elseif ($existing && $branch && (int) $existing->branch_id !== $branch->id) {
                $errors[] = "Mã lớp {$code} đã có ở cơ sở khác.";
            }

            $slots = $this->slots($data, $warnings);
            if ($slots === [] && ! $existing) {
                $warnings[] = 'Chưa có lịch học: lớp được tạo ở trạng thái Chờ xếp lịch.';
            }
            foreach ($slots as $slot) {
                if ($violation = $this->shiftRules->violation($slot['day'], $slot['start'], $slot['end'])) {
                    $warnings[] = 'Lệch khung ca dạy: '.$violation;
                }
            }

            $teacher = $teacherWarning = null;
            if (filled($data['teacher'] ?? null)) {
                [$teacher, $teacherWarning] = $this->findTeacher($teachers, (string) $data['teacher']);
                if ($teacherWarning) {
                    $warnings[] = $teacherWarning;
                }
            }

            $roomName = $slots[0]['room'] ?? null;
            $room = $roomName && $branch ? $rooms->first(fn (Room $r) => $r->branch_id === $branch->id && self::fold($r->name) === self::fold($roomName)) : null;
            $newRooms = $branch ? collect($slots)->pluck('room')->filter()->unique(fn ($name) => self::fold($name))
                ->reject(fn ($name) => $rooms->contains(fn (Room $r) => $r->branch_id === $branch->id && self::fold($r->name) === self::fold($name)))->values()->all() : [];
            // Lớp đã có mà chưa gắn phòng: lúc nhập sẽ tạo / gắn phòng theo file.
            $linkRoom = $existing && ! $existing->room_id && $roomName && $branch;
            if ($existing && ! $linkRoom) {
                $newRooms = [];
            }
            // Lớp đã có mà chưa có giáo viên (vd. nhập lớp trước khi tạo tài khoản giáo viên): gán giáo viên khớp được.
            $linkTeacher = $existing && ! $existing->teacher_id && $teacher;
            $roomNote = $newRooms ? 'Phòng '.implode(', ', $newRooms).' chưa có ở cơ sở: tạo mới khi nhập (loại "'.self::DEFAULT_ROOM_TYPE.'", sửa sức chứa trên màn Phòng học).' : null;

            $classes[$key] = [
                'line' => $line,
                'key' => $key,
                'code' => $existing?->code ?? $code,
                'name' => $existing?->name ?? (filled($data['class_name'] ?? null) ? trim((string) $data['class_name']) : $code),
                'branch_id' => $branch?->id,
                'branch_name' => $branch?->name,
                'class_id' => $existing?->id,
                'teacher_id' => $teacher?->id,
                'teacher_label' => $teacher?->name ?? (filled($data['teacher'] ?? null) ? trim((string) $data['teacher']) : null),
                'slots' => $slots,
                'room' => $roomName,
                'room_id' => $room?->id,
                'link_room' => (bool) $linkRoom,
                'link_teacher' => (bool) $linkTeacher,
                'start_date' => $this->date($data['start_date'] ?? null, 'Ngày bắt đầu khóa', $warnings),
                'end_date' => $this->date($data['end_date'] ?? null, 'Ngày kết thúc khóa', $warnings),
                'tuition_fee' => $this->money($data['tuition_fee'] ?? null, $warnings),
                'notes' => filled($data['notes'] ?? null) ? mb_substr(trim((string) $data['notes']), 0, 1000) : null,
                'student_count' => 0,
                'first_enrolled' => null,
                'errors' => $errors,
                'warnings' => array_values(array_filter($existing
                    ? ['Lớp đã có trong hệ thống: giữ nguyên, chỉ xếp học viên vào'.collect([$linkTeacher ? 'gán giáo viên '.$teacher->name : null, $linkRoom ? 'gắn phòng '.$roomName : null])->filter()->map(fn ($s) => ', '.$s)->implode('').'.', $existing->teacher_id ? null : $teacherWarning, $roomNote]
                    : [...$warnings, $roomNote])),
            ];
        }

        // ── Học viên ──
        $codes = collect($studentSheet['rows'])->map(fn ($r) => mb_strtoupper(trim((string) ($r['code'] ?? ''))))->filter()->unique()->values();
        $existingStudents = Student::withTrashed()->whereIn('code', $codes)->get(['id', 'code', 'name', 'branch_id', 'deleted_at'])
            ->keyBy(fn (Student $s) => mb_strtoupper($s->code));
        $enrolled = ClassEnrollment::query()->whereIn('student_id', $existingStudents->pluck('id'))
            ->whereIn('status', Student::ACTIVE_ENROLLMENT_STATUSES)->get(['student_id', 'class_id'])
            ->map(fn ($e) => $e->student_id.':'.$e->class_id)->flip();

        $students = [];
        $seen = [];
        foreach (array_slice($studentSheet['rows'], 0, self::MAX_ROWS, true) as $line => $data) {
            $errors = [];
            $warnings = [];
            $name = trim((string) ($data['name'] ?? ''));
            $code = mb_strtoupper(trim((string) ($data['code'] ?? '')));
            if ($name === '' && $code === '') {
                continue; // dòng chỉ có ghi chú chuyển lớp của file gốc
            }
            if ($name === '') {
                $errors[] = 'Thiếu họ tên.';
            }

            $branch = $resolveBranch($data['branch'] ?? null, $errors);
            $classCode = self::cleanCode((string) ($data['class_code'] ?? self::codeFromClassText((string) ($data['class_text'] ?? ''))));
            $classKey = self::classKey($classCode);
            $class = $classes[$classKey] ?? null;
            $existingClass = $class ? null : $existingClasses->get($classKey);
            if ($classCode === '') {
                $errors[] = 'Thiếu mã lớp.';
            } elseif ($class) {
                if ($class['errors'] !== []) {
                    $errors[] = "Lớp {$classCode} có lỗi ở sheet Lớp (dòng {$class['line']}).";
                } elseif ($branch && $class['branch_id'] !== $branch->id) {
                    $errors[] = "Lớp {$classCode} thuộc cơ sở {$class['branch_name']}, không phải {$branch->name}.";
                }
            } elseif (! $existingClass || $existingClass->trashed()) {
                $errors[] = "Không có lớp {$classCode}: thêm lớp vào sheet Lớp hoặc tạo lớp trước.";
            } elseif ($branch && (int) $existingClass->branch_id !== $branch->id) {
                $errors[] = "Lớp {$classCode} thuộc cơ sở khác.";
            }

            $student = $code !== '' ? $existingStudents->get($code) : null;
            $classId = $class['class_id'] ?? $existingClass?->id;
            $action = $student ? 'enroll' : 'create';
            if ($student?->trashed()) {
                $errors[] = "Mã học viên {$code} thuộc hồ sơ đã xóa.";
            } elseif ($student && $branch && (int) $student->branch_id !== $branch->id) {
                $errors[] = "Học viên {$code} đã có ở cơ sở khác.";
            } elseif ($student && $classId && isset($enrolled[$student->id.':'.$classId])) {
                $action = 'skip';
                $warnings[] = 'Học viên đã có trong lớp này: bỏ qua.';
            } elseif ($student) {
                $warnings[] = 'Học viên đã có hồ sơ: không sửa hồ sơ, chỉ xếp vào lớp.';
            }
            if ($code !== '' && isset($seen[$code][$classKey])) {
                $errors[] = "Mã học viên {$code} lặp lại trong cùng lớp (dòng {$seen[$code][$classKey]}).";
            } elseif ($code !== '' && isset($seen[$code]) && $action === 'create') {
                $action = 'enroll'; // cùng học viên, lớp thứ hai: tạo hồ sơ một lần
            }
            if ($code === '') {
                $warnings[] = 'Không có mã học viên: hệ thống sinh mã mới.';
            }

            $studentPhones = self::phones($data['phone'] ?? null);
            $parentPhones = self::phones($data['parent_phone'] ?? null);
            $parentNames = self::splitNames($data['parent_name'] ?? null);
            $phone = $studentPhones[0] ?? $parentPhones[0] ?? null;
            if ($action === 'create' && ! $phone) {
                $warnings[] = 'Không có SĐT (cả học viên lẫn phụ huynh): vẫn nhập, bổ sung SĐT trên hồ sơ sau.';
            } elseif ($action === 'create' && ! $studentPhones) {
                $warnings[] = 'Không có SĐT học viên: dùng SĐT phụ huynh.';
            }

            $total = $this->count($data['total_lessons'] ?? null);
            $attended = $this->count($data['attended_lessons'] ?? null);
            if ($total !== null && $attended !== null && $attended > $total) {
                $warnings[] = "Đã học {$attended} buổi, nhiều hơn số buổi đã đóng ({$total}): kiểm tra học phí.";
            }

            $email = trim((string) ($data['email'] ?? ''));
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $warnings[] = "Email \"{$email}\" không hợp lệ: bỏ qua.";
                $email = '';
            }
            $parentEmail = trim((string) ($data['parent_email'] ?? ''));
            if ($parentEmail !== '' && ! filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
                $parentEmail = '';
            }
            $parent2Phone = self::phones($data['parent2_phone'] ?? null)[0] ?? $parentPhones[1] ?? null;
            $extraNotes = array_filter([
                count($parentPhones) > 2 ? 'SĐT phụ huynh khác: '.implode(', ', array_slice($parentPhones, 2)) : null,
                count($studentPhones) > 1 ? 'SĐT học viên khác: '.implode(', ', array_slice($studentPhones, 1)) : null,
            ]);
            $enrolledAt = $this->date($data['enrolled_at'] ?? null, 'Ngày nhập học', $warnings);

            if ($class && $errors === [] && $action !== 'skip') {
                $classes[$classKey]['student_count']++;
                if ($enrolledAt && ($classes[$classKey]['first_enrolled'] === null || $enrolledAt < $classes[$classKey]['first_enrolled'])) {
                    $classes[$classKey]['first_enrolled'] = $enrolledAt;
                }
            }
            if ($code !== '' && $errors === []) {
                $seen[$code][$classKey] = $line;
            }

            $students[] = [
                'line' => $line,
                'action' => $action,
                'student_id' => $student?->id,
                'code' => $code !== '' ? mb_substr($code, 0, 50) : null,
                'name' => $student?->name ?? mb_substr($name, 0, 255),
                'branch_id' => $branch?->id,
                'class_key' => $classKey,
                'class_code' => $class['code'] ?? $existingClass?->code ?? $classCode,
                'class_id' => $existingClass?->id,
                'phone' => $phone,
                'dob' => $this->date($data['dob'] ?? null, 'Ngày sinh', $warnings),
                'gender' => self::gender($data['gender'] ?? null),
                'email' => $email ?: null,
                'facebook' => self::text($data['facebook'] ?? null),
                'id_card_number' => self::idCard($data['id_card'] ?? null),
                'school' => self::text($data['school'] ?? null),
                'parent_name' => $parentNames[0] ?? null,
                'parent_phone' => $parentPhones[0] ?? null,
                'parent_email' => $parentEmail ?: null,
                'parent_facebook' => self::text($data['parent_facebook'] ?? null),
                'parent2_name' => self::text($data['parent2_name'] ?? null) ?? ($parentNames[1] ?? null),
                'parent2_phone' => $parent2Phone,
                'address' => self::text($data['address'] ?? null),
                'enrolled_at' => $enrolledAt,
                'total_lessons' => $total,
                'attended_lessons' => $attended,
                'notes' => ($notes = trim(implode("\n", array_filter([trim((string) ($data['notes'] ?? '')), ...$extraNotes])))) !== '' ? mb_substr($notes, 0, 2000) : null,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        return ['classes' => array_values($classes), 'students' => $students, 'missing' => null];
    }

    /**
     * Ghi lớp rồi học viên (chỉ các dòng không lỗi). Buổi học sinh từ $sessionsFrom (không trước hôm nay)
     * đến ngày kết thúc khóa của lớp, hoặc $sessionsTo nếu lớp không ghi ngày kết thúc.
     *
     * @return array{classes: int, sessions: int, rooms: int, teachers: int, students: int, enrollments: int, failed: list<array{line: string, error: string}>}
     */
    public function import(array $preview, User $user, Carbon $sessionsFrom, Carbon $sessionsTo): array
    {
        $result = ['classes' => 0, 'sessions' => 0, 'rooms' => 0, 'teachers' => 0, 'students' => 0, 'enrollments' => 0, 'failed' => []];
        $classIds = [];
        $this->roomCache = [];
        $roomsBefore = Room::query()->count();

        foreach ($preview['classes'] as $row) {
            if ($row['errors'] !== []) {
                continue;
            }
            if ($row['class_id']) {
                $classIds[$row['key']] = $row['class_id'];
                // Lớp đã có (vd. nhập lần trước khi chưa tự tạo phòng) mà chưa gắn phòng: tạo / gắn phòng theo file.
                if (($row['link_room'] ?? false) && $row['room'] && $row['branch_id']) {
                    try {
                        DB::transaction(function () use ($row) {
                            $class = ClassModel::query()->lockForUpdate()->find($row['class_id']);
                            if ($class && ! $class->room_id) {
                                foreach ($row['slots'] as $slot) {
                                    if ($slot['room']) {
                                        $this->room((int) $row['branch_id'], $slot['room']);
                                    }
                                }
                                $room = $this->room((int) $row['branch_id'], $row['room']);
                                $class->update(['room_id' => $room->id, 'room' => $class->room ?: $room->name]);
                            }
                        });
                    } catch (\Throwable $e) {
                        $this->roomCache = []; // phòng tạo trong giao dịch vừa hủy không còn
                        $result['failed'][] = ['line' => 'Phòng lớp '.$row['code'], 'error' => $e->getMessage()];
                    }
                }

                if (($row['link_teacher'] ?? false) && $row['teacher_id']) {
                    try {
                        $result['teachers'] += DB::transaction(function () use ($row) {
                            $class = ClassModel::query()->lockForUpdate()->find($row['class_id']);
                            if (! $class || $class->teacher_id) {
                                return 0;
                            }
                            $class->update(['teacher_id' => $row['teacher_id']]);
                            ClassSession::query()->where('class_id', $class->id)->whereNull('teacher_id')->where('status', 'scheduled')
                                ->update(['teacher_id' => $row['teacher_id']]);

                            return 1;
                        });
                    } catch (\Throwable $e) {
                        $result['failed'][] = ['line' => 'Giáo viên lớp '.$row['code'], 'error' => $e->getMessage()];
                    }
                }

                continue;
            }

            try {
                [$class, $sessions] = DB::transaction(fn () => $this->createClass($row, $sessionsFrom, $sessionsTo));
                $classIds[$row['key']] = $class->id;
                $result['classes']++;
                $result['sessions'] += $sessions;
            } catch (\Throwable $e) {
                $this->roomCache = [];
                $result['failed'][] = ['line' => 'Lớp '.$row['code'], 'error' => $e->getMessage()];
            }
        }
        $result['rooms'] = Room::query()->count() - $roomsBefore;

        $createdByCode = [];
        foreach ($preview['students'] as $row) {
            if ($row['errors'] !== [] || $row['action'] === 'skip') {
                continue;
            }
            $classId = $classIds[$row['class_key']] ?? $row['class_id'];
            if (! $classId) {
                $result['failed'][] = ['line' => 'Học viên dòng '.$row['line'], 'error' => "Lớp {$row['class_code']} chưa được tạo."];

                continue;
            }

            try {
                DB::transaction(function () use ($row, $classId, $user, &$createdByCode, &$result) {
                    $studentId = $row['student_id'] ?? ($row['code'] ? ($createdByCode[$row['code']] ?? null) : null);
                    $student = $studentId ? Student::query()->lockForUpdate()->findOrFail($studentId) : null;
                    if (! $student) {
                        if ($row['code'] && Student::withTrashed()->where('code', $row['code'])->exists()) {
                            throw new \RuntimeException("Mã học viên {$row['code']} vừa được tạo ở nơi khác.");
                        }
                        $student = Student::create([
                            'code' => $row['code'] ?? 'HV-'.Str::upper((string) Str::ulid()),
                            'name' => $row['name'],
                            'phone' => $row['phone'] ?? '', // cột bắt buộc: để trống, bổ sung trên hồ sơ
                            'parent_name' => $row['parent_name'],
                            'parent_phone' => $row['parent_phone'],
                            'parent_email' => $row['parent_email'],
                            'parent_facebook' => $row['parent_facebook'],
                            'parent2_name' => $row['parent2_name'],
                            'parent2_phone' => $row['parent2_phone'],
                            'email' => $row['email'],
                            'facebook' => $row['facebook'],
                            'id_card_number' => $row['id_card_number'],
                            'dob' => $row['dob'],
                            'gender' => $row['gender'],
                            'address' => $row['address'],
                            'school' => $row['school'],
                            'branch_id' => $row['branch_id'],
                            'status' => 'studying',
                            'study_started_on' => $row['enrolled_at'] && $row['enrolled_at'] <= today()->toDateString() ? $row['enrolled_at'] : null,
                            'notes' => $row['notes'],
                        ] + array_filter([
                            'total_lessons' => $row['total_lessons'] ?: null,
                            'attended_lessons' => $row['attended_lessons'],
                        ], fn ($v) => $v !== null));
                        $result['students']++;
                    }
                    if ($row['code']) {
                        $createdByCode[$row['code']] = $student->id;
                    }

                    $alreadyIn = ClassEnrollment::query()->where('student_id', $student->id)->where('class_id', $classId)
                        ->whereIn('status', Student::ACTIVE_ENROLLMENT_STATUSES)->exists();
                    if (! $alreadyIn) {
                        // Học viên đang học từ hệ thống cũ: lượt xếp lớp đã xác nhận (không chờ bàn giao).
                        ClassEnrollment::create([
                            'student_id' => $student->id,
                            'class_id' => $classId,
                            'enrolled_at' => $row['enrolled_at'] ?? today()->toDateString(),
                            'curriculum_delivered' => false,
                            'zalo_group_added' => false,
                            'account_sent' => false,
                            'status' => 'completed',
                            'confirmed_at' => now(),
                            'confirmed_by' => $user->id,
                        ]);
                        $student->forceFill(['current_class_id' => $classId])->save();
                        $result['enrollments']++;
                    }
                });
            } catch (\Throwable $e) {
                $result['failed'][] = ['line' => 'Học viên dòng '.$row['line'], 'error' => $e instanceof \RuntimeException ? $e->getMessage() : 'Lỗi ghi dữ liệu: '.$e->getMessage()];
            }
        }

        return $result;
    }

    /** @return array{0: ClassModel, 1: int} lớp vừa tạo + số buổi học đã sinh */
    private function createClass(array $row, Carbon $sessionsFrom, Carbon $sessionsTo): array
    {
        if (ClassModel::withTrashed()->where('code', $row['code'])->exists()) {
            throw new \RuntimeException("Mã lớp {$row['code']} vừa được tạo ở nơi khác.");
        }

        // Phòng ghi trong lịch mà cơ sở chưa có → tạo phòng; buổi học + lớp dùng đúng tên phòng trong danh mục.
        $slots = array_map(fn (array $slot) => ['room' => $slot['room'] ? $this->room((int) $row['branch_id'], $slot['room'])->name : null] + $slot
            + ['name' => $this->shiftRules->nameFor($slot['day'], $slot['start'], $slot['end'])], $row['slots']);
        $room = $row['room'] ? $this->room((int) $row['branch_id'], $row['room']) : null;
        $class = ClassModel::create([
            'code' => $row['code'],
            'name' => $row['name'],
            'branch_id' => $row['branch_id'],
            'teacher_id' => $row['teacher_id'],
            'room' => $room?->name,
            'room_id' => $room?->id,
            'max_capacity' => max(self::DEFAULT_CAPACITY, (int) $row['student_count']),
            'min_students' => ClassModel::DEFAULT_MIN_STUDENTS,
            'tuition_fee' => $row['tuition_fee'],
            // Lớp đang học từ hệ thống cũ: không ghi ngày khai giảng thì lấy ngày nhập học sớm nhất của học viên trong lớp.
            'start_date' => $row['start_date'] ?? $row['first_enrolled'],
            'end_date' => $row['end_date'],
            'schedule_text' => collect($slots)->map(fn ($s) => "{$s['day']} {$s['start']}-{$s['end']}".($s['room'] ? " ({$s['room']})" : ''))->implode('; ') ?: null,
            'notes' => trim('Nhập từ danh sách lớp hệ thống cũ. '.($row['notes'] ?? '')),
            'status' => $slots ? 'active' : 'pending_schedule',
        ]);

        if ($slots === []) {
            return [$class, 0];
        }

        $from = $sessionsFrom->copy()->max($row['start_date'] ? Carbon::parse($row['start_date']) : $sessionsFrom)->max(today())->startOfDay();
        $to = $row['end_date'] ? Carbon::parse($row['end_date']) : $sessionsTo->copy();
        ClassScheduleConfig::create([
            'class_id' => $class->id,
            'academic_year' => $from->format('Y').' - '.$to->copy()->max($from)->format('Y'),
            'slot1_day' => $slots[0]['day'], 'slot1_start' => $slots[0]['start'], 'slot1_end' => $slots[0]['end'],
            'slot2_day' => $slots[1]['day'] ?? null, 'slot2_start' => $slots[1]['start'] ?? null, 'slot2_end' => $slots[1]['end'] ?? null,
        ]);

        $sessions = $this->schedule->generate($slots, $from, $to, $class->branch_id);
        foreach ($sessions as $session) {
            ClassSession::create([
                'class_id' => $class->id,
                'branch_id' => $class->branch_id,
                'date' => $session['date'],
                'shift_name' => $session['name'],
                'type' => ClassSession::TYPE_REGULAR,
                'start_time' => $session['start'],
                'end_time' => $session['end'],
                'room' => $session['room'] ?: $class->room,
                'teacher_id' => $class->teacher_id,
                'status' => 'scheduled',
            ]);
        }
        if ($sessions !== [] && ! $class->start_date) {
            $class->update(['start_date' => $sessions[0]['date']]);
        }
        if ($sessions !== [] && ! $row['end_date']) {
            $class->update(['end_date' => end($sessions)['date']]);
        }

        return [$class, count($sessions)];
    }

    /** @return list<array{day: string, start: string, end: string, room: ?string}> */
    private function slots(array $data, array &$warnings): array
    {
        $slots = [];
        foreach ([1, 2] as $n) {
            if (! filled($data["slot{$n}_day"] ?? null)) {
                continue;
            }
            $day = self::day((string) $data["slot{$n}_day"]);
            $start = self::time($data["slot{$n}_start"] ?? null);
            $end = self::time($data["slot{$n}_end"] ?? null);
            if (! $day || ! $start || ! $end || strcmp($end, $start) <= 0) {
                $warnings[] = "Buổi {$n} không đọc được (thứ / giờ): bỏ qua.";

                continue;
            }
            $slots[] = ['day' => $day, 'start' => $start, 'end' => $end, 'room' => self::text($data["slot{$n}_room"] ?? null)];
        }

        // Bảng "DS lớp - lịch" gốc: mỗi dòng của ô LỊCH HỌC là "T2   18:00–19:30   P301".
        if ($slots === [] && filled($data['schedule_text'] ?? null)) {
            foreach (preg_split('/\R/u', (string) $data['schedule_text']) as $part) {
                if (preg_match('/^\s*(T\s*[2-7]|CN|Thứ\s*[2-7]|Chủ\s*nhật)\s+(\d{1,2}[:hH.]\d{0,2})\s*[–—-]\s*(\d{1,2}[:hH.]\d{0,2})\s*(.*)$/u', $part, $m)) {
                    $day = self::day($m[1]);
                    $start = self::time($m[2]);
                    $end = self::time($m[3]);
                    if ($day && $start && $end && strcmp($end, $start) > 0) {
                        $slots[] = ['day' => $day, 'start' => $start, 'end' => $end, 'room' => self::text($m[4])];
                    }
                }
            }
        }

        if (count($slots) > 2) {
            $warnings[] = 'Lớp có hơn 2 buổi/tuần: chỉ lấy 2 buổi đầu.';
            $slots = array_slice($slots, 0, 2);
        }

        return $slots;
    }

    /** Phòng theo tên trong một cơ sở (không phân biệt hoa thường / dấu); chưa có thì tạo với loại mặc định. */
    private function room(int $branchId, string $name): Room
    {
        $key = $branchId.'|'.self::fold($name);
        if (isset($this->roomCache[$key])) {
            return $this->roomCache[$key];
        }

        $room = Room::query()->where('branch_id', $branchId)->get()->first(fn (Room $r) => self::fold($r->name) === self::fold($name));
        if (! $room) {
            $type = RoomType::query()->where('name', self::DEFAULT_ROOM_TYPE)->first()
                ?? RoomType::create(['name' => self::DEFAULT_ROOM_TYPE, 'is_active' => true]);
            $room = Room::create(['branch_id' => $branchId, 'room_type_id' => $type->id, 'name' => mb_substr(trim($name), 0, 100)]);
        }

        return $this->roomCache[$key] = $room;
    }

    /** @return array{0: ?User, 1: ?string} giáo viên khớp tên + cảnh báo nếu không khớp được */
    private function findTeacher(Collection $teachers, string $label): array
    {
        $needle = preg_replace('/^(ms|mr|mrs|co|thay|teacher)\s+/', '', self::fold($label));
        $matches = $teachers->filter(fn (User $t) => ($name = self::fold($t->name)) === $needle || str_ends_with($name, ' '.$needle));
        // Trùng tên với trợ giảng (vd. GV "Lưu Đức Anh" và TG "Hoàng Đức Anh"): ưu tiên người có vai trò giáo viên.
        if ($matches->count() > 1) {
            $teaching = $matches->filter(fn (User $t) => $t->hasAnyRole([...Roles::TEACHERS, Roles::ACADEMIC_LEAD]));
            $matches = $teaching->count() === 1 ? $teaching : $matches;
        }

        return match ($matches->count()) {
            1 => [$matches->first(), null],
            0 => [null, "Chưa tìm thấy giáo viên \"{$label}\": lớp tạo chưa có giáo viên, gán sau trên màn lớp."],
            default => [null, "Có {$matches->count()} giáo viên tên \"{$label}\": lớp tạo chưa có giáo viên, gán sau trên màn lớp."],
        };
    }

    /** "3.600.000", "3600000", "3600k" → số tiền; ô ghi diễn giải ("3600k=3480k+120k học vụ") → bỏ trống + cảnh báo. */
    private function money(mixed $value, array &$warnings): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*k$/i', $value, $m)) {
            return round((float) str_replace(',', '.', $m[1]) * 1000);
        }
        if (! preg_match('/^[\d.,\s]+(đ|vnđ|vnd)?$/iu', $value)) {
            $warnings[] = "Học phí \"{$value}\" không đọc được: bỏ trống, nhập lại trên màn lớp.";

            return null;
        }
        $digits = preg_replace('/\D/', '', (string) preg_replace('/[.,]0{1,2}$/', '', $value));

        return $digits !== '' ? (float) $digits : null;
    }

    private function count(mixed $value): ?int
    {
        $value = trim((string) $value);

        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    /** "KINDY 01" / "kindy1" / "CAM07" → khóa so khớp: chữ hoa, bỏ khoảng trắng, bỏ số 0 đầu mỗi cụm số. */
    public static function classKey(string $code): string
    {
        return preg_replace('/(?<!\d)0+(?=\d)/', '', str_replace(' ', '', mb_strtoupper($code)));
    }

    private static function cleanCode(string $code): string
    {
        // "4M3 (CAM6)" / "CAM07 (KINDY5)" → mã chính; tên cũ trong ngoặc giữ ở tên lớp.
        return mb_strtoupper(trim(preg_replace('/\s*\(.*$/u', '', trim($code))));
    }

    /** Mã lớp từ cột "Lớp" của file xuất gốc: "CAM 20 -KID2 T2T6", "5M1.HHT THỨ 2 6", "4m3-CAM6 (KID6)…" → "CAM20", "5M1", "4M3". */
    private static function codeFromClassText(string $text): string
    {
        return preg_match('/^\s*([A-Za-z]+\s?\d+|\d+[A-Za-z]+\d*)/u', $text, $m) ? str_replace(' ', '', mb_strtoupper($m[1])) : '';
    }

    private static function day(string $value): ?string
    {
        return self::DAYS[preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($value)))] ?? null;
    }

    private static function time(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value) && (float) $value < 1) { // ô giờ Excel (phần ngày)
            $minutes = (int) round((float) $value * 1440);

            return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }
        if (preg_match('/^(\d{1,2})\s*[:hH.]\s*(\d{2})?/', $value, $m) && (int) $m[1] < 24) {
            return sprintf('%02d:%02d', (int) $m[1], (int) ($m[2] ?? 0));
        }

        return null;
    }

    private static function splitNames(mixed $value): array
    {
        return array_values(array_filter(array_map(fn ($n) => mb_substr(trim($n), 0, 255), preg_split('/\//u', (string) $value)), fn ($n) => $n !== ''));
    }

    /** CCCD đọc từ ô số mất số 0 đầu (10–11 chữ số) → đủ 12 chữ số. */
    private static function idCard(mixed $value): ?string
    {
        $value = preg_replace('/\.0$/', '', trim((string) $value));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value) && strlen($value) >= 10 && strlen($value) < 12) {
            $value = str_pad($value, 12, '0', STR_PAD_LEFT);
        }

        return mb_substr($value, 0, 30);
    }

    private static function gender(mixed $value): ?string
    {
        return match (Str::slug(Str::ascii((string) $value))) {
            'nam', 'male', 'm' => 'Nam',
            'nu', 'female', 'f' => 'Nữ',
            '' => null,
            default => 'Khác',
        };
    }
}
