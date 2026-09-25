<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds. Tạo 1 tài khoản mẫu cho mỗi role để phục vụ
     * test 401/403 theo từng vai trò (không chỉ test bằng admin). Idempotent.
     *
     * Mật khẩu mặc định lấy từ SEED_DEFAULT_PASSWORD (.env), fallback
     * "Password123!" cho môi trường local/testing. Không dùng giá trị này
     * cho production; ProductionSeeder phải yêu cầu đổi mật khẩu ngay sau khi tạo.
     */
    public function run(): void
    {
        $password = Hash::make(config('access.seed_password'));

        $branchByCode = Branch::query()->pluck('id', 'code');

        $users = [
            // ============ BAN GIÁM ĐỐC & QUẢN LÝ ============
            [
                'employee_code' => 'ME-0001',
                'name' => 'Admin Hệ thống',
                'email' => 'admin@menglish.edu.vn',
                'phone' => '0900000001',
                'branch' => 'CG',
                'role' => 'admin',
            ],
            [
                'employee_code' => 'ME-0002',
                'name' => 'Lê Hoàng Nam',
                'email' => 'manager@menglish.edu.vn',
                'phone' => '0900000002',
                'branch' => 'CG',
                'role' => 'manager',
            ],
            [
                'employee_code' => 'ME-0008',
                'name' => 'Phạm Thu Hà',
                'email' => 'manager.bd@menglish.edu.vn',
                'phone' => '0900000009',
                'branch' => 'BD',
                'role' => 'manager',
            ],

            // ============ KẾ TOÁN ============
            [
                'employee_code' => 'ME-0003',
                'name' => 'Trần Thị B',
                'email' => 'ttb@menglish.edu.vn',
                'phone' => '0900000003',
                'branch' => 'BD',
                'role' => 'accountant',
            ],
            [
                'employee_code' => 'ME-0009',
                'name' => 'Ngô Thanh Tú',
                'email' => 'ketoan2@menglish.edu.vn',
                'phone' => '0900000010',
                'branch' => 'CG',
                'role' => 'accountant',
            ],

            // ============ HỌC THUẬT & HỌC VỤ ============
            [
                'employee_code' => 'ME-0010',
                'name' => 'Đặng Hồng Nhung',
                'email' => 'academiclead@menglish.edu.vn',
                'phone' => '0900000011',
                'branch' => 'CG',
                'role' => 'academic_lead',
            ],
            [
                'employee_code' => 'ME-0004',
                'name' => 'Nguyễn Văn A',
                'email' => 'nva@menglish.edu.vn',
                'phone' => '0900000004',
                'branch' => 'CG',
                'role' => 'academic_staff',
            ],
            [
                'employee_code' => 'ME-0011',
                'name' => 'Bùi Khánh Chi',
                'email' => 'giaovu2@menglish.edu.vn',
                'phone' => '0900000012',
                'branch' => 'BD',
                'role' => 'academic_staff',
            ],

            // ============ TƯ VẤN TUYỂN SINH (SALES) ============
            [
                'employee_code' => 'ME-0005',
                'name' => 'Lê Văn Vũ',
                'email' => 'levanvu@menglish.edu.vn',
                'phone' => '0900000005',
                'branch' => 'DD',
                'role' => 'sales_consultant',
            ],
            [
                'employee_code' => 'ME-0006',
                'name' => 'Trần Thị Mai',
                'email' => 'tranmaia@menglish.edu.vn',
                'phone' => '0900000007',
                'branch' => 'CG',
                'role' => 'sales_consultant',
            ],
            [
                'employee_code' => 'ME-0007',
                'name' => 'Hoàng Đức Thịnh',
                'email' => 'hoangthinh@menglish.edu.vn',
                'phone' => '0900000008',
                'branch' => 'BD',
                'role' => 'sales_consultant',
            ],

            // ============ GIÁO VIÊN ============
            [
                'employee_code' => 'GV-0492',
                'name' => 'Nguyễn Văn An',
                'email' => 'nguyenvanan@menglish.edu.vn',
                'phone' => '0900000006',
                'branch' => 'CG',
                'role' => 'teacher',
            ],
            [
                'employee_code' => 'GV-0501',
                'name' => 'ThS. Nguyễn Quốc Anh',
                'email' => 'gv.cohuu1@menglish.edu.vn',
                'phone' => '0900000013',
                'branch' => 'CG',
                'role' => 'teacher_fulltime',
            ],
            [
                'employee_code' => 'GV-0502',
                'name' => 'Trần Bảo Ngọc',
                'email' => 'gv.cohuu2@menglish.edu.vn',
                'phone' => '0900000014',
                'branch' => 'BD',
                'role' => 'teacher_fulltime',
            ],
            [
                'employee_code' => 'GV-0503',
                'name' => 'Lê Minh Khôi',
                'email' => 'gv.banthoigian1@menglish.edu.vn',
                'phone' => '0900000015',
                'branch' => 'DD',
                'role' => 'teacher_parttime',
            ],
            [
                'employee_code' => 'GV-0504',
                'name' => 'Sarah Johnson (GVNN)',
                'email' => 'gv.native1@menglish.edu.vn',
                'phone' => '0900000016',
                'branch' => 'CG',
                'role' => 'teacher_parttime',
            ],

            // ============ TRỢ GIẢNG (TA) ============
            [
                'employee_code' => 'TA-0001',
                'name' => 'Trần Anh Tuấn',
                'email' => 'ta.tuan@menglish.edu.vn',
                'phone' => '0912000001',
                'branch' => 'CG',
                'role' => 'assistant',
            ],
            [
                'employee_code' => 'TA-0002',
                'name' => 'Mai Ngọc Trâm',
                'email' => 'ta.tram@menglish.edu.vn',
                'phone' => '0912000002',
                'branch' => 'CG',
                'role' => 'assistant',
            ],
            [
                'employee_code' => 'TA-0003',
                'name' => 'Lê Hải Yến',
                'email' => 'ta.yen@menglish.edu.vn',
                'phone' => '0912000003',
                'branch' => 'BD',
                'role' => 'assistant',
            ],

            // ============ HỌC VIÊN (PORTAL) ============
            [
                'employee_code' => 'HV-0001',
                'name' => 'Nguyễn Minh Anh',
                'email' => 'hocvien1@menglish.edu.vn',
                'phone' => '0988123456',
                'branch' => 'CG',
                'role' => 'student',
            ],
            [
                'employee_code' => 'HV-0002',
                'name' => 'Vũ Thị Minh Hằng',
                'email' => 'hocvien2@menglish.edu.vn',
                'phone' => '0983234567',
                'branch' => 'CG',
                'role' => 'student',
            ],
            [
                'employee_code' => 'HV-0003',
                'name' => 'Nguyễn Đình Trọng',
                'email' => 'hocvien3@menglish.edu.vn',
                'phone' => '0912345678',
                'branch' => 'BD',
                'role' => 'student',
            ],
        ];

        foreach ($users as $data) {
            $role = $data['role'];
            $branchCode = $data['branch'];
            unset($data['role'], $data['branch']);

            $user = User::withTrashed()->where('email', $data['email'])->orWhere('employee_code', $data['employee_code'])->first();
            if ($user && $user->trashed()) {
                $user->restore();
            }
            if (!$user) {
                $user = new User();
            }

            $user->fill($data + [
                'branch_id' => $branchByCode[$branchCode] ?? null,
                'password' => $password,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $user->save();

            $user->syncRoles([$role]);
        }
    }
}
