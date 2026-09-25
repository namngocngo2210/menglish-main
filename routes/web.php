<?php

use App\Http\Controllers\AcademicDashboardController;
use App\Http\Controllers\AcademicSystemController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\ClassManagementController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\CourseLevelController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\KpiController;
use App\Http\Controllers\MediaManagerController;
use App\Http\Controllers\MerchandiseItemController;
use App\Http\Controllers\MockupHubController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PenaltyController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\PlacementTestController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecruitmentController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SepayWebhookController;
use App\Http\Controllers\StaffReportController;
use App\Http\Controllers\StudentPortalController;
use App\Http\Controllers\StudentProfileController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\SurveyController;
use App\Http\Controllers\SyllabusController;
use App\Http\Controllers\SystemCategoryController;
use App\Http\Controllers\SystemConfigController;
use App\Http\Controllers\TeacherPortalController;
use App\Http\Controllers\TrialGuestController;
use App\Http\Controllers\TuitionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPermissionOverrideController;
use App\Http\Controllers\WorkTaskController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth'])->name('dashboard');

// Interactive Mockup Hub Navigator (Admin / Manager)
Route::get('/mockup-hub', [MockupHubController::class, 'index'])->middleware(['auth', 'can:system_category.manage'])->name('mockup-hub.index');

// ─────────────────────────────────────────────────────────────
// Hệ thống Giao diện & Nghiệp vụ MENGLISH (58 Màn hình - Round Cuối)
// Bộ màn mockup cũ đọc AcademicRecord không lọc theo người dùng (bài tập, phản
// hồi, khảo sát của mọi học viên) nên chỉ Admin được xem bản mockup; vai trò
// khác được chuyển sang màn Laravel thật tương ứng (xem AcademicSystemController::show).
// ─────────────────────────────────────────────────────────────
Route::prefix('academic-system')->name('academic-system.')->middleware(['auth'])->group(function () {
    Route::get('/', [AcademicSystemController::class, 'index'])->name('index');
    Route::get('/web-admin/{screen?}', [AcademicSystemController::class, 'webAdmin'])->name('web-admin');
    Route::get('/operations/{screen?}', [AcademicSystemController::class, 'operations'])->name('operations');
    Route::get('/teacher-portal/{screen?}', [AcademicSystemController::class, 'teacherPortal'])->name('teacher-portal');
    Route::get('/parent-portal/{screen?}', [AcademicSystemController::class, 'parentPortal'])->name('parent-portal');
    Route::get('/{category}/{screen}', [AcademicSystemController::class, 'show'])->name('show');
});

// Shortcuts trực tiếp cho 4 phân hệ
Route::get('/academic-admin/{screen?}', [AcademicSystemController::class, 'webAdmin'])->middleware(['auth'])->name('academic-admin.shortcut');
Route::get('/academic-ops/{screen?}', [AcademicSystemController::class, 'operations'])->middleware(['auth'])->name('academic-ops.shortcut');
Route::get('/teacher-portal/{screen?}', [AcademicSystemController::class, 'teacherPortal'])->middleware(['auth'])->name('teacher-portal.shortcut');
Route::get('/parent-portal/{screen?}', [AcademicSystemController::class, 'parentPortal'])->middleware(['auth'])->name('parent-portal.shortcut');

// Dashboard Báo cáo & Nhật ký sự vụ Admin
Route::prefix('academic/dashboards')->name('academic.dashboards.')->middleware(['auth', 'can:class.update'])->group(function () {
    Route::get('/reports', [AcademicDashboardController::class, 'reports'])->name('reports');
    Route::get('/incidents', [AcademicDashboardController::class, 'incidents'])->name('incidents');
});

// API CSDL Thực Tế cho Hệ Thống 58 Màn Hình
Route::prefix('api/academic-system')->name('api.academic-system.')->middleware(['auth'])->group(function () {
    Route::get('/records', [AcademicSystemController::class, 'apiGetRecords'])->middleware('can:system_category.manage')->name('records.index');
    Route::post('/records', [AcademicSystemController::class, 'apiStoreRecord'])->middleware('can:system_category.manage')->name('records.store');
    Route::put('/records/{id}', [AcademicSystemController::class, 'apiUpdateRecord'])->middleware('can:system_category.manage')->name('records.update');
    Route::delete('/records/{id}', [AcademicSystemController::class, 'apiDeleteRecord'])->middleware('can:system_category.manage')->name('records.destroy');
    Route::post('/records/{id}/action', [AcademicSystemController::class, 'apiActionRecord'])->middleware('can:system_category.manage')->name('records.action');
});

// SePay Webhook Endpoints (Public Callback từ SePay)
Route::post('/hook/sepay-gateway/v1/add-payment', [SepayWebhookController::class, 'handleWebhook'])->name('sepay.webhook.gateway');
Route::post('/api/sepay/webhook', [SepayWebhookController::class, 'handleWebhook'])->name('sepay.webhook.api');
Route::get('/api/sepay/transactions', [SepayWebhookController::class, 'getRecentTransactions'])->middleware(['auth', 'can:tuition.view'])->name('sepay.transactions.recent');

// Cổng Tuyển dụng Public (Ứng viên xem và nộp hồ sơ không cần đăng nhập)
Route::get('/portal/recruitment', [RecruitmentController::class, 'portal'])->name('portal.recruitment');
Route::post('/portal/recruitment', [RecruitmentController::class, 'portalSubmit'])->name('portal.recruitment.submit');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // ─────────────────────────────────────────────
    // Tuyển dụng & Quản lý Hồ sơ CV Ứng viên
    // ─────────────────────────────────────────────
    Route::prefix('recruitment')->name('recruitment.')->group(function () {
        Route::get('/', [RecruitmentController::class, 'index'])->middleware('can:recruitment.view')->name('index');
        Route::post('/jobs', [RecruitmentController::class, 'storeJob'])->middleware('can:recruitment.manage')->name('jobs.store');
        Route::post('/jobs/{id}/toggle', [RecruitmentController::class, 'toggleJobStatus'])->middleware('can:recruitment.manage')->name('jobs.toggle');
        Route::post('/cv/{id}/status', [RecruitmentController::class, 'updateCvStatus'])->middleware('can:recruitment.manage')->name('cv.update-status');
    });

    // ─────────────────────────────────────────────
    // 1. CRM & Tuyển sinh
    // ─────────────────────────────────────────────
    Route::prefix('crm')->name('crm.')->middleware('can:lead.view')->group(function () {
        Route::get('/pipeline', [CrmController::class, 'pipeline'])->name('pipeline');
        Route::get('/waiting-list', [CrmController::class, 'waitingList'])->name('waiting-list');
        Route::get('/customers', [CrmController::class, 'customers'])->name('customers.index');
        Route::post('/customers', [CrmController::class, 'storeCustomer'])->middleware('can:lead.create')->name('customers.store');
        Route::get('/customers/create', [CrmController::class, 'createCustomer'])->middleware('can:lead.create')->name('customers.create');
        Route::get('/customers/{id}', [CrmController::class, 'showCustomer'])->name('customers.show');
        Route::put('/customers/{id}', [CrmController::class, 'updateCustomer'])->middleware('can:lead.update')->name('customers.update');
        // Quyền chuyển giai đoạn theo vai trò (CM tiến 1 bước, Admin lùi bước) do CrmStageService kiểm tra.
        Route::post('/customers/{id}/stage', [CrmController::class, 'updateStage'])->name('customers.stage');
        Route::post('/customers/{id}/next-stage', [CrmController::class, 'nextStage'])->name('customers.next-stage');
        Route::delete('/customers/{id}', [CrmController::class, 'destroyCustomer'])->middleware('can:lead.delete')->name('customers.destroy');
        Route::post('/customers/{id}/notes', [CrmController::class, 'addNote'])->middleware('can:lead.update')->name('customers.notes.store');
        Route::post('/customers/{id}/schedule-test', [CrmController::class, 'schedulePlacementTest'])->middleware('can:entrance_test.send')->name('customers.schedule-test');
        Route::post('/customers/{id}/test-score', [CrmController::class, 'saveTestScore'])->middleware('can:entrance_test.grade')->name('customers.save-test-score');
        Route::post('/customers/{id}/trial-bookings', [CrmController::class, 'storeTrialBooking'])->name('customers.trial-bookings.store');
        Route::post('/customers/{id}/trial-bookings/{booking}/cancel', [CrmController::class, 'cancelTrialBooking'])->name('customers.trial-bookings.cancel');
        Route::post('/customers/{id}/assign-class', [CrmController::class, 'assignClass'])->middleware('can:student.assign_class')->name('customers.assign-class');
        Route::get('/customers/{id}/edit', [CrmController::class, 'editCustomer'])->middleware('can:lead.update')->name('customers.edit');
        Route::get('/customers-won', [CrmController::class, 'wonCustomers'])->name('customers.won');
        Route::get('/closing-wizard', [CrmController::class, 'closingWizard'])->middleware('can:lead.convert')->name('closing-wizard');
        Route::post('/closing-wizard', [CrmController::class, 'processClosingWizard'])->middleware('can:lead.convert')->name('closing-wizard.store');
        Route::post('/promotions/store', [CrmController::class, 'storePromotion'])->middleware('can:promotion.manage')->name('promotions.store');
        Route::get('/lost-deals', [CrmController::class, 'lostDeals'])->name('lost-deals');
        Route::get('/reports', [CrmController::class, 'reports'])->name('reports');
    });
    Route::get('/crm/tuition-bill/{id}', [CrmController::class, 'tuitionBill'])->name('crm.tuition-bill');

    // ─────────────────────────────────────────────
    // 2. Học phí & Hóa đơn
    // ─────────────────────────────────────────────
    Route::prefix('tuition')->name('tuition.')->middleware('can:tuition.view')->group(function () {
        Route::get('/students', [TuitionController::class, 'students'])->name('students');
        Route::get('/import', [TuitionController::class, 'import'])->name('import');
        Route::post('/import', [TuitionController::class, 'importTuition'])->name('import.store');
        Route::get('/receipts/create', [TuitionController::class, 'createReceipt'])->name('receipts.create');
        Route::post('/receipts', [TuitionController::class, 'storeReceipt'])->middleware('can:tuition.create')->name('receipts.store');
        Route::put('/receipts/{id}', [TuitionController::class, 'updateReceipt'])->middleware('can:tuition.create')->name('receipts.update');
        Route::get('/receipts/approve', [TuitionController::class, 'approveReceipt'])->name('receipts.approve');
        Route::post('/receipts/{id}/approve', [TuitionController::class, 'approveReceiptAction'])->middleware('can:tuition.approve')->name('receipts.approve.action');
        Route::post('/receipts/{id}/reject', [TuitionController::class, 'rejectReceiptAction'])->middleware('can:tuition.reject')->name('receipts.reject.action');
        Route::get('/history', [TuitionController::class, 'history'])->name('history');
        Route::get('/invoices/cancellations', [TuitionController::class, 'invoiceCancellations'])->name('invoices.cancellations');
        Route::post('/invoices/cancellations', [TuitionController::class, 'storeInvoiceCancellation'])->middleware('can:invoice.request_cancel')->name('invoices.cancellations.store');
        Route::post('/invoices/cancellations/{id}/approve', [TuitionController::class, 'approveInvoiceCancellation'])->middleware('can:invoice.approve_cancel')->name('invoices.cancellations.approve');
        Route::post('/invoices/cancellations/{id}/reject', [TuitionController::class, 'rejectInvoiceCancellation'])->middleware('can:invoice.approve_cancel')->name('invoices.cancellations.reject');
        Route::get('/refunds', [TuitionController::class, 'refunds'])->name('refunds');
        Route::post('/refunds', [TuitionController::class, 'storeRefundRequest'])->middleware('can:refund_transfer.request')->name('refunds.store');
        Route::post('/refunds/{id}/approve', [TuitionController::class, 'approveRefundRequest'])->middleware('can:refund_transfer.approve')->name('refunds.approve');
        Route::post('/refunds/{id}/reject', [TuitionController::class, 'rejectRefundRequest'])->middleware('can:refund_transfer.approve')->name('refunds.reject');
        Route::get('/overdue', [TuitionController::class, 'overdue'])->name('overdue');
        Route::post('/overdue/{id}/remind', [TuitionController::class, 'sendOverdueReminder'])->middleware('can:tuition.mark_contacted')->name('overdue.remind');
        Route::post('/overdue/{id}/upcoming-remind', [TuitionController::class, 'sendUpcomingReminder'])->middleware('can:tuition.mark_contacted')->name('overdue.upcoming-remind');
        Route::get('/config', [TuitionController::class, 'config'])->name('config');
        Route::post('/config', [TuitionController::class, 'updateConfig'])->middleware('can:invoice_range.manage')->name('config.update');
    });

    // ─────────────────────────────────────────────
    // 2.1. Tài chính & Báo cáo Thu Chi (Epic 13)
    // ─────────────────────────────────────────────
    Route::prefix('finance')->name('finance.')->middleware('can:report.view')->group(function () {
        Route::get('/expenses', [FinanceController::class, 'expenses'])->name('expenses.index');
        Route::post('/expenses', [FinanceController::class, 'storeExpense'])->middleware('can:tuition.create')->name('expenses.store');
        Route::put('/expenses/{id}', [FinanceController::class, 'updateExpense'])->middleware('can:tuition.create')->name('expenses.update');
        Route::delete('/expenses/{id}', [FinanceController::class, 'destroyExpense'])->middleware('can:tuition.create')->name('expenses.destroy');
        Route::get('/expenses/export', [FinanceController::class, 'exportExpenses'])->name('expenses.export');

        Route::get('/reports/provisional-revenue', [FinanceController::class, 'provisionalRevenue'])->name('reports.revenue');
        Route::get('/reports/provisional-revenue/export', [FinanceController::class, 'exportRevenueReport'])->name('reports.revenue.export');
    });

    // ─────────────────────────────────────────────
    // 3. Hồ sơ Học sinh (Epic 6)
    // ─────────────────────────────────────────────
    Route::prefix('students')->name('students.')->middleware('can:student.view')->group(function () {
        Route::get('/', [StudentProfileController::class, 'index'])->name('index');
        Route::post('/', [StudentProfileController::class, 'storeStudent'])->middleware('can:student.create')->name('store');
        Route::get('/enrollments', [StudentProfileController::class, 'enrollments'])->name('enrollments');
        Route::post('/enrollments', [StudentProfileController::class, 'storeEnrollment'])->middleware('can:student.assign_class')->name('enrollments.store');
        Route::put('/enrollments/{id}', [StudentProfileController::class, 'updateEnrollmentHandoff'])->middleware('can:student.assign_class')->name('enrollments.update');
        Route::get('/{id}', [StudentProfileController::class, 'show'])->name('show');
        Route::put('/{id}', [StudentProfileController::class, 'updateStudent'])->middleware('can:student.update')->name('update');
        Route::put('/{id}/status', [StudentProfileController::class, 'updateStudentStatus'])->middleware('can:student.change_status')->name('status.update');
        Route::delete('/{id}', [StudentProfileController::class, 'destroyStudent'])->middleware('can:student.delete')->name('destroy');
        Route::get('/{id}/scoped', [StudentProfileController::class, 'scoped'])->name('scoped');
    });

    // ─────────────────────────────────────────────
    // Flow 1: Tuyển sinh, Khai giảng & Quản lý Lớp học (6 bước chuẩn BA)
    // ─────────────────────────────────────────────
    Route::prefix('classes')->name('classes.')->middleware('can:class.view')->group(function () {
        Route::get('/trial-booking', [ClassManagementController::class, 'trialBooking'])->name('trial-booking');
        Route::post('/trial-booking', [ClassManagementController::class, 'trialBookingStore'])->middleware('can:class.update')->name('trial-booking.store');
        Route::get('/create', [ClassManagementController::class, 'create'])->middleware('can:class.create')->name('create');
        Route::post('/', [ClassManagementController::class, 'store'])->middleware('can:class.create')->name('store');
        Route::get('/profile/{id?}', [ClassManagementController::class, 'profile'])->name('profile');
        Route::post('/check-availability', [ClassManagementController::class, 'checkAvailability'])->name('check-availability');
        Route::get('/academic-overview', [ClassManagementController::class, 'academicOverview'])->name('academic-overview');
        Route::get('/academic-list', [ClassManagementController::class, 'academicList'])->name('academic-list');
        Route::get('/academic-detail/{id?}', [ClassManagementController::class, 'academicDetail'])->name('academic-detail');
        Route::get('/qa-observation', [ClassManagementController::class, 'qaObservation'])->name('qa-observation');
        Route::get('/checklist', [ClassManagementController::class, 'checklist'])->name('checklist');
        Route::get('/evaluate-observation', [ClassManagementController::class, 'evaluateObservation'])->name('evaluate-observation');
        Route::get('/', [ClassManagementController::class, 'index'])->name('index');
        Route::get('/{id}/edit', [ClassManagementController::class, 'edit'])->name('edit');
        Route::put('/{id}', [ClassManagementController::class, 'update'])->middleware('can:class.update')->name('update');
        Route::delete('/{id}', [ClassManagementController::class, 'destroy'])->middleware('can:class.delete')->name('destroy');
    });

    // ─────────────────────────────────────────────
    // Flow 4: Cổng Phụ huynh & Học sinh (7 Màn hình) & Cổng Giáo viên chấm bài
    // ─────────────────────────────────────────────
    Route::prefix('portal')->name('portal.')->group(function () {
        // Step 1: Mobile App Shell
        Route::get('/app-shell', [StudentPortalController::class, 'appShell'])->name('app-shell');

        // Step 2: Trang chủ Phụ huynh / Học sinh
        Route::get('/home/{studentId?}', [StudentPortalController::class, 'studentHome'])->name('student.home');
        Route::post('/student/{id}/update-profile', [StudentPortalController::class, 'updateProfile'])->name('student.profile.update');
        Route::post('/student/tuition-request', [StudentPortalController::class, 'submitTuitionRequest'])->name('student.tuition.request');

        // Step 3: Học tập của tôi — Nộp bài tập
        Route::get('/student-homework/{studentId?}', [StudentPortalController::class, 'studentHomework'])->name('student.homework');
        Route::post('/student-homework/submit', [StudentPortalController::class, 'submitHomework'])->name('student.homework.submit');
        Route::post('/student-homework/{id}/update', [StudentPortalController::class, 'updateHomework'])->name('student.homework.update');
        Route::delete('/student-homework/{id}/destroy', [StudentPortalController::class, 'deleteHomework'])->name('student.homework.destroy');

        // Step 4: Luyện phát âm & Thu âm giọng nói AI
        Route::get('/pronunciation/{studentId?}', [StudentPortalController::class, 'studentPronunciation'])->name('student.pronunciation');
        Route::post('/pronunciation/record', [StudentPortalController::class, 'submitPronunciation'])->name('student.pronunciation.store');
        Route::delete('/pronunciation/{id}/destroy', [StudentPortalController::class, 'deletePronunciation'])->name('student.pronunciation.destroy');

        // Step 5: Danh sách thông báo
        Route::get('/notifications/{studentId?}', [StudentPortalController::class, 'studentNotifications'])->name('student.notifications');
        Route::post('/notifications/mark-read', [StudentPortalController::class, 'markNotificationsRead'])->name('student.notifications.read');
        Route::post('/notifications/{id}/mark-read', [StudentPortalController::class, 'markSingleNotificationRead'])->name('student.notifications.read-single');
        Route::delete('/notifications/{id}/destroy', [StudentPortalController::class, 'deleteNotification'])->name('student.notifications.destroy');

        // Step 6: Khảo sát & Đánh giá chất lượng đào tạo
        Route::get('/survey/{studentId?}', [StudentPortalController::class, 'studentSurvey'])->name('student.survey');
        Route::post('/survey/submit', [StudentPortalController::class, 'submitSurvey'])->name('student.survey.store');
        Route::delete('/survey/{id}/destroy', [StudentPortalController::class, 'deleteSurvey'])->name('student.survey.destroy');

        // Step 7: Phụ huynh gửi Feedback chặng học (MH6)
        Route::get('/feedback/{studentId?}', [StudentPortalController::class, 'studentFeedback'])->name('student.feedback');
        Route::post('/feedback/submit', [StudentPortalController::class, 'submitFeedback'])->name('student.feedback.store');
        Route::delete('/feedback/{id}/destroy', [StudentPortalController::class, 'deleteFeedback'])->name('student.feedback.destroy');

        // Cổng Giáo viên chấm bài nộp của lớp
        Route::get('/teacher-submissions/{classId?}', [StudentPortalController::class, 'teacherSubmissions'])->name('teacher.submissions');
        Route::get('/teacher/submissions/{classId?}', [StudentPortalController::class, 'teacherSubmissions'])->name('teacher.submissions.alias');
        Route::post('/teacher-submissions/{id}/mark', [StudentPortalController::class, 'markSubmission'])->name('teacher.submissions.mark');
    });

    // ─────────────────────────────────────────────
    // 4. Lương, Chấm công & KPI (Epic 7)
    // ─────────────────────────────────────────────
    Route::prefix('payroll')->name('payroll.')->group(function () {
        Route::get('/periods', [PayrollController::class, 'periods'])->middleware('can:payroll.view')->name('periods.index');
        Route::post('/periods', [PayrollController::class, 'storePeriod'])->middleware('can:payroll.create')->name('periods.store');
        Route::get('/periods/{id}', [PayrollController::class, 'showPeriod'])->middleware('can:payroll.view')->name('periods.show');
        Route::post('/periods/{id}/approve', [PayrollController::class, 'approvePeriod'])->middleware('can:payroll.approve')->name('periods.approve');
        Route::post('/periods/{id}/mark-paid', [PayrollController::class, 'markPaid'])->middleware('can:payroll.mark_paid')->name('periods.mark-paid');
        Route::post('/periods/{id}/calculate', [PayrollController::class, 'calculatePeriod'])->middleware('can:payroll.calculate')->name('periods.calculate');
        Route::get('/periods/{id}/fulltime', [PayrollController::class, 'fulltimePeriod'])->middleware('can:payroll.view')->name('periods.fulltime');
        Route::get('/periods/{id}/academic', [PayrollController::class, 'academicPeriod'])->middleware('can:payroll.view')->name('periods.academic');
        Route::get('/periods/{id}/operations', [PayrollController::class, 'operationsPeriod'])->middleware('can:payroll.view')->name('periods.operations');
        Route::post('/records/{id}/update', [PayrollController::class, 'updateRecord'])->middleware('can:payroll.edit')->name('records.update');
        Route::get('/timesheets/appsheet', [PayrollController::class, 'appsheetTimesheet'])->middleware('can:attendance_staff.view')->name('timesheets.appsheet');
        Route::get('/timesheets/manual', [PayrollController::class, 'manualTimesheet'])->middleware('can:attendance_staff.manual_record')->name('timesheets.manual');
        Route::post('/timesheets/manual', [PayrollController::class, 'storeTimesheet'])->middleware('can:attendance_staff.manual_record')->name('timesheets.manual.store');
        Route::get('/timesheets/teachers', [PayrollController::class, 'teacherTimesheets'])->name('timesheets.teachers');
        Route::post('/timesheets/teachers/{id}/review', [PayrollController::class, 'reviewTimesheet'])->middleware('can:attendance_staff.view')->name('timesheets.review');
        Route::get('/timesheets/sync-history', [PayrollController::class, 'syncHistory'])->middleware('can:attendance_staff.sync')->name('timesheets.sync-history');
        Route::get('/kpi-leaderboard', [PayrollController::class, 'kpiLeaderboard'])->middleware('can:kpi.view')->name('kpi-leaderboard');
        Route::get('/config/settings', [PayrollController::class, 'configSettings'])->middleware('can:teacher_rate.manage')->name('config.settings');
        Route::post('/config/settings', [PayrollController::class, 'storeSettings'])->middleware('can:teacher_rate.manage')->name('config.settings.store');
        Route::get('/config/teacher-rates', [PayrollController::class, 'teacherRates'])->middleware('can:teacher_rate.manage')->name('config.teacher-rates');
        Route::post('/config/teacher-rates', [PayrollController::class, 'storeTeacherRate'])->middleware('can:teacher_rate.manage')->name('config.teacher-rates.store');
        Route::post('/config/teacher-rates/personal', [PayrollController::class, 'storePersonalTeacherRate'])->middleware('can:teacher_rate.manage')->name('config.teacher-rates.personal.store');
        Route::get('/config/commission-tiers', [PayrollController::class, 'commissionTiers'])->middleware('can:commission_config.manage')->name('config.commission-tiers');
        Route::post('/config/commission-tiers', [PayrollController::class, 'storeCommissionTier'])->middleware('can:commission_config.manage')->name('config.commission-tiers.store');
        Route::put('/config/commission-tiers/{commissionTier}', [PayrollController::class, 'updateCommissionTier'])->middleware('can:commission_config.manage')->name('config.commission-tiers.update');
        Route::delete('/config/commission-tiers/{commissionTier}', [PayrollController::class, 'destroyCommissionTier'])->middleware('can:commission_config.manage')->name('config.commission-tiers.destroy');
    });

    Route::get('/portal/my-salary', [PayrollController::class, 'mySalary'])->name('portal.my-salary');

    // ─────────────────────────────────────────────
    // 5. Kỷ luật & Xử phạt (Epic 8)
    // ─────────────────────────────────────────────
    Route::get('/penalties', [PenaltyController::class, 'index'])->middleware('can:violation.view')->name('penalties.index');
    Route::post('/penalties', [PenaltyController::class, 'storePenalty'])->middleware('can:violation.create')->name('penalties.store');
    Route::post('/penalties/{id}/confirm', [PenaltyController::class, 'confirmPenalty'])->name('penalties.confirm');
    Route::post('/penalties/{id}/mark-paid', [PenaltyController::class, 'markPaidPenalty'])->middleware('can:violation.mark_paid')->name('penalties.mark-paid');
    Route::post('/penalties/{id}/resolve', [PenaltyController::class, 'resolvePenalty'])->middleware('can:violation.mark_resolved')->name('penalties.resolve');
    Route::post('/penalties/{id}/cancel', [PenaltyController::class, 'cancelPenalty'])->middleware('can:violation.cancel')->name('penalties.cancel');

    // ─────────────────────────────────────────────
    // 6. Khóa học, Bảng giá học phí & Trình độ
    // ─────────────────────────────────────────────
    Route::prefix('courses')->name('courses.')->group(function () {
        Route::get('/', [CourseController::class, 'index'])->middleware('can:course.view')->name('index');
        Route::post('/', [CourseController::class, 'store'])->middleware('can:course.create')->name('store');
        Route::put('/{id}', [CourseController::class, 'update'])->middleware('can:course.update')->name('update');
        Route::patch('/{id}/toggle', [CourseController::class, 'toggleStatus'])->middleware('can:course.update')->name('toggle');
        Route::delete('/{id}', [CourseController::class, 'destroy'])->middleware('can:course.delete')->name('destroy');
    });

    Route::get('/course-levels', [CourseLevelController::class, 'index'])->middleware('can:level.view')->name('course-levels.index');
    Route::post('/course-levels', [CourseLevelController::class, 'storeLevel'])->middleware('can:level.create')->name('course-levels.store');
    Route::put('/course-levels/{id}', [CourseLevelController::class, 'updateLevel'])->middleware('can:level.update')->name('course-levels.update');
    Route::delete('/course-levels/{id}', [CourseLevelController::class, 'destroyLevel'])->middleware('can:level.delete')->name('course-levels.destroy');

    // ─────────────────────────────────────────────
    // 7a. Quản lý Đợt Khảo sát Chất lượng
    // ─────────────────────────────────────────────
    Route::prefix('surveys')->name('surveys.')->middleware('can:survey.manage')->group(function () {
        Route::get('/', [SurveyController::class, 'index'])->name('index');
        Route::post('/', [SurveyController::class, 'store'])->name('store');
        Route::put('/{survey}', [SurveyController::class, 'update'])->name('update');
        Route::delete('/{survey}', [SurveyController::class, 'destroy'])->name('destroy');
    });

    // ─────────────────────────────────────────────
    // 7. Quản lý Đề Test Đầu Vào
    // ─────────────────────────────────────────────
    Route::prefix('placement-tests')->name('placement-tests.')->group(function () {        Route::get('/', [PlacementTestController::class, 'index'])->middleware('can:placement_test.view')->name('index');
        Route::get('/create', [PlacementTestController::class, 'create'])->middleware('can:placement_test.create')->name('create');
        Route::post('/', [PlacementTestController::class, 'storeTest'])->middleware('can:placement_test.create')->name('store');
        Route::get('/rubric-guide', [PlacementTestController::class, 'rubricGuide'])->middleware('can:placement_test.view')->name('rubric-guide');
        Route::get('/results/{id}', [PlacementTestController::class, 'showResult'])->middleware('can:placement_test.grade')->name('results.show');
        Route::post('/results/{id}', [PlacementTestController::class, 'updateResult'])->middleware('can:placement_test.grade')->name('results.update');
        Route::post('/{id}/duplicate', [PlacementTestController::class, 'duplicateTest'])->middleware('can:placement_test.create')->name('duplicate');
        Route::post('/{id}/distribute', [PlacementTestController::class, 'distributeTest'])->middleware('can:placement_test.distribute')->name('distribute');
        Route::get('/{id}', [PlacementTestController::class, 'showTest'])->middleware('can:placement_test.view')->name('show');
        Route::get('/{id}/edit', [PlacementTestController::class, 'editTest'])->middleware('can:placement_test.update')->name('edit');
        Route::put('/{id}', [PlacementTestController::class, 'updateTest'])->middleware('can:placement_test.update')->name('update');
        Route::delete('/{id}', [PlacementTestController::class, 'destroyTest'])->middleware('can:placement_test.delete')->name('destroy');
    });

    // ─────────────────────────────────────────────
    // 8. Quản lý Syllabus & Big Test
    // ─────────────────────────────────────────────
    Route::prefix('syllabus')->name('syllabus.')->middleware('can:syllabus.view')->group(function () {
        Route::get('/documents', [SyllabusController::class, 'documents'])->name('documents');
        Route::post('/documents', [SyllabusController::class, 'storeDocument'])->middleware('can:syllabus.upload')->name('documents.store');
        Route::get('/builder', [SyllabusController::class, 'builder'])->name('builder');
        Route::post('/units', [SyllabusController::class, 'storeUnit'])->middleware('can:syllabus.update')->name('units.store');
        Route::get('/assignments', [SyllabusController::class, 'assignments'])->name('assignments');
        Route::post('/assignments', [SyllabusController::class, 'storeAssignment'])->middleware('can:syllabus.manage')->name('assignments.store');
        Route::get('/versions', [SyllabusController::class, 'versions'])->name('versions');
        Route::get('/teacher-view', [SyllabusController::class, 'teacherView'])->name('teacher-view');
        Route::get('/teacher-propose', [SyllabusController::class, 'teacherPropose'])->name('teacher-propose');
        Route::get('/teacher-adjust', [SyllabusController::class, 'teacherAdjust'])->name('teacher-adjust');
        Route::get('/adjustment-requests', [SyllabusController::class, 'adjustmentRequests'])->name('adjustment-requests');
        Route::post('/adjustment-requests', [SyllabusController::class, 'storeAdjustmentRequest'])->middleware('can:syllabus.propose_adjustment')->name('adjustment-requests.store');
        Route::post('/adjustment-requests/{id}/approve', [SyllabusController::class, 'approveAdjustmentRequest'])->middleware('can:syllabus.approve_adjustment')->name('adjustment-requests.approve');
        Route::post('/adjustment-requests/{id}/reject', [SyllabusController::class, 'rejectAdjustmentRequest'])->middleware('can:syllabus.approve_adjustment')->name('adjustment-requests.reject');
        Route::get('/big-tests/distribution', [SyllabusController::class, 'bigTestDistribution'])->name('big-tests.distribution');
        Route::post('/big-tests/distribution', [SyllabusController::class, 'storeBigTest'])->middleware('can:syllabus.manage')->name('big-tests.store');
        Route::post('/big-tests/{id}/approve', [SyllabusController::class, 'approveAndDistributeBigTest'])->middleware('can:syllabus.approve_adjustment')->name('big-tests.approve');
        Route::get('/big-tests/schedules', [SyllabusController::class, 'bigTestSchedules'])->name('big-tests.schedules');
        Route::post('/big-tests/{id}/remind', [SyllabusController::class, 'sendBigTestReminder'])->middleware('can:syllabus.approve_adjustment')->name('big-tests.remind');
        Route::get('/big-tests/results/{id?}', [SyllabusController::class, 'bigTestResults'])->name('big-tests.results');
        Route::post('/big-tests/{id}/results', [SyllabusController::class, 'storeBigTestResults'])->middleware('can:syllabus.update')->name('big-tests.results.store');
        Route::post('/big-tests/{id}/results/approve', [SyllabusController::class, 'approveBigTestResults'])->middleware('can:syllabus.approve_adjustment')->name('big-tests.results.approve');
        Route::post('/big-tests/{id}/send-zalo', [SyllabusController::class, 'sendZaloResults'])->middleware('can:syllabus.approve_adjustment')->name('big-tests.send-zalo');
        Route::post('/big-tests/results/{resultId}/send-single-zalo', [SyllabusController::class, 'sendSingleZaloResult'])->middleware('can:syllabus.approve_adjustment')->name('big-tests.send-single-zalo');
    });

    // ─────────────────────────────────────────────
    // 9. Cấu hình hệ thống nâng cao (Epic 5)
    // ─────────────────────────────────────────────
    Route::prefix('system-config')->name('system-config.')->group(function () {
        Route::get('/bank-accounts', [SystemConfigController::class, 'bankAccounts'])->middleware('can:bank_account.manage')->name('bank-accounts');
        Route::post('/bank-accounts', [SystemConfigController::class, 'storeBankAccount'])->middleware('can:bank_account.manage')->name('bank-accounts.store');
        Route::put('/bank-accounts/{id}', [SystemConfigController::class, 'updateBankAccount'])->middleware('can:bank_account.manage')->name('bank-accounts.update');
        Route::delete('/bank-accounts/{id}', [SystemConfigController::class, 'destroyBankAccount'])->middleware('can:bank_account.manage')->name('bank-accounts.destroy');
        Route::post('/bank-accounts/{id}/default', [SystemConfigController::class, 'setDefaultBankAccount'])->middleware('can:bank_account.manage')->name('bank-accounts.default');
        Route::post('/sepay', [SystemConfigController::class, 'updateSepayConfig'])->middleware('can:bank_account.manage')->name('sepay.update');
        Route::get('/debt-reminders', [SystemConfigController::class, 'debtReminders'])->middleware('can:fee_reminder_config.manage')->name('debt-reminders');
        Route::post('/debt-reminders', [SystemConfigController::class, 'storeDebtReminder'])->middleware('can:fee_reminder_config.manage')->name('debt-reminders.store');
        Route::get('/ticket-emails', [SystemConfigController::class, 'ticketEmails'])->middleware('can:support_ticket.update')->name('ticket-emails');
        Route::post('/ticket-emails', [SystemConfigController::class, 'updateTicketEmails'])->middleware('can:support_ticket.update')->name('ticket-emails.update');
        Route::post('/ticket-emails/test', [SystemConfigController::class, 'sendTestTicketEmail'])->middleware('can:support_ticket.update')->name('ticket-emails.test');
        Route::get('/hosting', [SystemConfigController::class, 'hostingInfo'])->middleware('can:bank_account.manage')->name('hosting');
    });

    // ─────────────────────────────────────────────
    // 10. Quản lý Tài khoản & Phân quyền gốc
    // ─────────────────────────────────────────────
    Route::controller(UserController::class)->prefix('users')->name('users.')->group(function () {
        Route::get('/', 'index')->middleware('can:user.view')->name('index');
        Route::get('/create', 'create')->middleware('can:user.create')->name('create');
        Route::post('/', 'store')->middleware('can:user.create')->name('store');
        Route::get('/{user}', 'show')->middleware('can:user.view')->name('show');
        Route::get('/{user}/edit', 'edit')->middleware('can:user.update')->name('edit');
        Route::put('/{user}', 'update')->middleware('can:user.update')->name('update');
        Route::delete('/{user}', 'destroy')->middleware('can:user.delete')->name('destroy');
        Route::post('/{user}/lock', 'lock')->middleware('can:user.lock')->name('lock');
        Route::post('/{user}/unlock', 'unlock')->middleware('can:user.lock')->name('unlock');
        Route::post('/{user}/reset-password', 'resetPassword')->middleware('can:user.reset_password')->name('reset-password');
        Route::get('/{user}/roles', 'editRoles')->middleware('can:user.assign_role')->name('roles.edit');
        Route::put('/{user}/roles', 'updateRoles')->middleware('can:user.assign_role')->name('roles.update');
    });

    Route::get('/users/{user}/permissions', [UserPermissionOverrideController::class, 'edit'])
        ->middleware('can:permission.override')
        ->name('users.permissions.edit');
    Route::put('/users/{user}/permissions', [UserPermissionOverrideController::class, 'update'])
        ->middleware('can:permission.override')
        ->name('users.permissions.update');

    Route::controller(RoleController::class)->prefix('roles')->name('roles.')->group(function () {
        Route::get('/', 'index')->middleware('can:role.view')->name('index');
        Route::get('/create', 'create')->middleware('can:role.create')->name('create');
        Route::post('/', 'store')->middleware('can:role.create')->name('store');
        Route::get('/{role}/edit', 'edit')->middleware('can:role.update')->name('edit');
        Route::put('/{role}', 'update')->middleware('can:role.update')->name('update');
        Route::delete('/{role}', 'destroy')->middleware('can:role.delete')->name('destroy');
    });

    Route::controller(PermissionController::class)->prefix('permissions')->name('permissions.')->group(function () {
        Route::get('/', 'index')->middleware('can:permission.view')->name('index');
        Route::get('/create', 'create')->middleware('can:permission.create')->name('create');
        Route::post('/', 'store')->middleware('can:permission.create')->name('store');
        Route::get('/{permission}/edit', 'edit')->middleware('can:permission.update')->name('edit');
        Route::put('/{permission}', 'update')->middleware('can:permission.update')->name('update');
        Route::delete('/{permission}', 'destroy')->middleware('can:permission.delete')->name('destroy');
    });

    // ─────────────────────────────────────────────
    // 10. Báo lỗi & Hỗ trợ (Support Tickets / Helpdesk)
    // ─────────────────────────────────────────────
    Route::prefix('tickets')->name('tickets.')->group(function () {
        Route::get('/', [SupportTicketController::class, 'index'])->middleware('can:support_ticket.view')->name('index');
        Route::get('/create', [SupportTicketController::class, 'create'])->middleware('can:support_ticket.create')->name('create');
        Route::post('/', [SupportTicketController::class, 'store'])->middleware('can:support_ticket.create')->name('store');
        Route::get('/{id}', [SupportTicketController::class, 'show'])->middleware('can:support_ticket.view')->name('show');
        Route::post('/{id}/messages', [SupportTicketController::class, 'storeMessage'])->middleware('can:support_ticket.view')->name('messages.store');
        Route::post('/{id}/status', [SupportTicketController::class, 'updateStatus'])->middleware('can:support_ticket.close')->name('status.update');
        Route::post('/{id}/assign', [SupportTicketController::class, 'assign'])->middleware('can:support_ticket.assign')->name('assign');
    });

    // Tạo/sửa chi nhánh làm qua modal ở branches.index (POST store / PUT update);
    // 2 route GET create/edit cũ bị xóa vì BranchController không có method tương ứng (gây 500).
    Route::get('branches', [BranchController::class, 'index'])->middleware('can:branch.view')->name('branches.index');
    Route::post('branches', [BranchController::class, 'store'])->middleware('can:branch.create')->name('branches.store');
    Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('can:branch.update')->name('branches.update');
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('can:branch.delete')->name('branches.destroy');
    Route::post('branches/{id}/toggle', [BranchController::class, 'toggleStatus'])->middleware('can:branch.manage')->name('branches.toggle');
    Route::resource('system-categories', SystemCategoryController::class)->except('show')->middleware('can:system_category.manage');

    // ─────────────────────────────────────────────
    // Quản lý Danh mục Hàng hóa, Sách & Vật phẩm
    // ─────────────────────────────────────────────
    Route::prefix('merchandise')->name('merchandise.')->middleware('can:system_category.manage')->group(function () {
        Route::get('/', [MerchandiseItemController::class, 'index'])->name('index');
        Route::get('/create', [MerchandiseItemController::class, 'create'])->name('create');
        Route::post('/', [MerchandiseItemController::class, 'store'])->name('store');
        Route::get('/{merchandise}/edit', [MerchandiseItemController::class, 'edit'])->name('edit');
        Route::put('/{merchandise}', [MerchandiseItemController::class, 'update'])->name('update');
        Route::delete('/{merchandise}', [MerchandiseItemController::class, 'destroy'])->name('destroy');
        Route::post('/{merchandise}/toggle', [MerchandiseItemController::class, 'toggleStatus'])->name('toggle');
        Route::get('/api/items', [MerchandiseItemController::class, 'apiList'])->name('api');
    });

    Route::resource('holidays', HolidayController::class)->except('show')->middleware('can:holiday.manage');
    Route::get('/activity-logs', [ActivityLogController::class, 'index'])->middleware('can:activity_log.view')->name('activity-logs.index');

    // ─────────────────────────────────────────────
    // 11. Trung Tâm Thông Báo & Cảnh Báo Lead Sót (Notifications)
    // ─────────────────────────────────────────────
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [AdminNotificationController::class, 'index'])->middleware('can:notification.view')->name('index');
        Route::get('/dropdown', [AdminNotificationController::class, 'dropdown'])->middleware('can:notification.view')->name('dropdown');
        Route::post('/{id}/read', [AdminNotificationController::class, 'markAsRead'])->middleware('can:notification.view')->name('read');
        Route::post('/read-all', [AdminNotificationController::class, 'markAllAsRead'])->middleware('can:notification.view')->name('read-all');
        Route::post('/scan', [AdminNotificationController::class, 'scan'])->middleware('can:notification.manage')->name('scan');
    });

    // ─────────────────────────────────────────────
    // 12. Phân công công việc & Trợ giảng
    // ─────────────────────────────────────────────
    Route::prefix('tasks')->name('tasks.')->middleware('can:work_task.view')->group(function () {
        Route::get('/', [WorkTaskController::class, 'index'])->name('index');
        Route::get('/create', [WorkTaskController::class, 'create'])->middleware('can:work_task.create')->name('create');
        Route::post('/', [WorkTaskController::class, 'store'])->middleware('can:work_task.create')->name('store');
        Route::post('/{id}/status', [WorkTaskController::class, 'updateStatus'])->middleware('can:work_task.update')->name('status.update');
        Route::get('/classes-dashboard', [WorkTaskController::class, 'classesDashboard'])->name('classes-dashboard');
        Route::get('/ta-assign', [WorkTaskController::class, 'taAssignForm'])->middleware('can:work_task.assign')->name('ta-assign');
        Route::post('/ta-assign', [WorkTaskController::class, 'taAssignStore'])->middleware('can:work_task.assign')->name('ta-assign.store');
        Route::post('/{id}/complete', [WorkTaskController::class, 'completeTask'])->name('complete');
        Route::get('/class-reports/create', [WorkTaskController::class, 'createClassReport'])->name('class-reports.create');
        Route::post('/class-reports', [WorkTaskController::class, 'storeClassReport'])->name('class-reports.store');
        Route::get('/manual-approvals', [WorkTaskController::class, 'manualApprovals'])->middleware('can:work_task.approve')->name('manual-approvals');
        Route::post('/{id}/approve', [WorkTaskController::class, 'approveTask'])->middleware('can:work_task.approve')->name('approve');
        Route::post('/{id}/reject', [WorkTaskController::class, 'rejectTask'])->middleware('can:work_task.approve')->name('reject');
        Route::get('/schedule-config', [WorkTaskController::class, 'scheduleConfig'])->name('schedule-config');
        Route::post('/schedule-config', [WorkTaskController::class, 'updateScheduleConfig'])->middleware('can:work_task.assign')->name('schedule-config.update');
        Route::get('/support-sessions', [WorkTaskController::class, 'supportSessions'])->name('support-sessions');
        Route::post('/support-sessions', [WorkTaskController::class, 'storeSupportSession'])->middleware('can:work_task.assign')->name('support-sessions.store');
        Route::post('/support-sessions/{id}/complete', [WorkTaskController::class, 'completeSupportSession'])->name('support-sessions.complete');
        Route::post('/hr-demand', [WorkTaskController::class, 'saveHrDemand'])->middleware('can:work_task.assign')->name('hr-demand.save');
        Route::get('/kpi-dashboard', [WorkTaskController::class, 'kpiDashboard'])->name('kpi-dashboard');
    });

    Route::get('/portal/ta-tasks', [WorkTaskController::class, 'taPortal'])->name('portal.ta-tasks');

    // ──────────────────────────────────────
    // Cổng Giáo viên: Check-in nhiều ca & Điểm danh lớp
    // ──────────────────────────────────────
    Route::controller(TeacherPortalController::class)->prefix('teacher')->name('teacher.')->group(function () {
        Route::get('/home', 'home')->name('home');
        Route::post('/checkin', 'checkin')->name('checkin');
        Route::get('/attendance/{classId}', 'attendance')->name('attendance');
        Route::post('/attendance/{classId}', 'attendanceStore')->name('attendance.store');
        Route::get('/homework/{classId}', 'homework')->name('homework');
        Route::post('/homework/{classId}', 'homeworkStore')->name('homework.store');
        Route::delete('/homework/{classId}/{homeworkId}', 'homeworkDestroy')->name('homework.destroy');
        Route::get('/scores/{classId}', 'scores')->name('scores');
        Route::post('/scores/{classId}', 'scoresStore')->name('scores.store');
        Route::get('/remarks/{classId}', 'remarks')->name('remarks');
        Route::post('/remarks/{classId}', 'remarksStore')->name('remarks.store');
        Route::get('/order-test/{classId}', 'orderTest')->name('order-test');
        Route::post('/order-test/{classId}', 'submitOrderTest')->name('order-test.submit');
        Route::get('/big-test-report', 'bigTestReport')->name('big-test-report');
        Route::get('/general-report', 'generalReport')->name('general-report');
    });
    // Khách học thử trên buổi dạy của giáo viên + phản hồi gắn với lead
    Route::get('/teacher/trial-guests', [TrialGuestController::class, 'index'])->name('teacher.trial-guests');
    Route::post('/teacher/trial-guests/{booking}/feedback', [TrialGuestController::class, 'feedback'])->name('teacher.trial-guests.feedback');

    // ──────────────────────────────────────
    // Báo cáo & Nhật ký (Học vụ ngày / Học thuật tuần / GV tháng / Admin tổng)
    // ──────────────────────────────────────
    Route::controller(StaffReportController::class)->prefix('reports')->name('reports.')->group(function () {
        Route::get('/journal', 'journal')->name('journal');
        Route::post('/journal', 'journalStore')->name('journal.store');
        Route::post('/journal/{id}/followup', 'journalFollowup')->name('journal.followup');
        Route::post('/journal/{id}/status', 'journalStatus')->name('journal.status');
        Route::get('/my', 'myReports')->name('my');
        Route::post('/my', 'reportStore')->name('my.store');
        Route::get('/all', 'allReports')->name('all');
    });

    // ──────────────────────────────────────
    // KPI Học vụ: cấu hình chỉ số, đánh giá tháng, rà soát điểm danh
    // ──────────────────────────────────────
    Route::controller(KpiController::class)->prefix('kpi')->name('kpi.')->group(function () {
        Route::get('/criteria', 'criteria')->middleware('can:kpi.view')->name('criteria');
        Route::post('/criteria', 'criteriaStore')->middleware('can:kpi.manage')->name('criteria.store');
        Route::put('/criteria/{id}', 'criteriaUpdate')->middleware('can:kpi.manage')->name('criteria.update');
        Route::delete('/criteria/{id}', 'criteriaDestroy')->middleware('can:kpi.manage')->name('criteria.destroy');
        Route::get('/monthly', 'monthly')->middleware('can:kpi.view')->name('monthly');
        Route::get('/evaluate/{userId}', 'evaluate')->middleware('can:kpi.view')->name('evaluate');
        Route::post('/evaluate/{userId}', 'evaluateStore')->middleware('can:kpi.confirm')->name('evaluate.store');
        Route::get('/attendance-review', 'attendanceReview')->middleware('can:kpi.view')->name('attendance-review');
        Route::post('/attendance-review/{id}', 'reviewAttendance')->middleware('can:kpi.confirm')->name('attendance-review.update');
    });

    // ─────────────────────────────────────────────
    // 13. Quản lý Media & Tệp tin lưu trữ trên đĩa
    // ─────────────────────────────────────────────
    Route::prefix('media')->name('media.')->middleware('can:media.view')->group(function () {
        Route::get('/', [MediaManagerController::class, 'index'])->name('index');
        Route::post('/upload', [MediaManagerController::class, 'upload'])->middleware('can:media.upload')->name('upload');
        Route::post('/create-folder', [MediaManagerController::class, 'createFolder'])->middleware('can:media.manage')->name('create-folder');
        Route::delete('/delete-folder', [MediaManagerController::class, 'deleteFolder'])->middleware('can:media.delete')->name('delete-folder');
        Route::post('/move-files', [MediaManagerController::class, 'moveFiles'])->middleware('can:media.manage')->name('move-files');
        Route::delete('/bulk-destroy', [MediaManagerController::class, 'bulkDestroy'])->middleware('can:media.delete')->name('bulk-destroy');
        Route::delete('/destroy-filtered', [MediaManagerController::class, 'destroyFiltered'])->middleware('can:media.delete')->name('destroy-filtered');
        Route::get('/{id}/download', [MediaManagerController::class, 'download'])->name('download');
        Route::delete('/{id}', [MediaManagerController::class, 'destroy'])->middleware('can:media.delete')->name('destroy');
    });
});

// Cổng làm bài Test trực tuyến cho Lead / Học viên (Công khai)
Route::prefix('portal/placement-test')->name('portal.test.')->group(function () {
    Route::get('/{code}', [PlacementTestController::class, 'portalTakeTest'])->middleware('throttle:30,1')->name('take');
    Route::get('/{code}/submit', [PlacementTestController::class, 'portalSubmitTest'])->middleware('throttle:30,1');
    Route::post('/{code}/submit', [PlacementTestController::class, 'portalSubmitTest'])->middleware('throttle:10,1')->name('submit');
    // Scorecard chứa điểm số/PII của lead nên yêu cầu link có chữ ký, không cho dò id
    Route::get('/scorecard/{id}', [PlacementTestController::class, 'portalScorecard'])->name('scorecard')->middleware(['signed', 'throttle:30,1']);
    Route::get('/results/{id}', [PlacementTestController::class, 'portalScorecard'])->name('results')->middleware(['signed', 'throttle:30,1']);
});

Route::middleware(['auth'])->group(function () {
    Route::get('/academic/reports', [AcademicDashboardController::class, 'reports'])->middleware('can:class.update')->name('academic.reports');
    Route::get('/academic/incidents', [AcademicDashboardController::class, 'incidents'])->middleware('can:class.update')->name('academic.incidents');
    Route::get('/syllabus', [SyllabusController::class, 'documents'])->name('syllabus.index');
    Route::get('/portal/student/home', [StudentPortalController::class, 'studentHome'])->name('portal.student.home2');
    Route::get('/portal/student/homework', [StudentPortalController::class, 'studentHomework'])->name('portal.student.homework2');
    Route::get('/portal/student/pronunciation', [StudentPortalController::class, 'studentPronunciation'])->name('portal.student.pronunciation2');
    Route::get('/portal/student/notifications', [StudentPortalController::class, 'studentNotifications'])->name('portal.student.notifications2');
    Route::get('/portal/student/survey', [StudentPortalController::class, 'studentSurvey'])->name('portal.student.survey2');
    Route::get('/portal/student/feedback', [StudentPortalController::class, 'studentFeedback'])->name('portal.student.feedback2');
});

require __DIR__.'/auth.php';
