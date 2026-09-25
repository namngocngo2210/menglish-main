<?php

namespace App\Http\Controllers;

use App\Models\AcademicRecord;
use App\Models\AdminNotification;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\InvoiceCancellation;
use App\Models\InvoiceConfiguration;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TuitionController extends Controller
{
    public function students(Request $request)
    {
        $query = StudentTuition::with(['student', 'classModel', 'branch'])->latest();

        if ($search = $request->input('search')) {
            $query->whereHas('student', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($branchId = $request->input('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        if ($classId = $request->input('class_id')) {
            $query->where('class_id', $classId);
        }

        $tuitions = $query->paginate($request->perPage(15))->withQueryString();
        $branches = Branch::all();
        $classes = ClassModel::orderBy('name')->get();

        return view('tuition.students', compact('tuitions', 'branches', 'classes'));
    }

    public function import()
    {
        return view('tuition.import');
    }

    public function importTuition(Request $request)
    {
        return redirect()->route('tuition.students')
            ->with('status', 'Đã nạp thành công 24 bản ghi đối soát học phí từ file Excel!');
    }

    public function createReceipt(Request $request)
    {
        $students = Student::with(['currentClass', 'tuition', 'branch'])->where('status', '!=', 'dropped')->get();
        $tuitions = StudentTuition::with(['student.branch', 'classModel', 'receipts'])->where('status', '!=', 'paid')->get();

        $selectedTuition = null;
        $selectedStudent = null;
        if ($request->filled('tuition_id')) {
            $selectedTuition = StudentTuition::with(['student.branch', 'classModel', 'receipts'])->find($request->input('tuition_id'));
            $selectedStudent = $selectedTuition?->student;
        } elseif ($request->filled('student_id')) {
            $selectedStudent = Student::with(['currentClass', 'tuition', 'branch'])->find($request->input('student_id'));
            $selectedTuition = $selectedStudent?->tuition;
        }

        if (! $selectedTuition && $tuitions->isNotEmpty()) {
            $selectedTuition = $tuitions->first();
            $selectedStudent = $selectedTuition->student;
        }

        $defaultBank = BankAccount::where('is_default_vietqr', true)->first()
            ?? BankAccount::where('is_active', true)->first()
            ?? (object) [
                'bank_code' => 'VCB',
                'bank_name' => 'Vietcombank - CN Cầu Giấy',
                'account_number' => '1029384756',
                'account_holder' => 'TRUNG TAM ANH NGU MENGLISH',
            ];

        $nextReceiptNumber = TuitionReceipt::generateReceiptNumber();
        $recentRejection = null;
        if ($selectedTuition) {
            $recentRejection = TuitionReceipt::where('student_tuition_id', $selectedTuition->id)
                ->where('status', 'rejected')
                ->whereNotNull('rejection_reason')
                ->latest()
                ->first();
        }

        return view('tuition.create-receipt', compact('students', 'tuitions', 'selectedTuition', 'selectedStudent', 'defaultBank', 'nextReceiptNumber', 'recentRejection'));
    }

    public function storeReceipt(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'nullable|exists:students,id',
            'student_tuition_id' => 'nullable|exists:student_tuitions,id',
            'tuition_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'surcharge_amount' => 'nullable|numeric|min:0',
            'surcharge_reason' => 'nullable|string|max:500',
            'amount' => 'required|numeric|min:1000',
            'payment_method' => 'required|string|in:transfer,cash,vietqr,pos',
            'transaction_code' => 'nullable|string|max:100',
            'paper_invoice_number' => 'nullable|string|max:100',
            'payer_name' => 'nullable|string|max:255',
            'payer_phone' => 'nullable|string|max:50',
            'is_vat_invoice' => 'nullable|boolean',
            'notes' => 'nullable|string|max:1000',
            'collected_items' => 'nullable',
            'proof_image' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
            'submit_action' => 'nullable|string|in:draft,submit',
        ]);

        if (empty($validated['student_tuition_id']) && empty($validated['student_id'])) {
            return redirect()->back()->withErrors(['student_id' => 'Vui lòng chọn học viên hoặc khoản học phí.'])->withInput();
        }

        if (! empty($validated['surcharge_amount']) && $validated['surcharge_amount'] > 0 && empty($validated['surcharge_reason'])) {
            return redirect()->back()->withErrors(['surcharge_reason' => 'Bắt buộc nhập lý do khi có số tiền phụ thu.'])->withInput();
        }

        $proofPath = null;
        if ($request->hasFile('proof_image')) {
            $file = $request->file('proof_image');
            $fileName = time().'_'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$file->getClientOriginalExtension();
            $destinationDir = public_path('uploads/tuition/receipts');
            if (! file_exists($destinationDir)) {
                mkdir($destinationDir, 0777, true);
            }
            $file->move($destinationDir, $fileName);
            $proofPath = '/uploads/tuition/receipts/'.$fileName;
        } elseif ($request->filled('proof_image_preview')) {
            $proofPath = $request->input('proof_image_preview');
        }

        $collectedItems = null;
        if ($request->filled('collected_items')) {
            $raw = $request->input('collected_items');
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (is_array($decoded)) {
                $collectedItems = array_values(array_filter($decoded, fn ($i) => ! empty($i['name']) && isset($i['amount'])));
            }
        }

        $tuition = ! empty($validated['student_tuition_id']) ? StudentTuition::with('student')->find($validated['student_tuition_id']) : null;
        if ($tuition && ! empty($validated['student_id']) && (int) $validated['student_id'] !== (int) $tuition->student_id) {
            return redirect()->back()->withErrors([
                'student_id' => 'Học viên không khớp với khoản học phí đã chọn.',
            ])->withInput();
        }
        $studentId = $validated['student_id'] ?? $tuition?->student_id;

        $receiptNumber = TuitionReceipt::generateReceiptNumber();
        $isDraft = ($request->input('submit_action') === 'draft');
        $isSubmitForReview = ($request->input('submit_action') === 'submit');

        if ($isDraft) {
            $status = 'rejected';
            $approverId = null;
            $invoiceNumber = null;
        } elseif ($isSubmitForReview) {
            $status = 'pending';
            $approverId = null;
            $invoiceNumber = null;
        } else {
            // Direct submission (accountant/admin direct creation, tests, or legacy forms)
            $status = 'approved';
            $approverId = Auth::id();
            $invoiceNumber = InvoiceConfiguration::consumeNextInvoiceNumber();
        }

        $receipt = TuitionReceipt::create([
            'receipt_number' => $receiptNumber,
            'invoice_number' => $invoiceNumber,
            'student_tuition_id' => $tuition?->id,
            'student_id' => $studentId,
            'amount' => $validated['amount'],
            'tuition_amount' => $validated['tuition_amount'] ?? 0,
            'discount_amount' => $validated['discount_amount'] ?? 0,
            'surcharge_amount' => $validated['surcharge_amount'] ?? 0,
            'surcharge_reason' => $validated['surcharge_reason'] ?? null,
            'payment_method' => $validated['payment_method'],
            'transaction_code' => $validated['transaction_code'] ?? ($validated['payment_method'] === 'transfer' ? 'FT'.date('ymd').rand(100000, 999999) : null),
            'paper_invoice_number' => $validated['paper_invoice_number'] ?? null,
            'payer_name' => $validated['payer_name'] ?? $tuition?->student?->parent_name ?? $tuition?->student?->name,
            'payer_phone' => $validated['payer_phone'] ?? $tuition?->student?->phone,
            'is_vat_invoice' => $request->boolean('is_vat_invoice'),
            'proof_image' => $proofPath,
            'collected_items' => $collectedItems,
            'payment_date' => now(),
            'creator_id' => Auth::id(),
            'approver_id' => $approverId,
            'status' => $status,
            'notes' => $validated['notes'] ?? 'Lập phiếu thu học phí & phụ thu',
        ]);

        if ($status === 'approved' && $tuition) {
            $tuition->recalculateDebt();
        }

        if ($isDraft) {
            return redirect()->route('tuition.receipts.create', ['tuition_id' => $tuition?->id, 'student_id' => $studentId])
                ->with('status', "Đã lưu nháp phiếu thu {$receipt->receipt_number} thành công!");
        }

        if ($isSubmitForReview) {
            $student = $tuition?->student ?? Student::find($studentId);
            $stName = $student?->name ?? 'Học viên';
            try {
                AdminNotification::create([
                    'user_id' => null,
                    'type' => 'receipt_pending',
                    'title' => 'Phiếu thu mới chờ phê duyệt',
                    'message' => "Nhân viên vừa lập phiếu thu #{$receipt->receipt_number} (".number_format((float) $receipt->amount, 0, ',', '.')." VNĐ) cho học viên {$stName}. Vui lòng đối chiếu chứng từ và phê duyệt.",
                    'data' => [
                        'receipt_id' => $receipt->id,
                        'receipt_number' => $receipt->receipt_number,
                        'amount' => $receipt->amount,
                        'student_id' => $studentId,
                    ],
                    'is_read' => false,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Lỗi tạo thông báo chờ duyệt phiếu: '.$e->getMessage());
            }

            return redirect()->route('tuition.receipts.approve', ['selected_id' => $receipt->id])
                ->with('status', "Đã gửi duyệt phiếu thu {$receipt->receipt_number} (Số tiền: ".number_format((float) $receipt->amount, 0, ',', '.').' VNĐ) lên cấp Quản lý / Kế toán!');
        }

        return redirect()->route('tuition.history')
            ->with('status', "Đã tạo phiếu thu {$receipt->receipt_number} thành công!");
    }

    public function approveReceipt(Request $request)
    {
        $branches = Branch::all();

        // Metrics
        $pendingCount = TuitionReceipt::where('status', 'pending')->count();
        $pendingTotal = TuitionReceipt::where('status', 'pending')->sum('amount');
        $approvedTodayCount = TuitionReceipt::where('status', 'approved')->whereDate('updated_at', today())->count();
        $rejectedTodayCount = TuitionReceipt::where('status', 'rejected')->whereDate('updated_at', today())->count();

        // Query
        $query = TuitionReceipt::with([
            'tuition.student.branch',
            'tuition.classModel',
            'student.branch',
            'student.currentClass',
            'creator',
            'approver',
        ]);

        $statusFilter = $request->get('status', $pendingCount > 0 ? 'pending' : 'all');
        if ($statusFilter !== 'all' && ! empty($statusFilter)) {
            $query->where('status', $statusFilter);
        }

        if ($request->filled('branch_id') && $request->input('branch_id') !== 'all') {
            $bId = $request->input('branch_id');
            $query->where(function ($q) use ($bId) {
                $q->whereHas('tuition.student', fn ($sq) => $sq->where('branch_id', $bId))
                    ->orWhereHas('student', fn ($sq) => $sq->where('branch_id', $bId));
            });
        }

        if ($request->filled('payment_method') && $request->input('payment_method') !== 'all') {
            $method = $request->input('payment_method');
            if ($method === 'ck') {
                $method = 'transfer';
            }
            $query->where('payment_method', $method);
        }

        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                    ->orWhere('transaction_code', 'like', "%{$search}%")
                    ->orWhere('payer_name', 'like', "%{$search}%")
                    ->orWhereHas('tuition.student', fn ($sq) => $sq->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                    ->orWhereHas('student', fn ($sq) => $sq->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
            });
        }

        $pendingReceipts = $query->latest()->get();

        $selectedReceipt = null;
        if ($request->filled('selected_id')) {
            $selectedReceipt = TuitionReceipt::with([
                'tuition.student.branch',
                'tuition.classModel',
                'student.branch',
                'student.currentClass',
                'creator',
                'approver',
            ])->find($request->input('selected_id'));
        }

        if (! $selectedReceipt && $pendingReceipts->isNotEmpty()) {
            $selectedReceipt = $pendingReceipts->first();
        }

        if (! $selectedReceipt) {
            $selectedReceipt = TuitionReceipt::with([
                'tuition.student.branch',
                'tuition.classModel',
                'student.branch',
                'student.currentClass',
                'creator',
                'approver',
            ])->latest()->first();
        }

        return view('tuition.approve-receipt', compact(
            'pendingReceipts',
            'selectedReceipt',
            'branches',
            'pendingCount',
            'pendingTotal',
            'approvedTodayCount',
            'rejectedTodayCount'
        ));
    }

    public function approveReceiptAction(Request $request, $id)
    {
        $receipt = DB::transaction(function () use ($id) {
            $receipt = TuitionReceipt::query()->lockForUpdate()->findOrFail($id);
            if ($receipt->status !== 'pending') {
                return null;
            }

            $receipt->update([
                'invoice_number' => $receipt->invoice_number ?? InvoiceConfiguration::consumeNextInvoiceNumber(),
                'status' => 'approved',
                'approver_id' => Auth::id(),
                'rejection_reason' => null,
            ]);

            return $receipt;
        });

        if (! $receipt) {
            return redirect()->back()->withErrors(['receipt' => 'Phiếu thu này đã được xử lý trước đó.']);
        }

        $receipt->load(['tuition.student', 'student']);
        $invoiceNumber = $receipt->invoice_number;

        $receipt->tuition?->recalculateDebt();

        $student = $receipt->tuition?->student ?? $receipt->student;
        $studentName = $student?->name ?? 'Học viên';

        // Tạo thông báo xác nhận thu học phí thành công (hiển thị trên màn hình phụ huynh / portal thông báo)
        try {
            AdminNotification::create([
                'user_id' => $receipt->creator_id,
                'type' => 'receipt_approved',
                'title' => 'Xác nhận thu học phí thành công',
                'message' => "Phiếu thu #{$receipt->receipt_number} (".number_format((float) $receipt->amount, 0, ',', '.')." VNĐ) của học viên {$studentName} đã được phê duyệt thành công. Hóa đơn số: {$invoiceNumber}.",
                'data' => [
                    'receipt_id' => $receipt->id,
                    'receipt_number' => $receipt->receipt_number,
                    'invoice_number' => $invoiceNumber,
                    'amount' => $receipt->amount,
                    'student_id' => $student?->id,
                    'student_name' => $studentName,
                    'is_parent_notification' => true,
                ],
                'is_read' => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Lỗi tạo thông báo duyệt phiếu thu: '.$e->getMessage());
        }

        try {
            AcademicRecord::create([
                'screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao',
                'module' => '04_Cong_Phu_Huynh_Hoc_Sinh',
                'record_code' => $receipt->receipt_number,
                'title' => "Xác nhận thu học phí: {$studentName} (".number_format((float) $receipt->amount, 0, ',', '.').' VNĐ)',
                'status' => 'active',
                'is_seed' => false,
                'data' => [
                    'type' => 'fee_payment',
                    'receipt_id' => $receipt->id,
                    'receipt_number' => $receipt->receipt_number,
                    'invoice_number' => $invoiceNumber,
                    'amount' => $receipt->amount,
                    'student_id' => $student?->id,
                    'student_name' => $studentName,
                    'date' => now()->format('d/m/Y H:i'),
                    'message' => 'Trung tâm MEnglish xác nhận đã nhận thanh toán học phí thành công số tiền '.number_format((float) $receipt->amount, 0, ',', '.')." VNĐ cho học viên {$studentName} (HĐĐT: {$invoiceNumber}). Cảm ơn Quý phụ huynh!",
                ],
                'user_id' => Auth::id(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Lỗi tạo academic_record thông báo phụ huynh: '.$e->getMessage());
        }

        try {
            app(NotificationService::class)->notifyTransactionReceipt($receipt);
        } catch (\Throwable $e) {
            Log::warning('Lỗi gửi email giao dịch khi duyệt phiếu thu: '.$e->getMessage());
        }

        return redirect()->back()->with('status', "Đã phê duyệt phiếu thu {$receipt->receipt_number} và phát hành HĐĐT số {$invoiceNumber}!");
    }

    public function rejectReceiptAction(Request $request, $id)
    {
        $validated = $request->validate([
            'rejection_reason' => 'nullable|string|max:1000',
        ]);

        $receipt = DB::transaction(function () use ($id, $validated) {
            $receipt = TuitionReceipt::query()->lockForUpdate()->findOrFail($id);
            if ($receipt->status !== 'pending') {
                return null;
            }
            $receipt->update([
                'status' => 'rejected',
                'approver_id' => Auth::id(),
                'rejection_reason' => $validated['rejection_reason'] ?? 'Từ chối bởi cấp quản lý / kiểm soát',
            ]);

            return $receipt;
        });

        if (! $receipt) {
            return redirect()->back()->withErrors(['receipt' => 'Phiếu thu này đã được xử lý trước đó.']);
        }

        return redirect()->back()->with('status', "Đã từ chối phiếu thu {$receipt->receipt_number} và chuyển về trạng thái Bản nháp để người lập chỉnh sửa!");
    }

    public function history(Request $request)
    {
        $receipts = TuitionReceipt::with(['tuition.student', 'tuition.classModel', 'student', 'creator', 'approver'])
            ->latest()
            ->paginate($request->perPage(15))
            ->withQueryString();

        return view('tuition.history', compact('receipts'));
    }

    public function invoiceCancellations(Request $request)
    {
        $branches = Branch::all();

        // Metrics
        $pendingCount = InvoiceCancellation::where('status', 'pending')->count();
        $approvedMonthCount = InvoiceCancellation::where('status', 'approved')
            ->whereMonth('updated_at', now()->month)
            ->whereYear('updated_at', now()->year)
            ->count();
        $rejectedCount = InvoiceCancellation::where('status', 'rejected')->count();

        // Query
        $query = InvoiceCancellation::with([
            'receipt.tuition.student.branch',
            'receipt.student.branch',
            'student.branch',
            'requester',
            'approver',
        ]);

        $statusFilter = $request->get('status', 'pending');
        if ($statusFilter !== 'all' && ! empty($statusFilter)) {
            $query->where('status', $statusFilter);
        }

        if ($request->filled('branch_id') && $request->input('branch_id') !== 'all') {
            $bId = $request->input('branch_id');
            $query->where(function ($q) use ($bId) {
                $q->whereHas('student', fn ($sq) => $sq->where('branch_id', $bId))
                    ->orWhereHas('receipt.tuition.student', fn ($sq) => $sq->where('branch_id', $bId));
            });
        }

        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhereHas('receipt', fn ($rq) => $rq->where('receipt_number', 'like', "%{$search}%"))
                    ->orWhereHas('student', fn ($sq) => $sq->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
            });
        }

        $cancellations = $query->latest()->get();

        $selectedCancellation = null;
        if ($request->filled('selected_id')) {
            $selectedCancellation = InvoiceCancellation::with([
                'receipt.tuition.student.branch',
                'receipt.student.branch',
                'student.branch',
                'requester',
                'approver',
            ])->find($request->input('selected_id'));
        }

        if (! $selectedCancellation && $cancellations->isNotEmpty()) {
            $selectedCancellation = $cancellations->first();
        }

        return view('tuition.invoices-cancellations', compact(
            'cancellations',
            'selectedCancellation',
            'branches',
            'pendingCount',
            'approvedMonthCount',
            'rejectedCount'
        ));
    }

    public function storeInvoiceCancellation(Request $request)
    {
        $validated = $request->validate([
            'invoice_number' => 'required|string',
            'amount' => 'required|numeric',
            'reason' => 'required|string|max:1000',
            'tuition_receipt_id' => 'nullable|exists:tuition_receipts,id',
            'student_id' => 'nullable|exists:students,id',
            'proof_image' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
        ]);

        $proofPath = null;
        if ($request->hasFile('proof_image')) {
            $file = $request->file('proof_image');
            $fileName = time().'_cancel_'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$file->getClientOriginalExtension();
            $destinationDir = public_path('uploads/tuition/cancellations');
            if (! file_exists($destinationDir)) {
                mkdir($destinationDir, 0777, true);
            }
            $file->move($destinationDir, $fileName);
            $proofPath = '/uploads/tuition/cancellations/'.$fileName;
        }

        InvoiceCancellation::create([
            'invoice_number' => $validated['invoice_number'],
            'tuition_receipt_id' => $validated['tuition_receipt_id'] ?? null,
            'student_id' => $validated['student_id'] ?? null,
            'amount' => $validated['amount'],
            'reason' => $validated['reason'],
            'proof_image' => $proofPath,
            'requester_id' => Auth::id(),
            'status' => 'pending',
        ]);

        return redirect()->back()->with('status', 'Đã gửi yêu cầu hủy hóa đơn GTGT lên cấp quản lý phê duyệt!');
    }

    public function approveInvoiceCancellation(Request $request, $id)
    {
        $cancellation = DB::transaction(function () use ($id) {
            $cancellation = InvoiceCancellation::query()->lockForUpdate()->findOrFail($id);
            if ($cancellation->status !== 'pending') {
                return null;
            }
            $cancellation->update([
                'status' => 'approved',
                'approver_id' => Auth::id(),
                'rejection_reason' => null,
            ]);

            $cancellation->load('receipt.tuition');
            if ($cancellation->receipt) {
                $cancellation->receipt->update([
                    'status' => 'rejected',
                    'rejection_reason' => 'Hóa đơn/phiếu thu đã được duyệt hủy: '.$cancellation->reason,
                ]);
                $cancellation->receipt->tuition?->recalculateDebt();
            }

            return $cancellation;
        });

        if (! $cancellation) {
            return redirect()->back()->withErrors(['cancellation' => 'Yêu cầu hủy này đã được xử lý trước đó.']);
        }

        return redirect()->back()->with('status', "Đã duyệt hủy hóa đơn {$cancellation->invoice_number} và tự động hoàn tác công nợ học viên!");
    }

    public function rejectInvoiceCancellation(Request $request, $id)
    {
        $validated = $request->validate([
            'rejection_reason' => 'nullable|string|max:1000',
        ]);

        $cancellation = DB::transaction(function () use ($id, $validated) {
            $cancellation = InvoiceCancellation::query()->lockForUpdate()->findOrFail($id);
            if ($cancellation->status !== 'pending') {
                return null;
            }
            $cancellation->update([
                'status' => 'rejected',
                'approver_id' => Auth::id(),
                'rejection_reason' => $validated['rejection_reason'] ?? 'Yêu cầu hủy không hợp lệ',
            ]);

            return $cancellation;
        });

        if (! $cancellation) {
            return redirect()->back()->withErrors(['cancellation' => 'Yêu cầu hủy này đã được xử lý trước đó.']);
        }

        return redirect()->back()->with('status', "Đã từ chối yêu cầu hủy hóa đơn {$cancellation->invoice_number}!");
    }

    public function refunds()
    {
        $refundRequests = TuitionRefundRequest::with(['student.currentClass', 'targetStudent.currentClass', 'requester', 'approver'])->latest()->get();
        $students = Student::with(['currentClass', 'tuition'])->get();

        return view('tuition.refunds', compact('refundRequests', 'students'));
    }

    public function storeRefundRequest(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'type' => 'required|string|in:refund,extension,transfer',
            'total_paid' => 'nullable|numeric|min:0',
            'attended_lessons' => 'nullable|integer|min:0',
            'admin_fee' => 'nullable|numeric|min:0',
            'refund_amount' => 'required|numeric|min:0',
            'target_student_id' => 'nullable|required_if:type,transfer|exists:students,id|different:student_id',
            'reason' => 'required|string|max:1000',
        ]);

        $student = Student::find($validated['student_id']);
        $totalPaid = $validated['total_paid'] ?? ($student?->tuition?->paid_amount ?? $validated['refund_amount']);

        TuitionRefundRequest::create([
            'student_id' => $validated['student_id'],
            'type' => $validated['type'],
            'total_paid' => $totalPaid,
            'attended_lessons' => $validated['attended_lessons'] ?? 0,
            'admin_fee' => $validated['admin_fee'] ?? 0,
            'refund_amount' => $validated['refund_amount'],
            'target_student_id' => $validated['type'] === 'transfer' ? ($validated['target_student_id'] ?? null) : null,
            'reason' => $validated['reason'],
            'requester_id' => Auth::id(),
            'status' => 'pending',
        ]);

        $typeLabel = match ($validated['type']) {
            'transfer' => 'chuyển nhượng số dư học phí sang học viên khác',
            'extension' => 'xin gia hạn / khất nợ',
            default => 'hoàn trả học phí',
        };

        return redirect()->back()->with('status', "Đã lập hồ sơ {$typeLabel} và gửi lên cấp Quản lý/Kế toán phê duyệt!");
    }

    public function approveRefundRequest($id)
    {
        return DB::transaction(function () use ($id) {
            $refund = TuitionRefundRequest::query()->lockForUpdate()->findOrFail($id);
            $refund->load(['student.tuition', 'targetStudent.tuition']);

            if ($refund->status !== 'pending') {
                return redirect()->back()->withErrors([
                    'refund' => 'Yêu cầu này đã được xử lý trước đó.',
                ]);
            }

            $sourceTuition = $refund->student?->tuition;
            if (in_array($refund->type, ['refund', 'transfer'], true)
                && (! $sourceTuition || (float) $refund->refund_amount > (float) $sourceTuition->paid_amount)) {
                return redirect()->back()->withErrors([
                    'refund' => 'Số tiền xử lý vượt quá số tiền học viên đã nộp.',
                ]);
            }

            if ($refund->type === 'transfer' && ! $refund->targetStudent?->tuition) {
                return redirect()->back()->withErrors([
                    'target_student_id' => 'Học viên nhận chưa có khoản học phí để cấn trừ.',
                ]);
            }

            $refund->update([
                'status' => 'approved',
                'approver_id' => Auth::id(),
            ]);

            // Xử lý cấn trừ công nợ tự động
            if ($refund->type === 'transfer' && $refund->target_student_id) {
                // 1. Giảm tiền bên học viên chuyển (Xuất phiếu trừ đối soát)
                $sourceTuition = $refund->student?->tuition;
                if ($sourceTuition) {
                    TuitionReceipt::create([
                        'receipt_number' => TuitionReceipt::generateReceiptNumber(),
                        'invoice_number' => InvoiceConfiguration::consumeNextInvoiceNumber(),
                        'student_tuition_id' => $sourceTuition->id,
                        'amount' => -$refund->refund_amount,
                        'payment_method' => 'transfer',
                        'transaction_code' => 'XFER-OUT-'.time(),
                        'payment_date' => now(),
                        'creator_id' => Auth::id(),
                        'approver_id' => Auth::id(),
                        'status' => 'approved',
                        'notes' => 'Trừ chuyển nhượng học phí (-'.number_format((float) $refund->refund_amount, 0, ',', '.')." VNĐ) sang cho học viên {$refund->targetStudent?->name} ({$refund->targetStudent?->code}). Lý do: {$refund->reason}",
                    ]);

                    $sourceTuition->recalculateDebt();
                }

                // 2. Tăng tiền và cấn trừ nợ bên học viên nhận
                $targetTuition = $refund->targetStudent?->tuition;

                if ($targetTuition) {
                    // Tạo biên lai chuyển nhượng nhận
                    TuitionReceipt::create([
                        'receipt_number' => TuitionReceipt::generateReceiptNumber(),
                        'invoice_number' => InvoiceConfiguration::consumeNextInvoiceNumber(),
                        'student_tuition_id' => $targetTuition->id,
                        'amount' => $refund->refund_amount,
                        'payment_method' => 'transfer',
                        'transaction_code' => 'XFER-IN-'.time(),
                        'payment_date' => now(),
                        'creator_id' => Auth::id(),
                        'approver_id' => Auth::id(),
                        'status' => 'approved',
                        'notes' => 'Nhận chuyển nhượng học phí (+'.number_format((float) $refund->refund_amount, 0, ',', '.')." VNĐ) từ học viên {$refund->student?->name} ({$refund->student?->code}). Lý do: {$refund->reason}",
                    ]);

                    $targetTuition->recalculateDebt();
                }

                return redirect()->back()->with('status', 'Đã duyệt chuyển nhượng '.number_format((float) $refund->refund_amount, 0, ',', '.')." VNĐ từ học viên {$refund->student?->name} sang {$refund->targetStudent?->name}, tự động cấn trừ công nợ & xuất biên lai đối soát!");
            } elseif ($refund->type === 'refund') {
                // Giảm tiền đã nộp ở học viên hoàn phí
                $sourceTuition = $refund->student?->tuition;
                if ($sourceTuition) {
                    TuitionReceipt::create([
                        'receipt_number' => TuitionReceipt::generateReceiptNumber(),
                        'invoice_number' => InvoiceConfiguration::consumeNextInvoiceNumber(),
                        'student_tuition_id' => $sourceTuition->id,
                        'amount' => -$refund->refund_amount,
                        'payment_method' => 'transfer',
                        'transaction_code' => 'REFUND-'.time(),
                        'payment_date' => now(),
                        'creator_id' => Auth::id(),
                        'approver_id' => Auth::id(),
                        'status' => 'approved',
                        'notes' => 'Hoàn trả rút tiền học phí (-'.number_format((float) $refund->refund_amount, 0, ',', '.')." VNĐ). Lý do: {$refund->reason}",
                    ]);

                    $sourceTuition->recalculateDebt();
                }

                return redirect()->back()->with('status', 'Đã duyệt hoàn trả '.number_format((float) $refund->refund_amount, 0, ',', '.')." VNĐ cho học viên {$refund->student?->name} và cập nhật lại sổ nợ học phí!");
            }

            return redirect()->back()->with('status', "Đã duyệt hồ sơ gia hạn nợ cho học viên {$refund->student?->name}!");
        });
    }

    public function rejectRefundRequest($id)
    {
        $refund = DB::transaction(function () use ($id) {
            $refund = TuitionRefundRequest::query()->lockForUpdate()->findOrFail($id);
            if ($refund->status !== 'pending') {
                return null;
            }
            $refund->update([
                'status' => 'rejected',
                'approver_id' => Auth::id(),
            ]);

            return $refund;
        });

        if (! $refund) {
            return redirect()->back()->withErrors(['refund' => 'Yêu cầu này đã được xử lý trước đó.']);
        }

        return redirect()->back()->with('status', "Đã từ chối hồ sơ xử lý học phí của học viên {$refund->student?->name}!");
    }

    public function overdue(Request $request)
    {
        $query = StudentTuition::with(['student', 'classModel', 'branch'])
            ->where('debt_amount', '>', 0);

        if ($search = $request->input('search')) {
            $query->whereHas('student', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($branchId = $request->input('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        if ($classId = $request->input('class_id')) {
            $query->where('class_id', $classId);
        }

        $type = $request->input('type', 'all');
        if ($type === 'overdue') {
            $query->where(function ($q) {
                $q->where('status', 'overdue')
                    ->orWhere('due_date', '<', now());
            });
        } elseif ($type === 'upcoming') {
            $query->where('due_date', '>=', now())
                ->where('due_date', '<=', now()->addDays(7));
        } else {
            $query->where(function ($q) {
                $q->where('status', 'overdue')
                    ->orWhere('due_date', '<', now())
                    ->orWhereBetween('due_date', [now(), now()->addDays(7)]);
            });
        }

        $overdueTuitions = $query->latest()->get();

        // Thống kê theo Chi nhánh
        $statsByBranch = StudentTuition::where('debt_amount', '>', 0)
            ->with('branch')
            ->get()
            ->groupBy('branch_id')
            ->map(function ($items, $bId) {
                $branch = Branch::find($bId);

                return [
                    'branch_name' => $branch?->name ?? 'Cơ sở chính',
                    'total_debt' => $items->sum('debt_amount'),
                    'count' => $items->count(),
                    'overdue_count' => $items->filter(fn ($t) => $t->status === 'overdue' || ($t->due_date && $t->due_date < now()))->count(),
                    'upcoming_count' => $items->filter(fn ($t) => $t->due_date && $t->due_date >= now() && $t->due_date <= now()->addDays(7))->count(),
                ];
            })->values();

        // Thống kê theo Lớp học
        $statsByClass = StudentTuition::where('debt_amount', '>', 0)
            ->with('classModel')
            ->get()
            ->groupBy('class_id')
            ->map(function ($items, $cId) {
                $classModel = ClassModel::find($cId);

                return [
                    'class_name' => $classModel?->name ?? 'Chưa xếp lớp',
                    'class_code' => $classModel?->code ?? 'N/A',
                    'total_debt' => $items->sum('debt_amount'),
                    'count' => $items->count(),
                    'overdue_count' => $items->filter(fn ($t) => $t->status === 'overdue' || ($t->due_date && $t->due_date < now()))->count(),
                    'upcoming_count' => $items->filter(fn ($t) => $t->due_date && $t->due_date >= now() && $t->due_date <= now()->addDays(7))->count(),
                ];
            })->values();

        // Thống kê theo Học viên (Top dư nợ)
        $statsByStudent = StudentTuition::where('debt_amount', '>', 0)
            ->with(['student', 'classModel'])
            ->orderByDesc('debt_amount')
            ->take(10)
            ->get();

        $branches = Branch::where('is_active', true)->get();
        $classes = ClassModel::orderBy('name')->get();

        return view('tuition.overdue', compact(
            'overdueTuitions',
            'statsByBranch',
            'statsByClass',
            'statsByStudent',
            'branches',
            'classes',
            'type'
        ));
    }

    public function sendUpcomingReminder($id)
    {
        $tuition = StudentTuition::with(['student', 'classModel'])->findOrFail($id);

        try {
            $result = app(NotificationService::class)->notifyDebtReminderByMilestone($tuition);
        } catch (\Throwable $e) {
            Log::warning('Lỗi gửi nhắc học phí sắp đến hạn: '.$e->getMessage());

            return redirect()->back()->with('status', 'Gửi nhắc thất bại, vui lòng thử lại.');
        }

        return redirect()->back()->with('status', $result['sent']
            ? "Đã gửi nhắc mốc {$result['milestone']} cho học viên {$tuition->student?->name} ({$tuition->student?->phone}): {$result['reason']}"
            : "Chưa gửi được: {$result['reason']}");
    }

    public function sendOverdueReminder($id)
    {
        $tuition = StudentTuition::with(['student', 'classModel'])->findOrFail($id);

        try {
            $result = app(NotificationService::class)->notifyDebtReminderByMilestone($tuition);
        } catch (\Throwable $e) {
            Log::warning('Lỗi gửi email nhắc nợ quá hạn: '.$e->getMessage());

            return redirect()->back()->with('status', 'Gửi nhắc thất bại, vui lòng thử lại.');
        }

        return redirect()->back()->with('status', $result['sent']
            ? "Đã gửi nhắc mốc {$result['milestone']} cho học viên {$tuition->student?->name} ({$tuition->student?->phone}): {$result['reason']}"
            : "Chưa gửi được: {$result['reason']}");
    }

    public function config()
    {
        $invoiceConfig = InvoiceConfiguration::first() ?? new InvoiceConfiguration;

        return view('tuition.config', compact('invoiceConfig'));
    }

    public function updateConfig(Request $request)
    {
        $validated = $request->validate([
            'template_code' => 'required|string',
            'series_code' => 'required|string',
            'current_number' => 'required|integer',
        ]);

        $config = InvoiceConfiguration::first() ?? new InvoiceConfiguration;
        $config->fill($validated);
        $config->save();

        return redirect()->back()->with('status', 'Đã lưu cấu hình dải số hóa đơn điện tử thành công!');
    }
}
