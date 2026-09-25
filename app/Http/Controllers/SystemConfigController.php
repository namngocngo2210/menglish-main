<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\DebtReminderRule;
use App\Models\SepayConfiguration;
use App\Models\SepayTransaction;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SystemConfigController extends Controller
{
    public function bankAccounts()
    {
        $accounts = BankAccount::with('branch')->get();
        $branches = Branch::all();
        // Không dùng getActiveConfig() trực tiếp: hàm đó tự tạo row khi bảng trống và từng gây 500
        // khi thiếu SEPAY_WEBHOOK_SECRET. Tab SePay chỉ hiện khi webhook được bật qua env.
        $sepayEnabled = (bool) config('services.sepay.webhook_enabled', false);
        $sepayConfig = $sepayEnabled
            ? (SepayConfiguration::first() ?? SepayConfiguration::getActiveConfig())
            : null;
        $recentTransactions = $sepayEnabled ? SepayTransaction::latest()->take(15)->get() : collect();

        return view('system-config.bank-accounts', compact('accounts', 'branches', 'sepayConfig', 'recentTransactions', 'sepayEnabled'));
    }

    public function storeBankAccount(Request $request)
    {
        $validated = $request->validate([
            'bank_code' => 'required|string|max:20',
            'bank_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:50',
            'account_holder' => 'required|string|max:255',
            'branch_location' => 'nullable|string|max:255',
            'branch_id' => 'nullable|exists:branches,id',
            'is_default_vietqr' => 'nullable|boolean',
        ]);

        $isDefault = $request->boolean('is_default_vietqr');
        if ($isDefault) {
            BankAccount::query()->update(['is_default_vietqr' => false]);
        }

        $acc = BankAccount::create([
            'bank_code' => strtoupper($validated['bank_code']),
            'bank_name' => $validated['bank_name'],
            'account_number' => $validated['account_number'],
            'account_holder' => strtoupper($validated['account_holder']),
            'branch_location' => $validated['branch_location'] ?? null,
            'branch_id' => ! empty($validated['branch_id']) ? $validated['branch_id'] : null,
            'is_default_vietqr' => $isDefault || BankAccount::count() === 0,
            'is_active' => true,
        ]);

        return redirect()->route('system-config.bank-accounts')
            ->with('status', "Đã thêm tài khoản ngân hàng {$acc->bank_name} ({$acc->account_number}) thành công!");
    }

    public function updateBankAccount(Request $request, $id)
    {
        $acc = BankAccount::findOrFail($id);

        $validated = $request->validate([
            'bank_code' => 'required|string|max:20',
            'bank_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:50',
            'account_holder' => 'required|string|max:255',
            'branch_location' => 'nullable|string|max:255',
            'branch_id' => 'nullable|exists:branches,id',
            'is_default_vietqr' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $isDefault = $request->boolean('is_default_vietqr');
        if ($isDefault) {
            BankAccount::where('id', '!=', $id)->update(['is_default_vietqr' => false]);
        }

        $acc->update([
            'bank_code' => strtoupper($validated['bank_code']),
            'bank_name' => $validated['bank_name'],
            'account_number' => $validated['account_number'],
            'account_holder' => strtoupper($validated['account_holder']),
            'branch_location' => $validated['branch_location'] ?? null,
            'branch_id' => ! empty($validated['branch_id']) ? $validated['branch_id'] : null,
            'is_default_vietqr' => $isDefault ? true : $acc->is_default_vietqr,
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : true,
        ]);

        return redirect()->route('system-config.bank-accounts')
            ->with('status', "Đã cập nhật thông tin tài khoản ngân hàng {$acc->bank_name} ({$acc->account_number}) thành công!");
    }

    public function destroyBankAccount($id)
    {
        $acc = BankAccount::findOrFail($id);
        $name = $acc->bank_name.' ('.$acc->account_number.')';
        $acc->delete();

        // Ensure at least one default remains if any left
        if ($acc->is_default_vietqr && BankAccount::count() > 0) {
            BankAccount::first()->update(['is_default_vietqr' => true]);
        }

        return redirect()->route('system-config.bank-accounts')
            ->with('status', "Đã xóa tài khoản ngân hàng {$name}!");
    }

    public function setDefaultBankAccount($id)
    {
        BankAccount::query()->update(['is_default_vietqr' => false]);
        $acc = BankAccount::findOrFail($id);
        $acc->update(['is_default_vietqr' => true, 'is_active' => true]);

        return redirect()->route('system-config.bank-accounts')
            ->with('status', "Đã thiết lập {$acc->bank_name} ({$acc->account_number}) làm tài khoản nhận tiền mặc định VietQR!");
    }

    public function updateSepayConfig(Request $request)
    {
        $config = SepayConfiguration::getActiveConfig();

        $validated = $request->validate([
            'webhook_name' => 'required|string|max:255',
            'webhook_url' => 'required|url|max:500',
            'transaction_type' => 'required|in:in,out,all',
            'data_format' => 'required|string',
            'auth_method' => 'required|in:hmac_sha256,api_key,none',
            'secret_key' => 'required|string|max:255',
            'api_key' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'auto_retry' => 'nullable|boolean',
        ]);

        $config->update([
            'webhook_name' => $validated['webhook_name'],
            'webhook_url' => $validated['webhook_url'],
            'transaction_type' => $validated['transaction_type'],
            'data_format' => $validated['data_format'],
            'auth_method' => $validated['auth_method'],
            'secret_key' => $validated['secret_key'],
            'api_key' => $validated['api_key'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'auto_retry' => $request->boolean('auto_retry', true),
        ]);

        return redirect()->route('system-config.bank-accounts')
            ->with('status', 'Đã lưu cấu hình kết nối SePay Gateway thành công! Bạn có thể copy thông tin này lên SePay.');
    }

    public function debtReminders()
    {
        $rules = DebtReminderRule::query()->get()
            ->sortBy(fn (DebtReminderRule $rule) => $rule->effectiveOffset() ?? PHP_INT_MAX)
            ->values();
        $mustContactDays = (int) SystemSetting::get('debt_reminder.must_contact_days', config('tuition.overdue_serious_days', 7));

        return view('system-config.debt-reminders', compact('rules', 'mustContactDays'));
    }

    /**
     * Lưu (thêm mới / cập nhật theo milestone_key) một mốc nhắc nợ: thời điểm so với hạn đóng, kênh gửi, mẫu tin.
     * Mẫu tin chỉ được dùng các biến hệ thống thay được, để tin gửi đi không còn nguyên {BIEN}.
     */
    public function storeDebtReminder(Request $request)
    {
        $validated = $request->validate([
            'milestone_key' => 'nullable|string|max:30',
            'title' => 'required|string|max:255',
            'template_content' => 'required|string|max:2000',
            'timing' => 'nullable|in:before,due,after',
            'days' => 'nullable|integer|min:1|max:60',
            'channels' => 'nullable|array',
            'channels.*' => 'in:'.implode(',', array_keys(DebtReminderRule::CHANNELS)),
            'is_enabled' => 'nullable|boolean',
        ], [
            'days.min' => 'Số ngày phải từ 1 đến 60.',
            'days.max' => 'Số ngày phải từ 1 đến 60.',
            'channels.*.in' => 'Kênh gửi không hợp lệ.',
        ]);

        if ($unknown = DebtReminderRule::unknownVariables($validated['template_content'])) {
            return redirect()->back()->withErrors([
                'template_content' => 'Mẫu tin có biến hệ thống không hỗ trợ: '.implode(', ', $unknown)
                    .'. Chỉ dùng: '.implode(', ', array_keys(DebtReminderRule::VARIABLES)).'.',
            ])->withInput();
        }

        $offset = null;
        if (! empty($validated['timing'])) {
            if ($validated['timing'] !== 'due' && empty($validated['days'])) {
                return redirect()->back()->withErrors(['days' => 'Vui lòng nhập số ngày trước / sau hạn đóng.'])->withInput();
            }
            $offset = match ($validated['timing']) {
                'before' => -1 * (int) $validated['days'],
                'after' => (int) $validated['days'],
                default => 0,
            };
        }

        $key = $validated['milestone_key'] ?? null;
        $existing = $key ? DebtReminderRule::where('milestone_key', $key)->first() : null;
        $offset ??= $existing?->offset_days ?? DebtReminderRule::offsetFromKey($key);
        if ($offset === null) {
            return redirect()->back()->withErrors(['timing' => 'Vui lòng chọn thời điểm gửi (trước hạn / đúng hạn / quá hạn).'])->withInput();
        }
        $key ??= 'T'.($offset > 0 ? '+' : '').$offset;

        $duplicate = DebtReminderRule::query()
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
            ->where('milestone_key', '!=', $key)
            ->get()
            ->first(fn (DebtReminderRule $rule) => $rule->effectiveOffset() === $offset);
        if ($duplicate) {
            return redirect()->back()->withErrors(['days' => "Đã có mốc {$duplicate->milestone_key} ({$duplicate->offset_label}). Mỗi thời điểm chỉ một mốc nhắc."])->withInput();
        }

        $rule = DebtReminderRule::updateOrCreate(
            ['milestone_key' => $key],
            [
                'title' => $validated['title'],
                'template_content' => $validated['template_content'],
                'offset_days' => $offset,
                'channels' => $request->has('channels') || $request->boolean('channels_submitted')
                    ? array_values($validated['channels'] ?? [])
                    : ($existing?->channels ?? DebtReminderRule::DEFAULT_CHANNELS),
                'is_enabled' => $request->has('is_enabled') ? $request->boolean('is_enabled') : ($existing?->is_enabled ?? true),
            ]
        );

        return redirect()->route('system-config.debt-reminders')
            ->with('status', "Đã lưu mốc nhắc nợ {$rule->milestone_key} ({$rule->offset_label}) thành công!");
    }

    /** Ngưỡng "quá hạn bắt buộc liên hệ" dùng để chia nhóm danh sách thu phí quá hạn. */
    public function updateDebtReminderSettings(Request $request)
    {
        $validated = $request->validate([
            'must_contact_days' => 'required|integer|min:1|max:60',
        ], [
            'must_contact_days.min' => 'Giá trị không hợp lệ. Vui lòng nhập trong khoảng từ 1–60 ngày.',
            'must_contact_days.max' => 'Giá trị không hợp lệ. Vui lòng nhập trong khoảng từ 1–60 ngày.',
        ]);

        SystemSetting::set('debt_reminder.must_contact_days', (int) $validated['must_contact_days'], 'Số ngày quá hạn phải gọi điện liên hệ trực tiếp (nhóm quá hạn nghiêm trọng).');

        return redirect()->route('system-config.debt-reminders')
            ->with('status', 'Đã lưu mốc quá hạn bắt buộc liên hệ: '.$validated['must_contact_days'].' ngày.');
    }

    /**
     * Display ticket notification email configuration
     */
    public function ticketEmails()
    {
        $emails = SystemSetting::getTicketEmails();
        $isCreatedEnabled = SystemSetting::isTicketEventEnabled('created');
        $isCommentEnabled = SystemSetting::isTicketEventEnabled('comment');
        $isStatusChangedEnabled = SystemSetting::isTicketEventEnabled('status_changed');
        $isStaleLeadEnabled = SystemSetting::isTicketEventEnabled('stale_lead');
        $isTransactionEnabled = SystemSetting::isTicketEventEnabled('transaction');
        $isOverdueDebtEnabled = SystemSetting::isTicketEventEnabled('overdue_debt');
        $isHomeworkEnabled = SystemSetting::isTicketEventEnabled('homework');

        $smtp = SystemSetting::getSmtpConfig();

        $mailConfig = [
            'driver' => $smtp['mailer'],
            'host' => $smtp['host'],
            'port' => $smtp['port'],
            'encryption' => $smtp['encryption'],
            'username' => $smtp['username'],
            'password' => $smtp['password'],
            'has_password' => $smtp['has_password'],
            'from_address' => $smtp['from_address'],
            'from_name' => $smtp['from_name'],
            'env_default_email' => config('mail.tech_support_email', env('TECH_SUPPORT_EMAIL', 'tech.vmst@gmail.com')),
        ];

        return view('system-config.ticket-emails', compact(
            'emails',
            'isCreatedEnabled',
            'isCommentEnabled',
            'isStatusChangedEnabled',
            'isStaleLeadEnabled',
            'isTransactionEnabled',
            'isOverdueDebtEnabled',
            'isHomeworkEnabled',
            'mailConfig'
        ));
    }

    /**
     * Update recipient emails, notification triggers, and SMTP outgoing mail settings in Database
     */
    public function updateTicketEmails(Request $request)
    {
        // 1. Recipient emails list
        $rawEmails = $request->input('emails');
        $emails = [];

        if (is_array($rawEmails)) {
            $emails = $rawEmails;
        } elseif (is_string($rawEmails)) {
            $emails = preg_split('/[,\n;]+/', $rawEmails);
        }

        $validEmails = [];
        if (! empty($emails)) {
            foreach ($emails as $email) {
                $email = trim(strtolower((string) $email));
                if (! empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $validEmails[] = $email;
                }
            }
        }
        $validEmails = array_values(array_unique($validEmails));

        SystemSetting::set(
            'ticket_notification_emails',
            $validEmails,
            'Danh sách email nhận thông báo khi có ticket hỗ trợ kỹ thuật / báo lỗi'
        );

        SystemSetting::set(
            'ticket_notify_created',
            $request->boolean('notify_created'),
            'Thông báo khi có ticket mới'
        );

        SystemSetting::set(
            'ticket_notify_comment',
            $request->boolean('notify_comment'),
            'Thông báo khi có tin nhắn / bình luận mới trong ticket'
        );

        SystemSetting::set(
            'ticket_notify_status_changed',
            $request->boolean('notify_status_changed'),
            'Thông báo khi trạng thái ticket thay đổi'
        );

        SystemSetting::set(
            'ticket_notify_stale_lead',
            $request->boolean('notify_stale_lead'),
            'Thông báo khi có lead CRM bị trễ >24h chưa xử lý'
        );

        SystemSetting::set(
            'ticket_notify_transaction',
            $request->boolean('notify_transaction'),
            'Thông báo khi có giao dịch thanh toán / phiếu thu học phí mới'
        );

        SystemSetting::set(
            'ticket_notify_overdue_debt',
            $request->boolean('notify_overdue_debt'),
            'Thông báo khi có phiếu thu / công nợ trễ hẹn'
        );

        SystemSetting::set(
            'ticket_notify_homework',
            $request->boolean('notify_homework'),
            'Thông báo khi có học viên nộp bài / trễ nộp bài tập hoặc kiểm tra'
        );

        // 2. Outgoing SMTP Mail Settings stored directly in Database (no .env required)
        if ($request->has('mail_host')) {
            $host = trim((string) $request->input('mail_host'));
            if (! empty($host)) {
                SystemSetting::set('mail_host', $host, 'Máy chủ gửi thư SMTP');
            }
        }

        if ($request->has('mail_port')) {
            $port = (int) $request->input('mail_port');
            if ($port > 0) {
                SystemSetting::set('mail_port', (string) $port, 'Cổng kết nối SMTP');
            }
        }

        if ($request->has('mail_encryption')) {
            $encryption = trim((string) $request->input('mail_encryption'));
            SystemSetting::set('mail_encryption', $encryption, 'Giao thức mã hóa SMTP (tls/ssl/null)');
        }

        if ($request->has('mail_username')) {
            $username = trim((string) $request->input('mail_username'));
            SystemSetting::set('mail_username', $username, 'Tài khoản / Email đăng nhập gửi thư');
        }

        // Only update password if a new one was provided
        if ($request->filled('mail_password')) {
            $password = trim((string) $request->input('mail_password'));
            // Remove spaces in case user pasted a 16-char Google App Password with spaces ("xxxx xxxx xxxx xxxx")
            $sanitizedPassword = str_replace(' ', '', $password);
            SystemSetting::set('mail_password', $sanitizedPassword, 'Mật khẩu ứng dụng / Mật khẩu SMTP');
        }

        if ($request->has('mail_from_address')) {
            $fromAddress = trim((string) $request->input('mail_from_address'));
            if (! empty($fromAddress)) {
                SystemSetting::set('mail_from_address', $fromAddress, 'Địa chỉ email người gửi hiển thị');
            }
        }

        if ($request->has('mail_from_name')) {
            $fromName = trim((string) $request->input('mail_from_name'));
            if (! empty($fromName)) {
                SystemSetting::set('mail_from_name', $fromName, 'Tên người gửi hiển thị');
            }
        }

        // Apply dynamic SMTP settings immediately to runtime
        SystemSetting::applyDynamicMailConfig();

        return redirect()->route('system-config.ticket-emails')
            ->with('status', 'Đã lưu cấu hình danh sách email nhận và tài khoản gửi thư SMTP vào Database thành công!');
    }

    /**
     * Send a test ticket notification email to verify SMTP and recipient setup
     */
    public function sendTestTicketEmail(Request $request)
    {
        $testEmail = trim((string) $request->input('test_email'));
        $recipients = [];

        if (! empty($testEmail)) {
            if (! filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                return redirect()->route('system-config.ticket-emails')
                    ->with('error', "Địa chỉ email kiểm tra \"{$testEmail}\" không đúng định dạng!");
            }
            $recipients = [$testEmail];
        } else {
            $recipients = SystemSetting::getTicketEmails();
        }

        if (empty($recipients)) {
            return redirect()->route('system-config.ticket-emails')
                ->with('error', 'Chưa có email nào trong danh sách để gửi thử nghiệm!');
        }

        try {
            SystemSetting::applyDynamicMailConfig();
            $smtp = SystemSetting::getSmtpConfig();

            $subject = '🧪 [MEnglish Test] Kiểm tra kết nối Email nhận Ticket';
            $senderUser = auth()->user()?->name ?? 'Administrator';
            $senderEmail = auth()->user()?->email ?? 'admin';
            $smtpHost = $smtp['host'];
            $smtpPort = $smtp['port'];
            $mailDriver = $smtp['mailer'];

            $htmlBody = view('emails.ticket-notification', [
                'ticket' => (object) [
                    'code' => 'TEST',
                    'title' => 'Kiểm tra đường truyền Email Thông báo Ticket',
                    'priority' => 'high',
                    'priority_label' => 'Thử nghiệm',
                    'category_label' => 'Hệ thống IT',
                    'status' => 'resolved',
                    'status_label' => 'Hoạt động tốt',
                ],
                'type' => 'status',
                'subjectTitle' => $subject,
                'notificationTypeLabel' => 'Email Thử Nghiệm Hệ Thống',
                'priorityLabel' => 'Thử nghiệm',
                'content' => "Cổng gửi thư SMTP đã kết nối thành công qua máy chủ {$smtpHost}:{$smtpPort} (Driver: {$mailDriver}).\nCác email nhận thông báo: ".implode(', ', $recipients),
                'senderName' => "{$senderUser} ({$senderEmail})",
                'actionUrl' => route('system-config.ticket-emails'),
                'actionText' => 'Quản lý Cấu hình Email',
            ])->render();

            foreach ($recipients as $recipient) {
                Mail::html($htmlBody, function ($message) use ($recipient, $subject) {
                    $message->to($recipient)->subject($subject);
                });
            }

            return redirect()->route('system-config.ticket-emails')
                ->with('status', 'Đã gửi email thử nghiệm thành công tới: '.implode(', ', $recipients));
        } catch (\Throwable $e) {
            return redirect()->route('system-config.ticket-emails')
                ->with('error', 'Gửi email thử nghiệm thất bại: '.$e->getMessage());
        }
    }

    /**
     * Display Hosting, Server Specs, Disk Quota, PHP & Laravel Diagnostics
     */
    public function hostingInfo()
    {
        // 1. Dung lượng lưu trữ thực tế của website theo từng thành phần
        $uploadsSize = $this->getDirectorySize(public_path('uploads'));
        $storageSize = $this->getDirectorySize(storage_path());
        $vendorSize = $this->getDirectorySize(base_path('vendor'));
        $appSize = $this->getDirectorySize(app_path()) + $this->getDirectorySize(resource_path()) + $this->getDirectorySize(database_path());
        $sourceVendorSize = $vendorSize + $appSize;

        // 2. Cơ sở dữ liệu
        $dbVersion = 'Unknown';
        $dbSizeMb = 0;
        $dbTablesCount = 0;
        try {
            $dbVersion = DB::select('SELECT VERSION() as v')[0]->v ?? 'Unknown';
            $dbStats = DB::select('SELECT COUNT(*) as tbl_count, SUM(data_length + index_length) / 1024 / 1024 AS size_mb FROM information_schema.TABLES WHERE table_schema = DATABASE()')[0] ?? null;
            if ($dbStats) {
                $dbTablesCount = $dbStats->tbl_count ?? 0;
                $dbSizeMb = round((float) ($dbStats->size_mb ?? 0), 2);
            }
        } catch (\Exception $e) {
            // DB fallback
        }

        $dbSizeBytes = (int) ($dbSizeMb * 1024 * 1024);
        $totalAppUsageBytes = $uploadsSize + $storageSize + $sourceVendorSize + $dbSizeBytes;

        $safeTotal = max(1, $totalAppUsageBytes);
        $uploadsPercent = round(($uploadsSize / $safeTotal) * 100, 1);
        $sourceVendorPercent = round(($sourceVendorSize / $safeTotal) * 100, 1);
        $storagePercent = round(($storageSize / $safeTotal) * 100, 1);
        $dbPercent = round(($dbSizeBytes / $safeTotal) * 100, 1);

        // 3. Web server & Hosting panel (Đọc chính xác từ Header Server & Môi trường thực tế)
        $serverDetails = $this->detectWebServerDetails();

        // 4. PHP Extensions status
        $extensionsToCheck = [
            'pdo_mysql' => 'PDO MySQL Driver',
            'openssl' => 'OpenSSL Security',
            'curl' => 'cURL HTTP Client',
            'gd' => 'GD Image Processing',
            'imagick' => 'ImageMagick',
            'zip' => 'Zip Archive Handler',
            'mbstring' => 'Multibyte String (UTF-8)',
            'intl' => 'Internationalization (Intl)',
            'bcmath' => 'BCMath Arbitrary Precision',
            'xml' => 'XML Parser & DOM',
            'fileinfo' => 'MIME Fileinfo Detection',
            'exif' => 'EXIF Metadata Reader',
            'Zend OPcache' => 'OPcache Accelerator',
        ];

        $extensionStatuses = [];
        foreach ($extensionsToCheck as $ext => $label) {
            $extensionStatuses[$ext] = [
                'label' => $label,
                'enabled' => extension_loaded($ext),
            ];
        }

        // 5. Health checks
        $healthChecks = [
            'storage_writable' => is_writable(storage_path()),
            'cache_writable' => is_writable(base_path('bootstrap/cache')),
            'uploads_writable' => is_writable(public_path('uploads')),
            'db_connected' => true,
            'https_active' => request()->isSecure(),
        ];

        $storageStats = [
            'total_used_bytes' => $totalAppUsageBytes,
            'total_used_formatted' => $this->formatBytes($totalAppUsageBytes),
            'uploads_size_formatted' => $this->formatBytes($uploadsSize),
            'uploads_percent' => $uploadsPercent,
            'storage_size_formatted' => $this->formatBytes($storageSize),
            'storage_percent' => $storagePercent,
            'vendor_size_formatted' => $this->formatBytes($vendorSize),
            'app_size_formatted' => $this->formatBytes($appSize),
            'source_vendor_size_formatted' => $this->formatBytes($sourceVendorSize),
            'source_vendor_percent' => $sourceVendorPercent,
            'db_size_mb' => $dbSizeMb,
            'db_size_formatted' => $this->formatBytes($dbSizeBytes),
            'db_percent' => $dbPercent,
        ];

        $serverSpecs = [
            'php_version' => PHP_VERSION,
            'php_sapi' => php_sapi_name(),
            'laravel_version' => app()->version(),
            'app_env' => config('app.env'),
            'app_debug' => config('app.debug'),
            'app_timezone' => config('app.timezone'),
            'app_url' => config('app.url'),
            'server_software' => $serverDetails['raw_software'],
            'web_server_name' => $serverDetails['type'],
            'hosting_panel' => $serverDetails['panel'],
            'os_name' => PHP_OS.' ('.php_uname('s').' '.php_uname('r').' '.php_uname('m').')',
            'server_ip' => $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname()),
            'server_port' => $_SERVER['SERVER_PORT'] ?? 443,
            'server_protocol' => $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1',
            'hostname' => gethostname(),
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_execution_time' => ini_get('max_execution_time').'s',
            'max_input_vars' => ini_get('max_input_vars'),
            'db_connection' => config('database.default'),
            'db_host' => config('database.connections.mysql.host').':'.config('database.connections.mysql.port'),
            'db_database' => config('database.connections.mysql.database'),
            'db_version' => $dbVersion,
            'db_tables_count' => $dbTablesCount,
            'mail_driver' => config('mail.default'),
            'mail_host' => config('mail.mailers.smtp.host'),
            'session_driver' => config('session.driver'),
            'cache_driver' => config('cache.default'),
            'queue_driver' => config('queue.default'),
        ];

        return view('system-config.hosting', compact('storageStats', 'serverSpecs', 'extensionStatuses', 'healthChecks', 'serverDetails'));
    }

    private function detectWebServerDetails(): array
    {
        $serverSoftware = request()->server('SERVER_SOFTWARE')
            ?? $_SERVER['SERVER_SOFTWARE']
            ?? getenv('SERVER_SOFTWARE')
            ?? (function_exists('apache_get_version') ? apache_get_version() : null);

        $sapi = php_sapi_name();

        // 1. Phân loại Web Server chính xác
        $webServerType = 'Không xác định (Unknown)';
        $isLiteSpeed = false;

        if ($sapi === 'litespeed' || (is_string($serverSoftware) && (stripos($serverSoftware, 'LiteSpeed') !== false || stripos($serverSoftware, 'openlitespeed') !== false)) || $this->safeIsDir('/usr/local/lsws')) {
            $webServerType = 'OpenLiteSpeed / LiteSpeed Web Server';
            $isLiteSpeed = true;
            if (empty($serverSoftware) || $serverSoftware === 'cli') {
                $serverSoftware = 'OpenLiteSpeed (LiteSpeed SAPI)';
            }
        } elseif (is_string($serverSoftware) && stripos($serverSoftware, 'nginx') !== false) {
            $webServerType = 'Nginx Web Server';
        } elseif (is_string($serverSoftware) && (stripos($serverSoftware, 'apache') !== false || function_exists('apache_get_version'))) {
            $webServerType = 'Apache HTTP Server';
        } elseif (is_string($serverSoftware) && stripos($serverSoftware, 'caddy') !== false) {
            $webServerType = 'Caddy Web Server';
        } elseif ($sapi === 'cli-server' || (is_string($serverSoftware) && stripos($serverSoftware, 'Development Server') !== false)) {
            $webServerType = 'PHP Built-in Development Server';
            $serverSoftware = $serverSoftware ?: ('PHP '.PHP_VERSION.' Development Server');
        } elseif ($sapi === 'fpm-fcgi') {
            $webServerType = 'PHP-FPM (FastCGI Server)';
            $serverSoftware = $serverSoftware ?: 'PHP-FPM / FastCGI';
        } elseif ($sapi === 'cli') {
            $webServerType = 'PHP Command Line Interface (CLI)';
            $serverSoftware = $serverSoftware ?: 'PHP CLI';
        }

        // 2. Nhận diện Hosting Control Panel
        $docRoot = request()->server('DOCUMENT_ROOT') ?? $_SERVER['DOCUMENT_ROOT'] ?? base_path();
        $basePath = base_path();
        $hostingPanel = 'Linux / Dedicated Server';
        $isDirectAdmin = false;

        if (str_contains($docRoot, '/domains/') || str_contains($basePath, '/domains/') || getenv('DIRECTADMIN') || isset($_SERVER['DIRECTADMIN']) || $this->safeIsDir('/usr/local/directadmin')) {
            $hostingPanel = 'DirectAdmin Control Panel';
            $isDirectAdmin = true;
        } elseif ((str_contains($docRoot, '/home/') && ! str_contains($docRoot, '/domains/')) || $this->safeIsDir('/usr/local/cpanel')) {
            $hostingPanel = 'cPanel Control Panel';
        } elseif ($this->safeFileExists('/.dockerenv')) {
            $hostingPanel = 'Docker Container Environment';
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $hostingPanel = 'macOS Local Environment (Development)';
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $hostingPanel = 'Windows Environment';
        }

        return [
            'raw_software' => $serverSoftware ?: 'Unknown Web Server',
            'type' => $webServerType,
            'panel' => $hostingPanel,
            'is_litespeed' => $isLiteSpeed,
            'is_directadmin' => $isDirectAdmin,
            'sapi' => $sapi,
        ];
    }

    private function safeIsDir(string $path): bool
    {
        try {
            return @is_dir($path);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function safeFileExists(string $path): bool
    {
        try {
            return @file_exists($path);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getDirectorySize(string $path): int
    {
        $size = 0;
        if (! file_exists($path)) {
            return 0;
        }
        if (is_file($path)) {
            return filesize($path);
        }
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Exception $e) {
            // Ignore unreadable dirs
        }

        return $size;
    }

    private function formatBytes(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision).' '.$units[$pow];
    }
}
