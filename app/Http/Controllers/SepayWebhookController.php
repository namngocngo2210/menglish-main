<?php

namespace App\Http\Controllers;

use App\Models\AdminNotification;
use App\Models\CrmCustomer;
use App\Models\InvoiceConfiguration;
use App\Models\SepayConfiguration;
use App\Models\SepayTransaction;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SepayWebhookController extends Controller
{
    /**
     * Handle incoming SePay webhook
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        // Cờ tắt toàn bộ webhook (SEPAY_WEBHOOK_ENABLED): trả lời sạch 503 để SePay retry sau,
        // không rơi vào 500 khi cấu hình chưa có secret.
        if (! config('services.sepay.webhook_enabled', false)) {
            return response()->json([
                'success' => false,
                'message' => 'SePay webhook hiện đang tắt trên hệ thống.',
            ], 503);
        }

        $rawPayload = $request->getContent();
        $config = SepayConfiguration::getActiveConfig();

        if (! $config->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'SePay Webhook is currently disabled in system settings.',
            ], 403);
        }

        // 1. Authentication is mandatory unless the administrator explicitly
        // selects "none". Missing credentials must never fall through.
        if ($config->auth_method === 'hmac_sha256') {
            if (empty($config->secret_key)) {
                Log::error('[SePay Webhook] HMAC is enabled without a secret key.');

                return response()->json(['success' => false, 'message' => 'Webhook authentication is not configured.'], 503);
            }

            $receivedSignature = $request->header('X-SePay-Signature')
                ?: $request->header('x-sepay-signature')
                ?: $request->header('X-Signature');

            if (! $receivedSignature) {
                return response()->json(['success' => false, 'message' => 'Thiếu chữ ký xác thực webhook.'], 401);
            }

            $receivedSignature = preg_replace('/\Asha256=/i', '', trim($receivedSignature));
            $computedSignature = hash_hmac('sha256', $rawPayload, $config->secret_key);
            if (! hash_equals($computedSignature, $receivedSignature)) {
                Log::warning('[SePay Webhook] Invalid HMAC signature');

                return response()->json([
                    'success' => false,
                    'message' => 'Chữ ký xác thực HMAC-SHA256 không hợp lệ!',
                ], 401);
            }
        } elseif ($config->auth_method === 'api_key') {
            $expectedApiKey = (string) $config->api_key;
            $authorization = trim((string) $request->header('Authorization'));
            $receivedApiKey = (string) ($request->header('X-API-Key')
                ?: preg_replace('/\A(?:Bearer|Apikey)\s+/i', '', $authorization));

            if ($expectedApiKey === '' || $receivedApiKey === '' || ! hash_equals($expectedApiKey, $receivedApiKey)) {
                return response()->json(['success' => false, 'message' => 'API key không hợp lệ.'], 401);
            }
        }

        // 2. Parse payload
        $data = $request->json()->all();
        if (empty($data)) {
            $data = $request->all();
        }

        $sepayId = $data['id'] ?? $data['transaction_id'] ?? null;
        $gateway = $data['gateway'] ?? $data['bank'] ?? null;
        $accountNumber = $data['accountNumber'] ?? $data['account_number'] ?? null;
        $subAccount = $data['subAccount'] ?? $data['sub_account'] ?? null;
        $transferType = strtolower($data['transferType'] ?? $data['transfer_type'] ?? 'in');
        $transferAmount = (float) ($data['transferAmount'] ?? $data['transfer_amount'] ?? $data['amount'] ?? 0);
        $accumulated = (float) ($data['accumulated'] ?? 0);
        $content = trim($data['content'] ?? $data['description'] ?? '');
        $referenceCode = $data['referenceCode'] ?? $data['reference_code'] ?? null;
        $transactionDate = ! empty($data['transactionDate']) ? Carbon::parse($data['transactionDate']) : now();

        if ($sepayId === null || trim((string) $sepayId) === '') {
            return response()->json(['success' => false, 'message' => 'Thiếu mã giao dịch SePay.'], 422);
        }

        // 3. Prevent duplicate processing
        $existing = SepayTransaction::where('sepay_id', (string) $sepayId)->first();
        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'Giao dịch SePay đã được tiếp nhận trước đó (Duplicate).',
                'transaction_id' => $existing->id,
            ]);
        }

        // 4. Record transaction log (UNIQUE trên sepay_id là chốt chặn cuối chống trùng khi retry đồng thời)
        try {
            $tx = SepayTransaction::createOrFirst([
                'sepay_id' => (string) $sepayId,
            ], [
                'gateway' => $gateway,
                'transaction_date' => $transactionDate,
                'account_number' => $accountNumber,
                'sub_account' => $subAccount,
                'transfer_type' => $transferType,
                'transfer_amount' => $transferAmount,
                'accumulated' => $accumulated,
                'content' => $content,
                'reference_code' => $referenceCode,
                'raw_payload' => $data,
                'status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            $tx = SepayTransaction::where('sepay_id', (string) $sepayId)->first();

            if (! $tx) {
                return response()->json(['success' => false, 'message' => 'Không ghi nhận được giao dịch, vui lòng thử lại.'], 500);
            }
        }

        if (! $tx->wasRecentlyCreated) {
            return response()->json([
                'success' => true,
                'message' => 'Giao dịch SePay đã được tiếp nhận trước đó (Duplicate).',
                'transaction_id' => $tx->id,
            ]);
        }

        // Only process incoming money (transferType == in)
        if ($transferType !== 'in' || $transferAmount <= 0) {
            $tx->update([
                'status' => 'ignored',
                'response_message' => 'Bỏ qua giao dịch tiền ra hoặc số tiền <= 0',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Giao dịch tiền ra được ghi nhận nhưng không xử lý công nợ.',
            ]);
        }

        // 5. Intelligent Matcher: find Student / Tuition by transaction content
        $matchedTuition = null;
        $matchedStudent = null;
        $matchedCustomer = null;

        // a. Search by exact memo if stored in StudentTuition
        if (! empty($content)) {
            $cleanContent = preg_replace('/[^a-zA-Z0-9]/', '', strtoupper($content));

            // Check exact transfer memo match
            $matchedTuition = StudentTuition::whereNotNull('transfer_memo')
                ->where('transfer_memo', '!=', '')
                ->get()
                ->first(function ($t) use ($cleanContent) {
                    $cleanMemo = preg_replace('/[^a-zA-Z0-9]/', '', strtoupper($t->transfer_memo));

                    return ! empty($cleanMemo) && str_contains($cleanContent, $cleanMemo);
                });

            // b. Check student code (HS\d + HV-<ULID>; chấp nhận cả mã không gạch nối)
            if (! $matchedTuition) {
                if (preg_match('/(HS\d+|HV-?[A-Z0-9]{6,})/i', $content, $matches)) {
                    $code = strtoupper(str_replace('-', '', $matches[1]));
                    $student = Student::where('code', strtoupper($matches[1]))
                        ->orWhereRaw("REPLACE(code, '-', '') = ?", [$code])
                        ->first();

                    if ($student) {
                        $matchedStudent = $student;
                        $matchedTuition = StudentTuition::where('student_id', $student->id)
                            ->where('debt_amount', '>', 0)
                            ->latest()
                            ->first()
                            ?: StudentTuition::where('student_id', $student->id)->latest()->first();
                    }
                }
            }

            // c. Check CRM lead code (KH-<ULID>, CRM-<ULID>)
            if (! $matchedTuition) {
                if (preg_match('/(KH-?[A-Z0-9]{6,}|CRM-?[A-Z0-9]{6,})/i', $content, $matches)) {
                    $leadCode = strtoupper($matches[1]);
                    $customer = CrmCustomer::where('code', $leadCode)
                        ->orWhere('code', str_replace('-', '', $leadCode))
                        ->first();

                    if ($customer) {
                        $matchedCustomer = $customer;
                        // Check if customer already has a student record
                        $student = $this->findStudentByPhone($customer->phone);
                        if ($student) {
                            $matchedStudent = $student;
                            $matchedTuition = StudentTuition::where('student_id', $student->id)
                                ->where('debt_amount', '>', 0)
                                ->latest()
                                ->first();
                        }
                    }
                }
            }
        }

        // 6. Execute automated reconciliation if tuition matched
        if ($matchedTuition) {
            $matchedStudent = $matchedStudent ?: $matchedTuition->student;

            // Khoá dòng công nợ để hai webhook song song không nhân đôi phiếu thu, và
            // chỉ ghi nhận đúng phần nợ còn lại — phần khách chuyển thừa đưa ra thông báo đối soát tay.
            $outcome = DB::transaction(function () use ($matchedTuition, $transferAmount, $transactionDate, $sepayId, $referenceCode, $content) {
                $tuition = StudentTuition::whereKey($matchedTuition->id)->lockForUpdate()->first();

                if (! $tuition || (float) $tuition->debt_amount <= 0) {
                    return ['overpaid' => true, 'tuition' => $tuition, 'applied_amount' => 0.0];
                }

                $appliedAmount = round(min($transferAmount, (float) $tuition->debt_amount), 2);
                $invoiceNumber = InvoiceConfiguration::consumeNextInvoiceNumber($tuition->branch_id ?? $tuition->student?->branch_id);
                // whereHas thay vì role(): webhook public không được 500 khi vai trò admin chưa được seed
                $adminUser = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first()
                    ?: User::first();

                $receipt = TuitionReceipt::create([
                    'receipt_number' => TuitionReceipt::generateReceiptNumber(),
                    'invoice_number' => $invoiceNumber,
                    'student_tuition_id' => $tuition->id,
                    'student_id' => $tuition->student_id,
                    'amount' => $appliedAmount,
                    'payment_method' => 'transfer',
                    'transaction_code' => $sepayId ?: ($referenceCode ?: 'SEPAY'.time()),
                    'payment_date' => $transactionDate->toDateString(),
                    'creator_id' => $adminUser?->id,
                    'approver_id' => $adminUser?->id,
                    'status' => 'approved',
                    'notes' => "Thanh toán tự động thành công qua SePay Webhook (ID: {$sepayId}). Nội dung CK: {$content}",
                ]);

                $tuition->recalculateDebt();

                return [
                    'overpaid' => false,
                    'tuition' => $tuition,
                    'receipt' => $receipt,
                    'invoice_number' => $invoiceNumber,
                    'applied_amount' => $appliedAmount,
                ];
            });

            if ($outcome['overpaid']) {
                $tx->update([
                    'status' => 'overpaid',
                    'matched_tuition_id' => $matchedTuition->id,
                    'response_message' => 'Hợp đồng của '.($matchedStudent?->name ?? 'học viên').' đã thanh toán đủ; khoản '.number_format((float) $transferAmount, 0, ',', '.')." VNĐ không được tự động ghi nhận. ND: '{$content}'",
                ]);

                AdminNotification::create([
                    'title' => 'SePay: Thu thêm khi đã hết nợ ('.number_format((float) $transferAmount, 0, ',', '.').' VNĐ)',
                    'message' => 'Học viên '.($matchedStudent?->name ?? '#'.($matchedTuition->student_id ?? '')).' đã hết công nợ nhưng nhận thêm '.number_format((float) $transferAmount, 0, ',', '.')." VNĐ. ND: '{$content}'. Vui lòng đối soát thủ công (hoàn tiền hoặc thu trước kỳ sau).",
                    'type' => 'warning',
                    'is_read' => false,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Hợp đồng đã thanh toán đủ trước đó; giao dịch được lưu chờ đối soát thủ công.',
                    'transaction_id' => $tx->id,
                ]);
            }

            $receipt = $outcome['receipt'];
            $invoiceNumber = $outcome['invoice_number'];
            $appliedAmount = $outcome['applied_amount'];
            $overAmount = round($transferAmount - $appliedAmount, 2);

            // Thanh toán KHÔNG đổi giai đoạn Lead: Đã chốt chỉ qua Chốt & Xếp lớp / Gán lớp (CrmStageService).

            // Update transaction record
            $overNote = $overAmount > 0
                ? ' Khách chuyển thừa '.number_format($overAmount, 0, ',', '.').' VNĐ — cần đối soát thủ công.'
                : '';
            $tx->update([
                'status' => 'matched',
                'matched_student_id' => $matchedStudent?->id,
                'matched_tuition_id' => $matchedTuition->id,
                'matched_receipt_id' => $receipt->id,
                'response_message' => 'Đã gạch nợ thành công '.number_format($appliedAmount, 0, ',', '.')." VNĐ cho học viên {$matchedStudent?->name} ({$matchedStudent?->code}). Xuất HĐĐT số {$invoiceNumber}.{$overNote}",
            ]);

            // Notify Admin & Accountant & Branch Academic Staff
            AdminNotification::create([
                'title' => 'SePay: Khớp thanh toán '.number_format($appliedAmount, 0, ',', '.').' VNĐ',
                'message' => "Học viên {$matchedStudent?->name} ({$matchedStudent?->code}) đã thanh toán thành công qua SePay. Phiếu thu: {$receipt->receipt_number}. HĐĐT: {$invoiceNumber}.",
                'type' => 'success',
                'is_read' => false,
            ]);

            if ($overAmount > 0) {
                AdminNotification::create([
                    'title' => 'SePay: Khách chuyển thừa '.number_format($overAmount, 0, ',', '.').' VNĐ',
                    'message' => "Khoản chuyển của học viên {$matchedStudent?->name} ({$matchedStudent?->code}) vượt số tiền còn nợ ".number_format($overAmount, 0, ',', '.')." VNĐ. Phần thừa chưa ghi nhận vào phiếu thu — vui lòng đối soát thủ công. ND: '{$content}'",
                    'type' => 'warning',
                    'is_read' => false,
                ]);
            }

            try {
                // 1. Dispatch operational transaction alert
                app(NotificationService::class)->notifyTransactionReceipt($receipt);

                // 2. Dispatch verification email to both Academic Staff (branch) and Super Admin to immediately issue electronic invoice
                $recipients = collect();
                $adminEmails = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->pluck('email')->filter();
                $recipients = $recipients->merge($adminEmails);

                if ($matchedTuition->branch_id) {
                    $branchStaffEmails = User::where('branch_id', $matchedTuition->branch_id)
                        ->whereHas('roles', fn ($q) => $q->whereIn('name', ['academic_staff', 'manager', 'accountant']))
                        ->pluck('email')
                        ->filter();
                    $recipients = $recipients->merge($branchStaffEmails);
                }

                $recipients = $recipients->unique()->values();

                if ($recipients->isNotEmpty()) {
                    $subject = "[SePay Khớp Lệnh] Xuất hóa đơn #{$invoiceNumber} - Học viên {$matchedStudent?->name}";
                    $htmlContent = "<div style='font-family: Arial, sans-serif; line-height: 1.6; color: #1e293b; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>"
                        ."<div style='background: #ea580c; color: #ffffff; padding: 15px 20px; border-radius: 8px 8px 0 0; font-size: 18px; font-weight: bold;'>MENGLISH - XÁC THỰC THANH TOÁN SEPAY</div>"
                        ."<div style='padding: 20px; background: #ffffff;'>"
                        .'<p>Kính gửi Quản trị viên & Bộ phận Học vụ / Kế toán cơ sở,</p>'
                        .'<p>Cổng thanh toán tự động <strong>SePay</strong> vừa ghi nhận và khớp thành công giao dịch học phí:</p>'
                        ."<table style='width: 100%; border-collapse: collapse; margin: 15px 0;'>"
                        ."<tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b;'>Học viên:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; font-weight: bold;'>{$matchedStudent?->name} ({$matchedStudent?->code})</td></tr>"
                        ."<tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b;'>Số tiền khớp lệnh:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; font-weight: bold; color: #ea580c; font-family: monospace; font-size: 16px;'>".number_format($appliedAmount, 0, ',', '.').' VNĐ</td></tr>'
                        ."<tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b;'>Mã phiếu thu:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; font-mono font-bold;'>{$receipt->receipt_number}</td></tr>"
                        ."<tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b;'>Mã hóa đơn điện tử:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; font-mono font-bold; color: #0284c7;'>{$invoiceNumber}</td></tr>"
                        ."<tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b;'>Nội dung chuyển khoản:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9;'>{$content}</td></tr>"
                        .'</table>'
                        ."<p style='margin-top: 15px;'>Đề nghị Học vụ phụ trách và Kế toán tiến hành kiểm tra xuất hóa đơn và bàn giao học liệu cho học viên.</p>"
                        ."<div style='margin-top: 25px; text-align: center;'><a href='".route('tuition.history')."' style='background: #ea580c; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold;'>Xem Phiếu Thu & Hóa Đơn</a></div>"
                        .'</div>'
                        .'</div>';

                    foreach ($recipients as $email) {
                        try {
                            Mail::html($htmlContent, function ($msg) use ($email, $subject) {
                                $msg->to($email)->subject($subject);
                            });
                        } catch (\Throwable $me) {
                            Log::warning("Lỗi gửi email SePay cho {$email}: ".$me->getMessage());
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Lỗi xử lý gửi email SePay xác thực: '.$e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => "Khớp thanh toán thành công cho học viên {$matchedStudent?->name}!",
                'receipt_number' => $receipt->receipt_number,
                'invoice_number' => $invoiceNumber,
                'remaining_debt' => $outcome['tuition']->fresh()->debt_amount,
            ]);
        }

        // 7. Unmatched scenario: Log and notify staff for manual reconciliation
        $tx->update([
            'status' => 'unmatched',
            'response_message' => "Không tìm thấy học viên hoặc hợp đồng công nợ tương ứng với nội dung: '{$content}'",
        ]);

        AdminNotification::create([
            'title' => 'SePay: Giao dịch cần đối soát ('.number_format((float) $transferAmount, 0, ',', '.').' VNĐ)',
            'message' => 'Nhận '.number_format((float) $transferAmount, 0, ',', '.')." VNĐ vào tài khoản {$accountNumber} nhưng chưa tự động khớp học viên. ND: '{$content}'. Vui lòng kiểm tra đối soát thủ công.",
            'type' => 'warning',
            'is_read' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Giao dịch đã được lưu trữ an toàn nhưng chưa tìm thấy mã học viên để gạch nợ tự động.',
            'transaction_id' => $tx->id,
        ]);
    }

    /**
     * API: Lấy danh sách giao dịch SePay gần đây nhất (hỗ trợ màn hình cài đặt)
     */
    public function getRecentTransactions(): JsonResponse
    {
        if (! config('services.sepay.webhook_enabled', false)) {
            return response()->json([
                'success' => false,
                'message' => 'SePay webhook hiện đang tắt trên hệ thống.',
                'data' => [],
            ], 503);
        }

        $transactions = SepayTransaction::latest()->take(20)->get();

        return response()->json([
            'success' => true,
            'data' => $transactions,
        ]);
    }

    /**
     * Số điện thoại chuẩn hoá để so khớp bất kể định dạng (+84, khoảng trắng, gạch nối).
     */
    protected function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        // 84 + 9-10 số -> 0 + 9 số (hàng trăm triệu số VN đều có dạng 0-prefixed)
        if (str_starts_with($digits, '84') && strlen($digits) >= 10) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }

    /**
     * Tìm học viên theo SĐT: thử khớp nguyên văn trước, sau đó so khớp ở dạng đã chuẩn hoá.
     */
    protected function findStudentByPhone(?string $phone): ?Student
    {
        $normalized = $this->normalizePhone($phone);
        if ($normalized === '') {
            return null;
        }

        $exact = Student::where('phone', $phone)->first();
        if ($exact && $this->normalizePhone($exact->phone) === $normalized) {
            return $exact;
        }

        $candidates = Student::get(['id', 'phone']);
        $match = $candidates->first(fn (Student $candidate) => $this->normalizePhone($candidate->phone) === $normalized);

        // Trả về model đầy đủ — caller cần cả các trường khác (name, code...) chứ không chỉ phone
        return $match ? Student::find($match->id) : null;
    }
}
