<?php

$baseUrl = 'http://localhost:8000';
$password = 'quel1234';

$users = [
    'Admin' => 'admin@menglish.edu.vn',
    'HocVu' => 'hocvu@menglish.edu.vn',
    'HocThuat' => 'hocthuat@menglish.edu.vn',
    'KeToan' => 'ketoan@menglish.edu.vn',
    'GiaoVien' => 'giaovien@menglish.edu.vn',
    'HocVien' => 'hocvien@menglish.edu.vn',
];

$tests = [
    // BƯỚC 0 — Admin setup
    ['url' => '/users', 'expected' => 200, 'roles' => ['Admin']],
    ['url' => '/users/create', 'expected' => 200, 'roles' => ['Admin']],
    ['url' => '/roles', 'expected' => 200, 'roles' => ['Admin']],
    ['url' => '/holidays', 'expected' => 200, 'roles' => ['Admin']],

    // BƯỚC 1 — CRM / Học vụ
    ['url' => '/classes/trial-booking', 'expected' => 200, 'roles' => ['HocVu']],
    ['url' => '/students', 'expected' => 200, 'roles' => ['HocVu']],
    ['url' => '/students/enrollments', 'expected' => 200, 'roles' => ['HocVu']],
    ['url' => '/classes/create', 'expected' => 200, 'roles' => ['HocVu']],
    ['url' => '/classes', 'expected' => 200, 'roles' => ['HocVu']],

    // BƯỚC 2 — Kế toán thu phí
    ['url' => '/tuition/students', 'expected' => 200, 'roles' => ['KeToan']],
    ['url' => '/tuition/receipts/create', 'expected' => 200, 'roles' => ['KeToan']],
    ['url' => '/tuition/receipts/approve', 'expected' => 200, 'roles' => ['KeToan']],

    // BƯỚC 3 — Học thuật syllabus
    ['url' => '/syllabus', 'expected' => 200, 'roles' => ['HocThuat']],
    ['url' => '/classes/academic-overview', 'expected' => 200, 'roles' => ['HocThuat']],
    ['url' => '/classes/academic-list', 'expected' => 200, 'roles' => ['HocThuat']],

    // BƯỚC 4 — Giáo viên
    ['url' => '/portal/teacher/submissions', 'expected' => 200, 'roles' => ['GiaoVien']],
    ['url' => '/payroll/timesheets/teachers', 'expected' => 200, 'roles' => ['GiaoVien']],
    ['url' => '/portal/ta-tasks', 'expected' => 200, 'roles' => ['GiaoVien']],

    // BƯỚC 5 — Học viên
    ['url' => '/portal/student/home', 'expected' => 200, 'roles' => ['HocVien']],
    ['url' => '/portal/student/homework', 'expected' => 200, 'roles' => ['HocVien']],
    ['url' => '/portal/student/pronunciation', 'expected' => 200, 'roles' => ['HocVien']],
    ['url' => '/portal/student/notifications', 'expected' => 200, 'roles' => ['HocVien']],
    ['url' => '/portal/student/survey', 'expected' => 200, 'roles' => ['HocVien']],
    ['url' => '/portal/student/feedback', 'expected' => 200, 'roles' => ['HocVien']],
    ['url' => '/portal/app-shell', 'expected' => 200, 'roles' => ['HocVien']],

    // BƯỚC 6 — Báo cáo
    ['url' => '/academic/reports', 'expected' => 200, 'roles' => ['Admin', 'HocThuat']],
    ['url' => '/academic/incidents', 'expected' => 200, 'roles' => ['Admin', 'HocThuat']],

    // BƯỚC 7 — Chấm công & lương
    ['url' => '/payroll/timesheets/manual', 'expected' => 200, 'roles' => ['HocVu']],
    ['url' => '/payroll/timesheets/teachers', 'expected' => 200, 'roles' => ['GiaoVien']],
    ['url' => '/payroll/periods', 'expected' => 200, 'roles' => ['Admin', 'KeToan']],
    ['url' => '/payroll/my-salary', 'expected' => 200, 'roles' => ['GiaoVien', 'HocVu']],

    // BƯỚC 8 — Admin tổng hợp
    ['url' => '/dashboard', 'expected' => 200, 'roles' => ['Admin']],
    ['url' => '/activity-logs', 'expected' => 200, 'roles' => ['Admin']],
    ['url' => '/recruitment', 'expected' => 200, 'roles' => ['Admin']],

    // PHÂN QUYỀN (test 403 or 302/404 for forbidden routes)
    ['url' => '/users', 'expected' => [403, 302, 404], 'roles' => ['HocVien', 'GiaoVien']],
    ['url' => '/payroll/periods', 'expected' => [403, 302, 404], 'roles' => ['HocVien', 'HocThuat']],
    ['url' => '/classes/create', 'expected' => [403, 302, 404], 'roles' => ['HocVien']],
];

function login($email, $password, $cookieFile) {
    global $baseUrl;
    
    // GET /login to get token
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . '/login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $html = curl_exec($ch);
    curl_close($ch);

    preg_match('/name="_token" value="(.*?)"/', $html, $matches);
    $token = $matches[1] ?? '';

    // POST /login
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . '/login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        '_token' => $token,
        'email' => $email,
        'password' => $password,
    ]));
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    return $info['http_code'] == 302; // Assuming redirect on success
}

function check_url($url, $cookieFile) {
    global $baseUrl;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    return $info['http_code'];
}

$results = [];
$passCount = 0;
$failCount = 0;

$cookieDir = __DIR__ . '/cookies';
if (!is_dir($cookieDir)) {
    mkdir($cookieDir, 0777, true);
}

foreach ($users as $roleName => $email) {
    echo "Testing Role: $roleName ($email)\n";
    $cookieFile = $cookieDir . "/cookie_$roleName.txt";
    if (file_exists($cookieFile)) unlink($cookieFile);
    
    $loggedIn = login($email, $password, $cookieFile);
    if (!$loggedIn) {
        echo "FAILED TO LOGIN $roleName\n";
        continue;
    }
    
    foreach ($tests as $test) {
        if (in_array($roleName, $test['roles'])) {
            $code = check_url($test['url'], $cookieFile);
            
            $expected = is_array($test['expected']) ? $test['expected'] : [$test['expected']];
            $passed = in_array($code, $expected);
            
            $status = $passed ? "PASS" : "FAIL";
            if ($passed) $passCount++; else $failCount++;
            
            $expectedStr = implode('|', $expected);
            echo "$status | $roleName | {$test['url']} | Expected: $expectedStr | Actual: $code\n";
            
            $results[] = [
                'role' => $roleName,
                'url' => $test['url'],
                'expected' => $expectedStr,
                'actual' => $code,
                'status' => $status
            ];
        }
    }
    echo "---------------------------\n";
}

echo "Tổng hợp:\n";
echo "PASS: $passCount\n";
echo "FAIL: $failCount\n";

file_put_contents(__DIR__ . '/test_results.json', json_encode($results, JSON_PRETTY_PRINT));
?>
