<?php

/**
 * Script Kiểm thử và Xác thực Toàn diện Live HTTP trên http://127.0.0.1:8000
 * Dành cho 6 vai trò và đối soát Prototype roundcuoi-kieulien
 */

$baseUrl = 'http://127.0.0.1:8000';
$cookieJarDir = sys_get_temp_dir() . '/menglish_test_cookies';
if (!is_dir($cookieJarDir)) {
    mkdir($cookieJarDir, 0777, true);
}

function makeRequest(string $method, string $url, array $data = [], ?string $cookieFile = null, bool $followRedirects = true): array
{
    $ch = curl_init();
    
    // Headers
    $headers = [
        'User-Agent: MEnglish-QA-Bot/1.0',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    ];

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    }

    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = curl_error($ch);

    $headerStr = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    return [
        'code' => $httpCode,
        'headers' => $headerStr,
        'body' => $body,
        'effective_url' => $effectiveUrl,
        'error' => $error,
    ];
}

function extractCsrfToken(string $html): ?string
{
    if (preg_match('/<input[^>]*name=["\']_token["\'][^>]*value=["\']([^"\']+)["\']/i', $html, $matches)) {
        return $matches[1];
    }
    if (preg_match('/<meta[^>]*name=["\']csrf-token["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $matches)) {
        return $matches[1];
    }
    return null;
}

function loginUser(string $baseUrl, string $email, string $password, string $cookieFile): bool
{
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }

    // 1. GET /login to get CSRF token & initial cookie
    $loginPage = makeRequest('GET', "{$baseUrl}/login", [], $cookieFile);
    $token = extractCsrfToken($loginPage['body']);

    if (!$token) {
        echo "  [FAIL] Không thể lấy CSRF token từ trang login cho {$email}\n";
        return false;
    }

    // 2. POST /login
    $res = makeRequest('POST', "{$baseUrl}/login", [
        '_token' => $token,
        'email' => $email,
        'password' => $password,
    ], $cookieFile, false);

    // Redirect to dashboard means login succeeded (not redirected back to /login)
    if ($res['code'] === 302 && preg_match('/Location:\s*([^\r\n]+)/i', $res['headers'], $locMatches)) {
        $location = trim($locMatches[1]);
        if (str_contains($location, '/dashboard') || rtrim($location, '/') === $baseUrl) {
            return true;
        }
    }

    return false;
}

echo "=========================================================================\n";
echo "   BẮT ĐẦU BỘ KIỂM THỬ XÁC THỰC TOÀN DIỆN LIVE HTTP (http://127.0.0.1:8000)\n";
echo "=========================================================================\n\n";

$rolesConfig = [
    'admin' => [
        'name' => 'Admin (Quản trị viên)',
        'emails' => ['admin@menglish.edu.vn', 'lequelcm@gmail.com'],
        'password' => 'quel1234',
    ],
    'academic_staff' => [
        'name' => 'Học vụ (academic_staff)',
        'emails' => ['hocvu@menglish.edu.vn'],
        'password' => 'quel1234',
    ],
    'academic_lead' => [
        'name' => 'Học thuật (academic_lead)',
        'emails' => ['hocthuat@menglish.edu.vn'],
        'password' => 'quel1234',
    ],
    'accountant' => [
        'name' => 'Kế toán (accountant)',
        'emails' => ['ketoan@menglish.edu.vn'],
        'password' => 'quel1234',
    ],
    'teacher' => [
        'name' => 'Giáo viên (teacher)',
        'emails' => ['giaovien@menglish.edu.vn'],
        'password' => 'quel1234',
    ],
    'student' => [
        'name' => 'Học sinh (student)',
        'emails' => ['hocvien@menglish.edu.vn'],
        'password' => 'quel1234',
    ],
];

$testResults = [
    'auth' => [],
    'routes' => [],
    'hierarchy' => [],
    'prototypes' => [],
];

// ─────────────────────────────────────────────────────────────
// PHẦN 1: KIỂM THỬ ĐĂNG NHẬP (AUTHENTICATION)
// ─────────────────────────────────────────────────────────────
echo "1. KIỂM THỬ ĐĂNG NHẬP 6 VAI TRÒ (7 TÀI KHOẢN):\n";
echo "--------------------------------------------------\n";

foreach ($rolesConfig as $roleKey => $info) {
    foreach ($info['emails'] as $email) {
        $cookieFile = "{$cookieJarDir}/{$roleKey}_{$email}.cookie";
        $ok = loginUser($baseUrl, $email, $info['password'], $cookieFile);
        if ($ok) {
            echo "  [PASS] {$info['name']}: {$email} -> Đăng nhập thành công!\n";
            $testResults['auth'][$email] = 'PASS';
        } else {
            echo "  [FAIL] {$info['name']}: {$email} -> Đăng nhập thất bại!\n";
            $testResults['auth'][$email] = 'FAIL';
        }
    }
}

// Thử đăng nhập sai pass
$badCookie = "{$cookieJarDir}/bad_login.cookie";
$badLogin = loginUser($baseUrl, 'admin@menglish.edu.vn', 'SaiPassNe!', $badCookie);
if (!$badLogin) {
    echo "  [PASS] Đăng nhập mật khẩu sai bị chặn chính xác!\n";
    $testResults['auth']['bad_password_blocked'] = 'PASS';
} else {
    echo "  [FAIL] Đăng nhập mật khẩu sai nhưng vẫn lọt!\n";
    $testResults['auth']['bad_password_blocked'] = 'FAIL';
}
echo "\n";

// ─────────────────────────────────────────────────────────────
// PHẦN 2: KIỂM THỬ PHÂN QUYỀN ROUTE (200 OK vs 403 Forbidden)
// ─────────────────────────────────────────────────────────────
echo "2. KIỂM THỬ PHÂN QUYỀN TRUY CẬP ROUTE ĐẶC TRƯNG:\n";
echo "--------------------------------------------------\n";

// a. Admin
$adminCookie = "{$cookieJarDir}/admin_admin@menglish.edu.vn.cookie";
$adminRoutes = [
    '/dashboard' => 200,
    '/users' => 200,
    '/users/create' => 200,
    '/roles' => 200,
    '/permissions' => 200,
    '/branches' => 200,
    '/classes' => 200,
    '/classes/create' => 200,
    '/tuition/students' => 200,
    '/finance/reports/provisional-revenue' => 200,
    '/finance/expenses' => 200,
    '/syllabus/builder' => 200,
    '/academic-system' => 200,
    '/mockup-hub' => 200,
];

echo "  -> Kiểm tra quyền Admin:\n";
foreach ($adminRoutes as $path => $expectedCode) {
    $res = makeRequest('GET', "{$baseUrl}{$path}", [], $adminCookie, false);
    $status = ($res['code'] === $expectedCode) ? 'PASS' : 'FAIL';
    echo "     [{$status}] Admin GET {$path} -> HTTP {$res['code']} (kỳ vọng {$expectedCode})\n";
    $testResults['routes']["admin_{$path}"] = $status;
}

// b. Kế toán (accountant)
$accountantCookie = "{$cookieJarDir}/accountant_ketoan@menglish.edu.vn.cookie";
$accountantRoutes = [
    '/tuition/students' => 200,
    '/tuition/receipts/create' => 200,
    '/tuition/history' => 200,
    '/tuition/overdue' => 200,
    '/finance/reports/provisional-revenue' => 200,
    '/finance/expenses' => 200,
    '/academic-system/01_Web_Admin/10_doi_soat_chot_bang_cong' => 200,
    '/users/create' => 403, // Bị cấm
    '/classes/create' => 403, // Bị cấm
];

echo "\n  -> Kiểm tra quyền Kế toán:\n";
foreach ($accountantRoutes as $path => $expectedCode) {
    $res = makeRequest('GET', "{$baseUrl}{$path}", [], $accountantCookie, false);
    $status = ($res['code'] === $expectedCode) ? 'PASS' : 'FAIL';
    echo "     [{$status}] Kế toán GET {$path} -> HTTP {$res['code']} (kỳ vọng {$expectedCode})\n";
    $testResults['routes']["accountant_{$path}"] = $status;
}

// c. Giáo viên (teacher)
$teacherCookie = "{$cookieJarDir}/teacher_giaovien@menglish.edu.vn.cookie";
$teacherRoutes = [
    '/portal/my-salary' => 200,
    '/portal/teacher-submissions' => 200,
    '/portal/teacher/submissions' => 200,
    '/academic-system/03_Cong_Giao_Vien/15_check_in_cua_toi' => 200,
    '/academic-system/03_Cong_Giao_Vien/02_diem_danh_lop_giao_vien' => 200,
    '/academic-system/03_Cong_Giao_Vien/04_bai_nop_cua_lop' => 200,
    '/payroll/timesheets/teachers' => 200,
    '/users/create' => 403, // Bị cấm
    '/classes/create' => 403, // Bị cấm
];

echo "\n  -> Kiểm tra quyền Giáo viên:\n";
foreach ($teacherRoutes as $path => $expectedCode) {
    $res = makeRequest('GET', "{$baseUrl}{$path}", [], $teacherCookie, false);
    $status = ($res['code'] === $expectedCode) ? 'PASS' : 'FAIL';
    echo "     [{$status}] Giáo viên GET {$path} -> HTTP {$res['code']} (kỳ vọng {$expectedCode})\n";
    $testResults['routes']["teacher_{$path}"] = $status;
}

// d. Học sinh (student)
$studentCookie = "{$cookieJarDir}/student_hocvien@menglish.edu.vn.cookie";
$studentRoutes = [
    '/portal/app-shell' => 200,
    '/portal/home' => 200,
    '/portal/student-homework' => 200,
    '/portal/pronunciation' => 200,
    '/portal/notifications' => 200,
    '/portal/survey' => 200,
    '/portal/feedback' => 200,
    '/users/create' => 403, // Bị cấm
    '/classes/create' => 403, // Bị cấm
];

echo "\n  -> Kiểm tra quyền Học sinh:\n";
foreach ($studentRoutes as $path => $expectedCode) {
    $res = makeRequest('GET', "{$baseUrl}{$path}", [], $studentCookie, false);
    $status = ($res['code'] === $expectedCode) ? 'PASS' : 'FAIL';
    echo "     [{$status}] Học sinh GET {$path} -> HTTP {$res['code']} (kỳ vọng {$expectedCode})\n";
    $testResults['routes']["student_{$path}"] = $status;
}

// e. Học vụ (academic_staff)
$staffCookie = "{$cookieJarDir}/academic_staff_hocvu@menglish.edu.vn.cookie";
$staffRoutes = [
    '/users' => 200,
    '/users/create' => 200,
    '/classes' => 200,
    '/classes/create' => 200,
    '/payroll/timesheets/manual' => 200,
    '/academic-system/02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/06_bao_cao_ngay_hoc_vu' => 200,
    '/academic-system/02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/05_nhat_ky_hoc_vu' => 200,
];

echo "\n  -> Kiểm tra quyền Học vụ:\n";
foreach ($staffRoutes as $path => $expectedCode) {
    $res = makeRequest('GET', "{$baseUrl}{$path}", [], $staffCookie, false);
    $status = ($res['code'] === $expectedCode) ? 'PASS' : 'FAIL';
    echo "     [{$status}] Học vụ GET {$path} -> HTTP {$res['code']} (kỳ vọng {$expectedCode})\n";
    $testResults['routes']["staff_{$path}"] = $status;
}

// f. Học thuật (academic_lead)
$leadCookie = "{$cookieJarDir}/academic_lead_hocthuat@menglish.edu.vn.cookie";
$leadRoutes = [
    '/syllabus/builder' => 200,
    '/syllabus/assignments' => 200,
    '/syllabus/versions' => 200,
    '/syllabus/big-tests/distribution' => 200,
    '/syllabus/big-tests/results' => 200,
    '/classes/academic-overview' => 200,
    '/classes/academic-list' => 200,
    '/academic-system/01_Web_Admin/02_soan_syllabus_theo_chang' => 200,
    '/academic-system/01_Web_Admin/03_giao_chang_cho_giao_vien' => 200,
    '/academic-system/01_Web_Admin/05_chi_tiet_de_xuat_sua_giao_trinh' => 200,
    '/academic-system/01_Web_Admin/06_duyet_phan_phoi_de_big_test' => 200,
];

echo "\n  -> Kiểm tra quyền Học thuật:\n";
foreach ($leadRoutes as $path => $expectedCode) {
    $res = makeRequest('GET', "{$baseUrl}{$path}", [], $leadCookie, false);
    $status = ($res['code'] === $expectedCode) ? 'PASS' : 'FAIL';
    echo "     [{$status}] Học thuật GET {$path} -> HTTP {$res['code']} (kỳ vọng {$expectedCode})\n";
    $testResults['routes']["lead_{$path}"] = $status;
}
echo "\n";

// ─────────────────────────────────────────────────────────────
// PHẦN 3: KIỂM TRA PHÂN CẤP TẠO TÀI KHOẢN TRÊN LIVE SERVER
// ─────────────────────────────────────────────────────────────
echo "3. KIỂM TRA PHÂN CẤP TẠO TÀI KHOẢN (USER CREATION HIERARCHY):\n";
echo "----------------------------------------------------------------\n";

// 1. Học thuật (academic_lead):
// Thử tạo teacher -> Thành công (302 redirect /users)
// Thử tạo academic_staff hoặc admin -> Thất bại (302 redirect back kèm session error)
$leadCreatePage = makeRequest('GET', "{$baseUrl}/users/create", [], $leadCookie);
$tokenLead = extractCsrfToken($leadCreatePage['body']);

// a) Tạo role teacher (Hợp lệ)
$testTeacherEmail = 'test_live_gv_' . time() . '@menglish.edu.vn';
$resLeadCreateTeacher = makeRequest('POST', "{$baseUrl}/users", [
    '_token' => $tokenLead,
    'name' => 'Test Live Teacher',
    'email' => $testTeacherEmail,
    'role' => 'teacher',
    'password' => 'quel1234',
], $leadCookie, false);

if ($resLeadCreateTeacher['code'] === 302 && str_contains($resLeadCreateTeacher['headers'], '/users')) {
    echo "  [PASS] Học thuật tạo vai trò 'teacher' thành công (Redirect về /users)\n";
    $testResults['hierarchy']['lead_create_teacher'] = 'PASS';
} else {
    echo "  [FAIL] Học thuật tạo vai trò 'teacher' thất bại! HTTP {$resLeadCreateTeacher['code']}\n";
    $testResults['hierarchy']['lead_create_teacher'] = 'FAIL';
}

// b) Thử tạo role academic_staff (Bị cấm)
$illegalStaffEmail = 'test_illegal_staff_' . time() . '@menglish.edu.vn';
$resLeadCreateStaff = makeRequest('POST', "{$baseUrl}/users", [
    '_token' => $tokenLead,
    'name' => 'Illegal Staff',
    'email' => $illegalStaffEmail,
    'role' => 'academic_staff',
    'password' => 'quel1234',
], $leadCookie, false);

// Không redirect đến /users mà redirect back về /users/create do validation error
if ($resLeadCreateStaff['code'] === 302 && !str_contains($resLeadCreateStaff['headers'], 'Location: http://127.0.0.1:8000/users\r\n')) {
    echo "  [PASS] Học thuật tạo 'academic_staff' bị từ chối chính xác với lỗi validation!\n";
    $testResults['hierarchy']['lead_create_forbidden_role'] = 'PASS';
} else {
    echo "  [FAIL] Học thuật tạo 'academic_staff' không bị chặn đúng cách!\n";
    $testResults['hierarchy']['lead_create_forbidden_role'] = 'FAIL';
}

// 2. Học vụ (academic_staff):
$staffCreatePage = makeRequest('GET', "{$baseUrl}/users/create", [], $staffCookie);
$tokenStaff = extractCsrfToken($staffCreatePage['body']);

// a) Tạo role assistant (Hợp lệ)
$testTaEmail = 'test_live_ta_' . time() . '@menglish.edu.vn';
$resStaffCreateTa = makeRequest('POST', "{$baseUrl}/users", [
    '_token' => $tokenStaff,
    'name' => 'Test Live TA',
    'email' => $testTaEmail,
    'role' => 'assistant',
    'password' => 'quel1234',
], $staffCookie, false);

if ($resStaffCreateTa['code'] === 302 && str_contains($resStaffCreateTa['headers'], '/users')) {
    echo "  [PASS] Học vụ tạo vai trò 'assistant' thành công (Redirect về /users)\n";
    $testResults['hierarchy']['staff_create_assistant'] = 'PASS';
} else {
    echo "  [FAIL] Học vụ tạo vai trò 'assistant' thất bại! HTTP {$resStaffCreateTa['code']}\n";
    $testResults['hierarchy']['staff_create_assistant'] = 'FAIL';
}

// b) Thử tạo role admin hoặc academic_lead (Bị cấm)
$illegalAdminEmail = 'test_illegal_admin_' . time() . '@menglish.edu.vn';
$resStaffCreateAdmin = makeRequest('POST', "{$baseUrl}/users", [
    '_token' => $tokenStaff,
    'name' => 'Illegal Admin',
    'email' => $illegalAdminEmail,
    'role' => 'admin',
    'password' => 'quel1234',
], $staffCookie, false);

if ($resStaffCreateAdmin['code'] === 302 && !str_contains($resStaffCreateAdmin['headers'], 'Location: http://127.0.0.1:8000/users\r\n')) {
    echo "  [PASS] Học vụ tạo 'admin' bị từ chối chính xác với lỗi validation!\n";
    $testResults['hierarchy']['staff_create_forbidden_role'] = 'PASS';
} else {
    echo "  [FAIL] Học vụ tạo 'admin' không bị chặn đúng cách!\n";
    $testResults['hierarchy']['staff_create_forbidden_role'] = 'FAIL';
}
echo "\n";

// ─────────────────────────────────────────────────────────────
// PHẦN 4: ĐỐI SOÁT CÁC MÀN HÌNH HIỂN THỊ VỚI PROTOTYPE ROUNDCUOI-KIEULIEN
// ─────────────────────────────────────────────────────────────
echo "4. ĐỐI SOÁT MÀN HÌNH VỚI BỘ PROTOTYPE ROUNDCUOI-KIEULIEN:\n";
echo "-----------------------------------------------------------\n";

// a. Giáo viên:
$checkinPage = makeRequest('GET', "{$baseUrl}/academic-system/03_Cong_Giao_Vien/15_check_in_cua_toi", [], $teacherCookie);
$hasCheckin = str_contains($checkinPage['body'], 'Check-in') && str_contains($checkinPage['body'], 'menglish-real-data-engine.js');
echo "  [ " . ($hasCheckin ? 'PASS' : 'FAIL') . " ] Giáo viên: Màn check-in ca dạy nhiều ca (03_Cong_Giao_Vien/15_check_in_cua_toi) có Data Engine\n";
$testResults['prototypes']['teacher_checkin'] = $hasCheckin ? 'PASS' : 'FAIL';

$attendancePage = makeRequest('GET', "{$baseUrl}/academic-system/03_Cong_Giao_Vien/02_diem_danh_lop_giao_vien", [], $teacherCookie);
$hasAttendance = str_contains($attendancePage['body'], 'Điểm danh');
echo "  [ " . ($hasAttendance ? 'PASS' : 'FAIL') . " ] Giáo viên: Cổng điểm danh lớp (03_Cong_Giao_Vien/02_diem_danh_lop_giao_vien)\n";
$testResults['prototypes']['teacher_attendance'] = $hasAttendance ? 'PASS' : 'FAIL';

$submPage = makeRequest('GET', "{$baseUrl}/portal/teacher/submissions", [], $teacherCookie);
$hasSubm = str_contains($submPage['body'], 'Chấm bài') || str_contains($submPage['body'], 'bài nộp');
echo "  [ " . ($hasSubm ? 'PASS' : 'FAIL') . " ] Giáo viên: Chấm bài nộp (/portal/teacher/submissions)\n";
$testResults['prototypes']['teacher_submissions'] = $hasSubm ? 'PASS' : 'FAIL';

$salaryPage = makeRequest('GET', "{$baseUrl}/portal/my-salary", [], $teacherCookie);
$hasSalary = str_contains($salaryPage['body'], 'Lương') || str_contains($salaryPage['body'], 'Thu nhập');
echo "  [ " . ($hasSalary ? 'PASS' : 'FAIL') . " ] Giáo viên: Xem lương cá nhân (/portal/my-salary)\n";
$testResults['prototypes']['teacher_salary'] = $hasSalary ? 'PASS' : 'FAIL';

// b. Học sinh:
$studentDashboard = makeRequest('GET', "{$baseUrl}/dashboard", [], $studentCookie);
$menuHidden = !str_contains($studentDashboard['body'], 'CRM & Tuyển sinh')
    && !str_contains($studentDashboard['body'], 'Nhân sự & Vận hành')
    && !str_contains($studentDashboard['body'], 'Học phí & Hoá đơn')
    && !str_contains($studentDashboard['body'], 'Phân quyền & Hệ thống');
echo "  [ " . ($menuHidden ? 'PASS' : 'FAIL') . " ] Học sinh: Menu quản trị nội bộ (CRM, HR, Phí, Phân quyền) được ẩn hoàn toàn\n";
$testResults['prototypes']['student_menu_hidden'] = $menuHidden ? 'PASS' : 'FAIL';

$studentScreens = [
    'App Shell' => '/portal/app-shell',
    'Trang chủ (Home)' => '/portal/home',
    'Nộp bài tập' => '/portal/student-homework',
    'Luyện phát âm AI' => '/portal/pronunciation',
    'Thông báo' => '/portal/notifications',
    'Khảo sát' => '/portal/survey',
    'Feedback' => '/portal/feedback',
];

foreach ($studentScreens as $label => $path) {
    $sp = makeRequest('GET', "{$baseUrl}{$path}", [], $studentCookie);
    $ok = ($sp['code'] === 200);
    echo "  [ " . ($ok ? 'PASS' : 'FAIL') . " ] Học sinh: 7 màn hình Cổng PH/HS - {$label} ({$path})\n";
    $testResults['prototypes']["student_{$path}"] = $ok ? 'PASS' : 'FAIL';
}

// c. Học vụ:
$staffDayReport = makeRequest('GET', "{$baseUrl}/academic-system/02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/06_bao_cao_ngay_hoc_vu", [], $staffCookie);
$hasDayReport = str_contains($staffDayReport['body'], 'Báo cáo ngày');
echo "  [ " . ($hasDayReport ? 'PASS' : 'FAIL') . " ] Học vụ: Màn Báo cáo ngày (02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/06_bao_cao_ngay_hoc_vu)\n";
$testResults['prototypes']['staff_day_report'] = $hasDayReport ? 'PASS' : 'FAIL';

$staffIncident = makeRequest('GET', "{$baseUrl}/academic-system/02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/05_nhat_ky_hoc_vu", [], $staffCookie);
$hasIncident = str_contains($staffIncident['body'], 'Nhật ký');
echo "  [ " . ($hasIncident ? 'PASS' : 'FAIL') . " ] Học vụ: Nhật ký sự vụ (02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/05_nhat_ky_hoc_vu)\n";
$testResults['prototypes']['staff_incident'] = $hasIncident ? 'PASS' : 'FAIL';

$staffTimesheet = makeRequest('GET', "{$baseUrl}/payroll/timesheets/manual", [], $staffCookie);
$hasTimesheet = str_contains($staffTimesheet['body'], 'Chấm công');
echo "  [ " . ($hasTimesheet ? 'PASS' : 'FAIL') . " ] Học vụ: Chấm công cho GV/TA (/payroll/timesheets/manual)\n";
$testResults['prototypes']['staff_manual_timesheet'] = $hasTimesheet ? 'PASS' : 'FAIL';

// d. Kế toán:
$accReview = makeRequest('GET', "{$baseUrl}/academic-system/01_Web_Admin/10_doi_soat_chot_bang_cong", [], $accountantCookie);
$hasAccReview = str_contains($accReview['body'], 'bảng công');
echo "  [ " . ($hasAccReview ? 'PASS' : 'FAIL') . " ] Kế toán: Màn Chốt bảng công (01_Web_Admin/10_doi_soat_chot_bang_cong)\n";
$testResults['prototypes']['acc_payroll_review'] = $hasAccReview ? 'PASS' : 'FAIL';

$accTuition = makeRequest('GET', "{$baseUrl}/tuition/students", [], $accountantCookie);
$hasAccTuition = str_contains($accTuition['body'], 'Học viên') || str_contains($accTuition['body'], 'Học phí');
echo "  [ " . ($hasAccTuition ? 'PASS' : 'FAIL') . " ] Kế toán: Màn Thu phí (/tuition/students)\n";
$testResults['prototypes']['acc_tuition'] = $hasAccTuition ? 'PASS' : 'FAIL';

$accReceipt = makeRequest('GET', "{$baseUrl}/tuition/receipts/create", [], $accountantCookie);
$hasAccReceipt = str_contains($accReceipt['body'], 'phiếu thu') || str_contains($accReceipt['body'], 'Phiếu thu');
echo "  [ " . ($hasAccReceipt ? 'PASS' : 'FAIL') . " ] Kế toán: Lập phiếu thu VietQR chuẩn CRM (/tuition/receipts/create)\n";
$testResults['prototypes']['acc_receipt'] = $hasAccReceipt ? 'PASS' : 'FAIL';

$accRev = makeRequest('GET', "{$baseUrl}/finance/reports/provisional-revenue", [], $accountantCookie);
$hasAccRev = str_contains($accRev['body'], 'Doanh thu') || str_contains($accRev['body'], 'Báo cáo');
echo "  [ " . ($hasAccRev ? 'PASS' : 'FAIL') . " ] Kế toán: Báo cáo Doanh thu (/finance/reports/provisional-revenue)\n";
$testResults['prototypes']['acc_revenue'] = $hasAccRev ? 'PASS' : 'FAIL';

$accExp = makeRequest('GET', "{$baseUrl}/finance/expenses", [], $accountantCookie);
$hasAccExp = str_contains($accExp['body'], 'khoản chi') || str_contains($accExp['body'], 'Khoản chi');
echo "  [ " . ($hasAccExp ? 'PASS' : 'FAIL') . " ] Kế toán: Báo cáo Chi phí (/finance/expenses)\n";
$testResults['prototypes']['acc_expenses'] = $hasAccExp ? 'PASS' : 'FAIL';

// e. Học thuật:
$leadSyllabus = makeRequest('GET', "{$baseUrl}/academic-system/01_Web_Admin/02_soan_syllabus_theo_chang", [], $leadCookie);
$hasLeadSyllabus = str_contains($leadSyllabus['body'], 'syllabus') || str_contains($leadSyllabus['body'], 'Syllabus');
echo "  [ " . ($hasLeadSyllabus ? 'PASS' : 'FAIL') . " ] Học thuật: Soạn Syllabus chặng (01_Web_Admin/02_soan_syllabus_theo_chang)\n";
$testResults['prototypes']['lead_syllabus'] = $hasLeadSyllabus ? 'PASS' : 'FAIL';

$leadAssign = makeRequest('GET', "{$baseUrl}/academic-system/01_Web_Admin/03_giao_chang_cho_giao_vien", [], $leadCookie);
$hasLeadAssign = str_contains($leadAssign['body'], 'Giao chặng') || str_contains($leadAssign['body'], 'chặng');
echo "  [ " . ($hasLeadAssign ? 'PASS' : 'FAIL') . " ] Học thuật: Giao chặng GV (01_Web_Admin/03_giao_chang_cho_giao_vien)\n";
$testResults['prototypes']['lead_assign'] = $hasLeadAssign ? 'PASS' : 'FAIL';

$leadVersions = makeRequest('GET', "{$baseUrl}/academic-system/01_Web_Admin/05_chi_tiet_de_xuat_sua_giao_trinh", [], $leadCookie);
$hasLeadVersions = str_contains($leadVersions['body'], 'giáo trình') || str_contains($leadVersions['body'], 'Giáo trình');
echo "  [ " . ($hasLeadVersions ? 'PASS' : 'FAIL') . " ] Học thuật: Duyệt sửa GT (01_Web_Admin/05_chi_tiet_de_xuat_sua_giao_trinh)\n";
$testResults['prototypes']['lead_versions'] = $hasLeadVersions ? 'PASS' : 'FAIL';

$leadBigTest = makeRequest('GET', "{$baseUrl}/academic-system/01_Web_Admin/06_duyet_phan_phoi_de_big_test", [], $leadCookie);
$hasLeadBigTest = str_contains($leadBigTest['body'], 'Big Test') || str_contains($leadBigTest['body'], 'big test');
echo "  [ " . ($hasLeadBigTest ? 'PASS' : 'FAIL') . " ] Học thuật: Duyệt Big Test (01_Web_Admin/06_duyet_phan_phoi_de_big_test)\n";
$testResults['prototypes']['lead_bigtest'] = $hasLeadBigTest ? 'PASS' : 'FAIL';

$leadOverview = makeRequest('GET', "{$baseUrl}/academic-system/02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/13_tong_quan_danh_sach_lop_hoc_thuat", [], $leadCookie);
$hasLeadOverview = str_contains($leadOverview['body'], 'Danh sách lớp') || str_contains($leadOverview['body'], 'Học thuật');
echo "  [ " . ($hasLeadOverview ? 'PASS' : 'FAIL') . " ] Học thuật: Sơ đồ khối lớp (02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/13_tong_quan_danh_sach_lop_hoc_thuat)\n";
$testResults['prototypes']['lead_overview'] = $hasLeadOverview ? 'PASS' : 'FAIL';

echo "\n=========================================================================\n";
echo "   TỔNG KẾT TOÀN BỘ KIỂM THỬ XÁC THỰC TRÊN LIVE HTTP:\n";

$totalPass = 0;
$totalFail = 0;
foreach ($testResults as $group => $items) {
    foreach ($items as $k => $v) {
        if ($v === 'PASS') $totalPass++;
        else $totalFail++;
    }
}

echo "   - TỔNG SỐ CHECKPOINTS: " . ($totalPass + $totalFail) . "\n";
echo "   - PASSED: {$totalPass}\n";
echo "   - FAILED: {$totalFail}\n";
echo "=========================================================================\n";

// Cleanup cookies
array_map('unlink', glob("{$cookieJarDir}/*"));
@rmdir($cookieJarDir);

exit($totalFail > 0 ? 1 : 0);
