<?php

namespace App\Services;

use App\Helpers\AclHelper;
use App\Imports\RawRowsImport;
use App\Models\Branch;
use App\Models\User;
use App\Services\Concerns\ReadsImportRows;
use App\Support\DataScope;
use App\Support\Rbac;
use App\Support\Roles;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Nhập hồ sơ nhân sự (giáo viên, trợ giảng…) từ Excel: tạo tài khoản (mã MES tự cấp, mật khẩu ban đầu chung, bắt đổi
 * khi đăng nhập lần đầu) kèm hồ sơ. Nhận file mẫu (HEADINGS) lẫn sheet gốc "Giáo viên Part" / form "Trợ giảng".
 * Chạy lại không tạo trùng: email đã có (email công việc hoặc email cá nhân) → chỉ bổ sung trường hồ sơ đang trống.
 */
class StaffImportService
{
    use ReadsImportRows;

    public const MAX_ROWS = 500;

    public const HEADINGS = [
        'name' => 'Họ tên',
        'role' => 'Vai trò',
        'branch' => 'Cơ sở',
        'email' => 'Email công việc',
        'personal_email' => 'Email cá nhân',
        'phone' => 'SĐT',
        'date_of_birth' => 'Ngày sinh',
        'id_card_number' => 'CCCD',
        'id_card_issued_on' => 'Ngày cấp CCCD',
        'hometown' => 'Quê quán',
        'current_address' => 'Nơi ở hiện tại',
        'emergency_name' => 'Người liên hệ khẩn cấp',
        'emergency_phone' => 'SĐT người liên hệ',
        'graduation_school' => 'Tốt nghiệp',
        'major' => 'Chuyên ngành',
        'teaching_level' => 'Level giảng dạy',
        'certificates' => 'Chứng chỉ',
        'contract_type' => 'Hình thức',
        'joined_on' => 'Ngày bắt đầu làm việc',
        'facebook' => 'Facebook',
        'bank_account' => 'TK ngân hàng',
        'contact_channel' => 'Kênh liên lạc',
        'staff_note' => 'Ghi chú',
    ];

    /** Tiêu đề (đã slug) => key; gồm cả sheet gốc "Giáo viên Part" và form "Trợ giảng". */
    private const ALIASES = [
        'ho_ten' => 'name', 'ho_va_ten' => 'name', 'ho_va_ten_gv' => 'name', 'ho_va_ten_day_du' => 'name',
        'vai_tro' => 'role', 'vi_tri' => 'role',
        'co_so' => 'branch', 'chi_nhanh' => 'branch',
        'email_cong_viec' => 'email', 'email_dang_nhap' => 'email', 'mail_doanh_nghiep' => 'email', 'email' => 'email',
        'email_ca_nhan' => 'personal_email', 'mail_ca_nhan' => 'personal_email',
        'sdt' => 'phone', 'so_dien_thoai' => 'phone', 'so_dien_thoai_ca_nhan' => 'phone',
        'ngay_sinh' => 'date_of_birth', 'ngay_thang_nam_sinh' => 'date_of_birth',
        'cccd' => 'id_card_number', 'so_cccd' => 'id_card_number', 'can_cuoc_cong_dan' => 'id_card_number',
        'ngay_cap_cccd' => 'id_card_issued_on', 'ngay_cap' => 'id_card_issued_on',
        'que_quan' => 'hometown', 'dia_chi_o_que_neu_co' => 'hometown',
        'noi_o_hien_tai' => 'current_address', 'dia_chi_dang_o' => 'current_address',
        'nguoi_lien_he_khan_cap' => 'emergency_name', 'nguoi_lien_lac_khi_can' => 'emergency_name', 'khi_can_lien_he_voi_ai' => 'emergency_name',
        'sdt_nguoi_lien_he' => 'emergency_phone', 'sdt_lien_he' => 'emergency_phone', 'so_dien_thoai_lien_he_nguoi_than' => 'emergency_phone',
        'tot_nghiep' => 'graduation_school', 'tot_nghiep_truong_nao' => 'graduation_school',
        'chuyen_nganh' => 'major', 'chuyen_nganh_dao_tao' => 'major',
        'level_giang_day' => 'teaching_level',
        'chung_chi' => 'certificates', 'cc_ta_cc_giang_day_khac_neu_co' => 'certificates',
        'hinh_thuc' => 'contract_type', 'loai_hop_dong' => 'contract_type',
        'ngay_bat_dau_lam_viec' => 'joined_on', 'ngay_bat_dau_lam_viec_tai_me' => 'joined_on',
        'facebook' => 'facebook', 'link_fb_neu_co' => 'facebook',
        'tk_ngan_hang' => 'bank_account', 'tai_khoan_ngan_hang_de_nhan_luong_ghi_ro_tk_ngan_hang' => 'bank_account',
        'kenh_lien_lac' => 'contact_channel', 'phuong_thuc_lien_lac_voi_quan_ly_nhan_su' => 'contact_channel',
        'ghi_chu' => 'staff_note', 'note' => 'staff_note',
    ];

    /** Trường hồ sơ ghi từ file (nhân sự đã có: chỉ điền trường đang trống). */
    private const PROFILE_FIELDS = [
        'personal_email', 'facebook', 'phone', 'date_of_birth', 'id_card_number', 'id_card_issued_on', 'hometown', 'current_address',
        'emergency_contact', 'graduation_school', 'major', 'teaching_level', 'certificates', 'contract_type', 'joined_on',
        'bank_account', 'contact_channel', 'staff_note',
    ];

    private const CONTRACT_TYPES = ['Toàn thời gian', 'Bán thời gian', 'Thử việc', 'Cộng tác viên'];

    /**
     * @return array{rows: list<array>, missing: ?string}
     */
    public function parse(UploadedFile $file, ?int $defaultBranchId, ?string $defaultRole, User $actor): array
    {
        $sheet = null;
        foreach (RawRowsImport::allSheets($file) as $rows) {
            if ($sheet = $this->locate($rows, self::ALIASES, ['name'])) {
                break;
            }
        }
        if (! $sheet) {
            return ['rows' => [], 'missing' => 'Không tìm thấy cột "Họ tên". Vui lòng dùng file mẫu.'];
        }
        if (count($sheet['rows']) > self::MAX_ROWS) {
            return ['rows' => [], 'missing' => 'File có quá '.self::MAX_ROWS.' dòng, vui lòng chia nhỏ.'];
        }

        $branches = Branch::query()->where('is_active', true)->get(['id', 'name', 'code']);
        $managed = DataScope::level($actor, 'user') === DataScope::BRANCH ? $actor->branchIds() : null;
        $existing = User::query()->get(['id', 'name', 'email', 'personal_email', 'employee_code', 'branch_id', 'created_by']);
        $byEmail = $existing->flatMap(fn (User $u) => collect([$u->email, $u->personal_email])->filter()->mapWithKeys(fn ($e) => [Str::lower($e) => $u]));
        $seen = [];
        $out = [];

        foreach ($sheet['rows'] as $line => $data) {
            $errors = [];
            $warnings = [];
            $name = self::text($data['name'] ?? null);
            if (! $name) {
                continue; // dòng ghi chú / trống tên
            }

            $role = $this->role($data['role'] ?? null) ?? $defaultRole;
            if (! $role) {
                $errors[] = filled($data['role'] ?? null) ? "Vai trò \"{$data['role']}\" không nhận ra (Giáo viên part, Giáo viên full, Trợ giảng…)." : 'Chưa có vai trò: ghi cột Vai trò hoặc chọn vai trò mặc định.';
            } elseif (! Rbac::canAssignRole($actor, $role)) {
                $errors[] = 'Bạn không được tạo tài khoản vai trò '.AclHelper::shortRoleLabel($role).'.';
            }

            $branch = filled($data['branch'] ?? null) ? $this->findBranch($branches, (string) $data['branch']) : $branches->firstWhere('id', $defaultBranchId);
            if (! $branch) {
                $errors[] = filled($data['branch'] ?? null) ? "Không tìm thấy cơ sở \"{$data['branch']}\"." : 'Chưa có cơ sở: ghi cột Cơ sở hoặc chọn cơ sở mặc định.';
            } elseif ($managed !== null && ! in_array($branch->id, $managed, true)) {
                $errors[] = "Bạn chỉ được tạo nhân sự cho cơ sở mình quản lý ({$branch->name}).";
            }

            // Email công việc là email đăng nhập; chỉ có email cá nhân → dùng làm email tài khoản.
            $emails = collect([$data['email'] ?? null, $data['personal_email'] ?? null])
                ->map(fn ($e) => Str::lower(trim((string) $e)))->filter()->unique()->values();
            foreach ($emails as $email) {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Email \"{$email}\" không hợp lệ.";
                }
            }
            $email = $emails->first();
            $personalEmail = $emails->get(1);
            if (! $email) {
                $errors[] = 'Chưa có email.';
            }

            $match = $emails->map(fn ($e) => $byEmail->get($e))->filter()->first();
            foreach ($emails as $e) {
                if (isset($seen[$e])) {
                    $errors[] = "Trùng email với dòng {$seen[$e]}.";
                }
                $seen[$e] = $line;
            }
            if ($match) {
                $warnings[] = "Đã có tài khoản {$match->employee_code} ({$match->name}): chỉ bổ sung các trường hồ sơ đang trống.";
                if (! $this->canManage($actor, $match, $managed)) {
                    $errors[] = "Bạn không có quyền sửa tài khoản {$match->employee_code}.";
                }
            } elseif ($same = $existing->first(fn (User $u) => self::fold($u->name) === self::fold($name))) {
                $warnings[] = "Đã có nhân sự cùng tên ({$same->employee_code}, {$same->email}): kiểm tra có trùng người không.";
            }

            $phones = self::phones($data['phone'] ?? null);
            if (count($phones) > 1) {
                $warnings[] = 'Có nhiều SĐT, lấy số đầu: '.implode(', ', $phones).'.';
            }
            if (! $phones) {
                $warnings[] = 'Không có SĐT.';
            }

            $idCard = preg_replace('/\.0$/', '', trim((string) ($data['id_card_number'] ?? '')));
            if ($idCard !== '' && ! preg_match('/^\d{12}$/', $idCard)) {
                $warnings[] = "CCCD \"{$idCard}\" không đủ 12 số: vẫn lưu, kiểm tra lại.";
            }

            $profile = [
                'personal_email' => $personalEmail,
                'facebook' => self::text($data['facebook'] ?? null),
                'phone' => $phones[0] ?? null,
                'date_of_birth' => $this->date($data['date_of_birth'] ?? null, 'Ngày sinh', $warnings),
                'id_card_number' => $idCard !== '' ? mb_substr($idCard, 0, 30) : null,
                'id_card_issued_on' => $this->date($data['id_card_issued_on'] ?? null, 'Ngày cấp CCCD', $warnings),
                'hometown' => self::text($data['hometown'] ?? null),
                'current_address' => self::text($data['current_address'] ?? null),
                'emergency_contact' => self::emergency($data['emergency_name'] ?? null, $data['emergency_phone'] ?? null),
                'graduation_school' => self::text($data['graduation_school'] ?? null),
                'major' => self::text($data['major'] ?? null),
                'teaching_level' => self::text($data['teaching_level'] ?? null),
                'certificates' => self::certificates($data['certificates'] ?? null),
                'contract_type' => self::contractType($data['contract_type'] ?? null) ?? self::contractTypeFor($role),
                'joined_on' => $this->date($data['joined_on'] ?? null, 'Ngày bắt đầu làm việc', $warnings),
                'bank_account' => self::text($data['bank_account'] ?? null),
                'contact_channel' => mb_substr((string) self::text($data['contact_channel'] ?? null), 0, 50) ?: null,
                'staff_note' => filled($data['staff_note'] ?? null) ? mb_substr(trim((string) $data['staff_note']), 0, 2000) : null,
            ];

            $out[] = [
                'line' => $line,
                'action' => $match ? 'update' : 'create',
                'user_id' => $match?->id,
                'employee_code' => $match?->employee_code,
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'role_label' => $role ? AclHelper::shortRoleLabel($role) : null,
                'branch_id' => $branch?->id,
                'branch_name' => $branch?->name,
                'profile' => $profile,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        return ['rows' => $out, 'missing' => null];
    }

    /**
     * @return array{created: int, updated: int, accounts: list<array>, failed: list<array>}
     */
    public function import(array $preview, string $passwordHash, User $actor): array
    {
        $result = ['created' => 0, 'updated' => 0, 'accounts' => [], 'failed' => []];

        foreach ($preview['rows'] as $row) {
            if ($row['errors'] !== []) {
                continue;
            }
            try {
                DB::transaction(function () use ($row, $passwordHash, $actor, &$result) {
                    if ($row['action'] === 'update') {
                        $user = User::query()->lockForUpdate()->findOrFail($row['user_id']);
                        $blank = collect($row['profile'])->filter(fn ($v, $field) => filled($v) && blank($user->{$field}));
                        if ($blank->isNotEmpty()) {
                            $user->fill($blank->all())->save();
                            $result['updated']++;
                        }

                        return;
                    }

                    $user = retry(3, fn () => User::create([
                        ...collect($row['profile'])->only(self::PROFILE_FIELDS)->all(),
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'branch_id' => $row['branch_id'],
                        'password' => $passwordHash,
                        'must_change_password' => true,
                        'is_active' => true,
                        'created_by' => $actor->id,
                    ]), when: fn (\Throwable $e) => $e instanceof UniqueConstraintViolationException && ! User::where('email', $row['email'])->exists());
                    $user->syncRoles([$row['role']]);
                    $result['created']++;
                    $result['accounts'][] = ['name' => $user->name, 'employee_code' => $user->employee_code, 'email' => $user->email, 'role' => $row['role_label'], 'branch' => $row['branch_name']];
                });
            } catch (\Throwable $e) {
                report($e);
                $result['failed'][] = ['line' => $row['line'], 'name' => $row['name'], 'error' => $e instanceof UniqueConstraintViolationException ? 'Email đã được dùng bởi tài khoản khác.' : 'Lỗi khi ghi: '.Str::limit($e->getMessage(), 120)];
            }
        }

        return $result;
    }

    /** "Giáo viên" / "GV part" / "Trợ giảng" / "teacher_parttime"… → tên vai trò; ô trống → null. */
    private function role(mixed $value): ?string
    {
        $folded = self::fold((string) $value);
        if ($folded === '') {
            return null;
        }
        foreach ([...Roles::LABELS, ...Roles::SHORT_LABELS] as $role => $label) {
            if ($folded === $role || $folded === self::fold($label)) {
                return $role === Roles::STUDENT ? null : $role;
            }
        }

        return match (true) {
            str_contains($folded, 'tro giang') || str_contains($folded, 'assistant') => Roles::ASSISTANT,
            (str_contains($folded, 'giao vien') || preg_match('/\bgv\b|teacher/', $folded)) && str_contains($folded, 'full') => Roles::TEACHER_FULLTIME,
            str_contains($folded, 'giao vien') || (bool) preg_match('/\bgv\b|teacher/', $folded) => Roles::TEACHER_PARTTIME,
            default => null,
        };
    }

    private function canManage(User $actor, User $target, ?array $managed): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        return Rbac::isSubordinate($actor, $target)
            && ($managed === null || in_array((int) $target->branch_id, $managed, true))
            && (DataScope::level($actor, 'user') !== DataScope::OWN || (int) $target->created_by === (int) $actor->id);
    }

    private static function contractType(mixed $value): ?string
    {
        $folded = self::fold((string) $value);

        return match (true) {
            $folded === '' => null,
            str_contains($folded, 'full') || str_contains($folded, 'toan thoi gian') => 'Toàn thời gian',
            str_contains($folded, 'part') || str_contains($folded, 'ban thoi gian') => 'Bán thời gian',
            str_contains($folded, 'thu viec') => 'Thử việc',
            str_contains($folded, 'cong tac') || str_contains($folded, 'ctv') => 'Cộng tác viên',
            default => in_array(trim((string) $value), self::CONTRACT_TYPES, true) ? trim((string) $value) : null,
        };
    }

    private static function contractTypeFor(?string $role): ?string
    {
        return match ($role) {
            Roles::TEACHER_FULLTIME => 'Toàn thời gian',
            Roles::TEACHER_PARTTIME => 'Bán thời gian',
            Roles::ASSISTANT => 'Cộng tác viên',
            default => null,
        };
    }

    /** "Mẹ" + "0396988395" → "Mẹ - 0396988395"; tên đã ghi kèm số thì giữ nguyên. */
    private static function emergency(mixed $name, mixed $phone): ?string
    {
        $name = trim((string) $name);
        $phone = trim((string) $phone);
        if ($phone !== '' && str_contains(preg_replace('/\D/', '', $name), preg_replace('/\D/', '', $phone))) {
            $phone = '';
        }
        $value = trim(implode(' - ', array_filter([$name, $phone], fn ($v) => $v !== '')));

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }

    /** "Không", "không có", "KHÔNG" ở ô chứng chỉ → trống. */
    private static function certificates(mixed $value): ?string
    {
        return in_array(self::fold((string) $value), ['khong', 'khong co', 'no', 'none', '-'], true) ? null : self::text($value);
    }
}
