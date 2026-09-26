<?php

namespace App\Http\Controllers;

use App\Exceptions\InvoiceRangeExhaustedException;
use App\Exports\TuitionImportTemplateExport;
use App\Models\AcademicRecord;
use App\Models\AdminNotification;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\InvoiceCancellation;
use App\Models\InvoiceConfiguration;
use App\Models\SepayTransaction;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\SystemSetting;
use App\Models\TuitionContactLog;
use App\Models\TuitionReceipt;
use App\Models\TuitionRefundRequest;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\SafeUploadService;
use App\Services\SalesCommissionService;
use App\Services\TuitionImportService;
use App\Support\TuitionBranchScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class TuitionController extends Controller
{
    /** Minh chứng phiếu thu / hủy hóa đơn: ảnh hoặc PDF (theo nội dung file). */
    private const PROOF_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /** Chi nhánh người xem được thao tác ở các màn Học phí (null = toàn hệ thống). Xem TuitionBranchScope. */
    private function branchScope(): ?array
    {
        return TuitionBranchScope::branchIds(Auth::user());
    }

    private const OUT_OF_SCOPE = 'Khoản học phí này thuộc chi nhánh ngoài phạm vi bạn được quản lý.';

    public function students(Request $request)
    {
        $scope = $this->branchScope();
        $query = TuitionBranchScope::tuitions(StudentTuition::with(['student', 'classModel', 'branch'])->latest(), $scope);

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
        $branches = TuitionBranchScope::branches($scope)->get();
        $classes = ClassModel::when($scope !== null, fn ($q) => $q->whereIn('branch_id', $scope))->orderBy('name')->get();

        return view('tuition.students', compact('tuitions', 'branches', 'classes'));
    }

    /**
     * Nhập học phí từ Excel — 3 bước: Tải file → Xem trước (lỗi từng dòng) → Kết quả.
     */
    public function import(Request $request)
    {
        $branches = Branch::where('is_active', true)->orderBy('name')->get();
        $preview = null;
        if ($request->filled('token')) {
            $preview = $request->session()->get('tuition_import.'.$request->input('token'));
            if ($preview) {
                $preview['token'] = $request->input('token');
                $preview['branch_name'] = Branch::find($preview['branch_id'])?->name;
            }
        }
        $result = $request->session()->get('tuition_import_result');

        return view('tuition.import', compact('branches', 'preview', 'result'));
    }

    public function importTuition(Request $request, TuitionImportService $importer)
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'excel_file' => 'required|file|max:10240|mimes:xlsx,xls,csv,txt',
        ], [
            'branch_id.required' => 'Vui lòng chọn chi nhánh.',
            'excel_file.required' => 'Vui lòng chọn file Excel (.xlsx) hoặc CSV để nhập.',
            'excel_file.mimes' => 'Chỉ hỗ trợ file .xlsx, .xls hoặc .csv.',
            'excel_file.max' => 'File tối đa 10MB.',
        ]);

        try {
            $parsed = $importer->parse($request->file('excel_file'), (int) $validated['branch_id']);
        } catch (\Throwable $e) {
            Log::warning('Không đọc được file nhập học phí: '.$e->getMessage());

            return redirect()->route('tuition.import')->withErrors(['excel_file' => 'Không đọc được file. Vui lòng dùng file mẫu (.xlsx hoặc .csv UTF-8).']);
        }

        if ($parsed['missing_columns'] !== []) {
            return redirect()->route('tuition.import')->withErrors([
                'excel_file' => 'File thiếu cột bắt buộc: '.implode(', ', $parsed['missing_columns']).'. Vui lòng dùng file mẫu.',
            ]);
        }

        if ($parsed['rows'] === []) {
            return redirect()->route('tuition.import')->withErrors(['excel_file' => 'File không có dòng dữ liệu nào.']);
        }

        $token = (string) Str::uuid();
        $request->session()->put('tuition_import.'.$token, [
            'branch_id' => (int) $validated['branch_id'],
            'file_name' => $request->file('excel_file')->getClientOriginalName(),
            'rows' => $parsed['rows'],
        ]);

        return redirect()->route('tuition.import', ['token' => $token]);
    }

    public function confirmImport(Request $request, TuitionImportService $importer)
    {
        $validated = $request->validate(['token' => 'required|string']);
        $preview = $request->session()->pull('tuition_import.'.$validated['token']);
        if (! $preview) {
            return redirect()->route('tuition.import')->withErrors(['excel_file' => 'Phiên xem trước đã hết hạn, vui lòng tải file lên lại.']);
        }

        $valid = array_values(array_filter($preview['rows'], fn ($row) => empty($row['errors'])));
        if ($valid === []) {
            return redirect()->route('tuition.import')->withErrors(['excel_file' => 'Không có dòng hợp lệ nào để nhập.']);
        }

        $result = $importer->import($valid, (int) $preview['branch_id'], $request->user());
        $result['skipped'] = count($preview['rows']) - count($valid);
        $result['file_name'] = $preview['file_name'];

        return redirect()->route('tuition.import')
            ->with('tuition_import_result', $result)
            ->with('status', "Đã nhập {$result['tuitions']} hồ sơ học phí và {$result['receipts']} phiếu thu chờ duyệt.");
    }

    public function downloadImportTemplate()
    {
        return Excel::download(new TuitionImportTemplateExport, 'mau-nhap-hoc-phi.xlsx');
    }

    public function createReceipt(Request $request)
    {
        return $this->receiptForm($request, null);
    }

    /**
     * Màn sửa phiếu thu nháp / bị trả về: dùng lại form lập phiếu, điền sẵn dữ liệu, lưu nháp hoặc gửi duyệt lại.
     */
    public function editReceipt(Request $request, $id)
    {
        $receipt = TuitionReceipt::with(['tuition.student.branch', 'tuition.classModel', 'student'])->findOrFail($id);
        $user = $request->user();
        abort_unless((int) $receipt->creator_id === (int) $user->id || $user->hasRole('admin'), 403, 'Chỉ người lập phiếu mới được sửa phiếu này.');

        if (! in_array($receipt->status, TuitionReceipt::EDITABLE_STATUSES, true)) {
            return redirect()->route('tuition.receipts.approve', ['selected_id' => $receipt->id])
                ->withErrors(['receipt' => 'Chỉ sửa được phiếu ở trạng thái Bản nháp hoặc Bị từ chối.']);
        }

        return $this->receiptForm($request, $receipt);
    }

    private function receiptForm(Request $request, ?TuitionReceipt $editingReceipt)
    {
        $scope = $this->branchScope();
        $students = TuitionBranchScope::students(Student::with(['currentClass', 'tuition', 'branch']), $scope)->where('status', '!=', 'dropped')->get();
        $tuitions = TuitionBranchScope::tuitions(StudentTuition::query(), $scope)
            ->with(['student.branch', 'student.currentClass.course', 'classModel.course', 'receipts', 'bankAccount'])
            ->where(function ($q) use ($editingReceipt) {
                $q->where('status', '!=', 'paid');
                if ($editingReceipt?->student_tuition_id) {
                    $q->orWhere('id', $editingReceipt->student_tuition_id);
                }
            })
            ->get();

        $selectedTuition = null;
        $selectedStudent = null;
        if ($editingReceipt) {
            $selectedTuition = $editingReceipt->tuition;
            $selectedStudent = $editingReceipt->tuition?->student ?? $editingReceipt->student;
        } elseif ($request->filled('tuition_id')) {
            $selectedTuition = $tuitions->firstWhere('id', (int) $request->input('tuition_id'))
                ?? TuitionBranchScope::tuitions(StudentTuition::query(), $scope)->with(['student.branch', 'classModel', 'receipts'])->find($request->input('tuition_id'));
            $selectedStudent = $selectedTuition?->student;
        } elseif ($request->filled('student_id')) {
            $selectedStudent = TuitionBranchScope::students(Student::with(['currentClass', 'tuition', 'branch']), $scope)->find($request->input('student_id'));
            $selectedTuition = $selectedStudent?->tuition;
        }

        if (! $selectedTuition && ! $editingReceipt && $tuitions->isNotEmpty()) {
            $selectedTuition = $tuitions->first();
            $selectedStudent = $selectedTuition->student;
        }

        // Mỗi khoản học phí dùng tài khoản của hợp đồng / chi nhánh (fallback mặc định) để tạo QR; số buổi lấy từ dữ liệu thật.
        $tuitionMeta = $tuitions->mapWithKeys(function (StudentTuition $t) {
            $bank = $t->resolveBankAccount();

            return [$t->id => [
                'sessions' => $t->sessionStats(),
                'bank' => $bank ? [
                    'bank_code' => $bank->bank_code,
                    'bank_name' => $bank->bank_name,
                    'account_number' => $bank->account_number,
                    'account_holder' => $bank->account_holder,
                    'scope' => $t->bank_account_id && (int) $t->bank_account_id === (int) $bank->id
                        ? 'Tài khoản gắn với hợp đồng'
                        : ($bank->branch_id ? 'Tài khoản chi nhánh '.($bank->branch?->name ?? '') : 'Tài khoản mặc định hệ thống'),
                ] : null,
            ]];
        });

        // Chỉ dùng tài khoản đang hoạt động; không có thì view cảnh báo và ẩn QR (không fallback số TK giả).
        $defaultBank = $selectedTuition?->resolveBankAccount() ?? BankAccount::defaultAccount();

        $nextReceiptNumber = $editingReceipt?->receipt_number ?? TuitionReceipt::generateReceiptNumber();
        $recentRejection = null;
        if ($editingReceipt?->status === TuitionReceipt::STATUS_REJECTED) {
            $recentRejection = $editingReceipt;
        } elseif ($selectedTuition && ! $editingReceipt) {
            $recentRejection = TuitionReceipt::where('student_tuition_id', $selectedTuition->id)
                ->where('status', 'rejected')
                ->whereNotNull('rejection_reason')
                ->latest()
                ->first();
        }

        return view('tuition.create-receipt', compact(
            'students', 'tuitions', 'selectedTuition', 'selectedStudent', 'defaultBank', 'nextReceiptNumber',
            'recentRejection', 'editingReceipt', 'tuitionMeta'
        ));
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

        if ($amountError = $this->receiptAmountError($validated)) {
            return redirect()->back()->withErrors($amountError)->withInput();
        }

        if (! empty($validated['surcharge_amount']) && $validated['surcharge_amount'] > 0 && empty($validated['surcharge_reason'])) {
            return redirect()->back()->withErrors(['surcharge_reason' => 'Bắt buộc nhập lý do khi có số tiền phụ thu.'])->withInput();
        }

        $isDraft = ($request->input('submit_action') === 'draft');
        $hasProof = $request->hasFile('proof_image')
            || ($request->filled('proof_image_preview') && $this->isSafeProofReference((string) $request->input('proof_image_preview')));
        if (! $isDraft && ($submitError = $this->receiptSubmitError($validated['payment_method'], $validated['transaction_code'] ?? null, $hasProof))) {
            return redirect()->back()->withErrors($submitError)->withInput();
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
        $scope = $this->branchScope();
        abort_unless($tuition
            ? TuitionBranchScope::allowsTuition($tuition, $scope)
            : TuitionBranchScope::allowsStudent(Student::with('currentClass')->find($studentId), $scope), 403, self::OUT_OF_SCOPE);

        $receiptNumber = TuitionReceipt::generateReceiptNumber();
        // Phiếu thu KHÔNG bao giờ tự duyệt khi lập: chỉ endpoint approve (tuition.approve) mới duyệt & cấp số HĐ.
        $status = $isDraft ? TuitionReceipt::STATUS_DRAFT : TuitionReceipt::STATUS_PENDING;
        $surcharge = (float) ($validated['surcharge_amount'] ?? 0);
        $discount = $tuition ? (float) ($validated['discount_amount'] ?? 0) : 0.0;

        if (! $isDraft && $tuition && ($overpayError = $this->overpaymentError($tuition, (float) $validated['amount'] - $surcharge, $discount))) {
            return redirect()->back()->withErrors(['amount' => $overpayError])->withInput();
        }

        $proofPath = $this->storeProof($request);

        try {
            $receipt = TuitionReceipt::create([
                'receipt_number' => $receiptNumber,
                'invoice_number' => null,
                'student_tuition_id' => $tuition?->id,
                'student_id' => $studentId,
                'amount' => $validated['amount'],
                'tuition_amount' => (float) $validated['amount'] - $surcharge,
                'discount_amount' => $discount,
                'surcharge_amount' => $surcharge,
                'surcharge_reason' => $validated['surcharge_reason'] ?? null,
                'payment_method' => $validated['payment_method'],
                'transaction_code' => $validated['transaction_code'] ?? null,
                'paper_invoice_number' => $validated['paper_invoice_number'] ?? null,
                'payer_name' => $validated['payer_name'] ?? $tuition?->student?->parent_name ?? $tuition?->student?->name,
                'payer_phone' => $validated['payer_phone'] ?? $tuition?->student?->phone,
                'is_vat_invoice' => $request->boolean('is_vat_invoice'),
                'proof_image' => $proofPath,
                'collected_items' => $collectedItems,
                'payment_date' => now(),
                'creator_id' => Auth::id(),
                'approver_id' => null,
                'status' => $status,
                'notes' => $validated['notes'] ?? 'Lập phiếu thu học phí & phụ thu',
            ]);
        } catch (UniqueConstraintViolationException) {
            return redirect()->back()->withErrors([
                'transaction_code' => 'Mã giao dịch '.$validated['transaction_code'].' vừa được ghi nhận ở một phiếu thu khác.',
            ])->withInput();
        }

        if ($isDraft) {
            return redirect()->route('tuition.receipts.edit', $receipt->id)
                ->with('status', "Đã lưu nháp phiếu thu {$receipt->receipt_number} thành công! Bạn có thể tiếp tục sửa và gửi duyệt.");
        }

        $this->notifyReceiptPending($receipt, $tuition?->student ?? Student::find($studentId));

        return redirect()->route('tuition.receipts.approve', ['selected_id' => $receipt->id])
            ->with('status', "Đã gửi duyệt phiếu thu {$receipt->receipt_number} (Số tiền: ".number_format((float) $receipt->amount, 0, ',', '.').' VNĐ) lên cấp Quản lý / Kế toán!');
    }

    /**
     * Người lập sửa phiếu nháp / bị từ chối rồi lưu nháp hoặc gửi duyệt lại (chuyển sang pending).
     */
    public function updateReceipt(Request $request, $id)
    {
        $validated = $request->validate([
            'discount_amount' => 'nullable|numeric|min:0',
            'surcharge_amount' => 'nullable|numeric|min:0',
            'surcharge_reason' => 'nullable|string|max:500',
            'tuition_amount' => 'nullable|numeric|min:0',
            'amount' => 'required|numeric|min:1000',
            'payment_method' => 'required|string|in:transfer,cash,vietqr,pos',
            'transaction_code' => 'nullable|string|max:100',
            'paper_invoice_number' => 'nullable|string|max:100',
            'payer_name' => 'nullable|string|max:255',
            'payer_phone' => 'nullable|string|max:50',
            'is_vat_invoice' => 'nullable|boolean',
            'notes' => 'nullable|string|max:1000',
            'proof_image' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
            'remove_proof' => 'nullable|boolean',
            'submit_action' => 'nullable|string|in:draft,submit',
        ]);

        $user = $request->user();
        $receipt = TuitionReceipt::with('tuition.student')->findOrFail($id);
        abort_unless((int) $receipt->creator_id === (int) $user->id || $user->hasRole('admin'), 403, 'Chỉ người lập phiếu mới được sửa phiếu này.');

        if ($amountError = $this->receiptAmountError($validated)) {
            return redirect()->back()->withErrors($amountError)->withInput();
        }

        $isDraft = ($validated['submit_action'] ?? null) === 'draft';
        $surcharge = (float) ($validated['surcharge_amount'] ?? 0);
        $discount = $receipt->tuition ? (float) ($validated['discount_amount'] ?? 0) : 0.0;
        $transactionCode = $request->has('transaction_code')
            ? (($validated['transaction_code'] ?? null) ?: null)
            : $receipt->transaction_code;

        $keepsProof = $receipt->proof_image && ! $request->boolean('remove_proof');
        $hasProof = $keepsProof || $request->hasFile('proof_image')
            || ($request->filled('proof_image_preview') && $this->isSafeProofReference((string) $request->input('proof_image_preview')));
        if (! $isDraft && ($submitError = $this->receiptSubmitError($validated['payment_method'], $transactionCode, $hasProof, $receipt->id))) {
            return redirect()->back()->withErrors($submitError)->withInput();
        }

        if (! $isDraft && $receipt->tuition && ($overpayError = $this->overpaymentError($receipt->tuition, (float) $validated['amount'] - $surcharge, $discount))) {
            return redirect()->back()->withErrors(['amount' => $overpayError])->withInput();
        }

        $newProof = $this->storeProof($request);
        $proofPath = $newProof ?? ($keepsProof ? $receipt->proof_image : null);

        try {
            $updated = DB::transaction(function () use ($id, $validated, $isDraft, $surcharge, $discount, $transactionCode, $proofPath, $request) {
                $locked = TuitionReceipt::query()->lockForUpdate()->findOrFail($id);
                if (! in_array($locked->status, TuitionReceipt::EDITABLE_STATUSES, true)) {
                    return null;
                }

                $locked->update([
                    'amount' => $validated['amount'],
                    'tuition_amount' => (float) $validated['amount'] - $surcharge,
                    'discount_amount' => $discount,
                    'surcharge_amount' => $surcharge,
                    'surcharge_reason' => $validated['surcharge_reason'] ?? null,
                    'payment_method' => $validated['payment_method'],
                    'transaction_code' => $transactionCode,
                    'paper_invoice_number' => $validated['paper_invoice_number'] ?? $locked->paper_invoice_number,
                    'payer_name' => $validated['payer_name'] ?? $locked->payer_name,
                    'payer_phone' => $validated['payer_phone'] ?? $locked->payer_phone,
                    'is_vat_invoice' => $request->has('is_vat_invoice') ? $request->boolean('is_vat_invoice') : $locked->is_vat_invoice,
                    'proof_image' => $proofPath,
                    'notes' => $validated['notes'] ?? $locked->notes,
                    'status' => $isDraft ? TuitionReceipt::STATUS_DRAFT : TuitionReceipt::STATUS_PENDING,
                    'approver_id' => null,
                    'rejection_reason' => $isDraft ? $locked->rejection_reason : null,
                ]);

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            return redirect()->back()->withErrors([
                'transaction_code' => 'Mã giao dịch '.$transactionCode.' vừa được ghi nhận ở một phiếu thu khác.',
            ])->withInput();
        }

        if (! $updated) {
            return redirect()->back()->withErrors(['receipt' => 'Chỉ sửa được phiếu ở trạng thái Bản nháp hoặc Bị từ chối.']);
        }

        if ($isDraft) {
            return redirect()->back()->with('status', "Đã lưu nháp phiếu thu {$updated->receipt_number}.");
        }

        $this->notifyReceiptPending($updated, $receipt->tuition?->student ?? $receipt->student);

        return redirect()->route('tuition.receipts.approve', ['selected_id' => $updated->id])
            ->with('status', "Đã gửi duyệt lại phiếu thu {$updated->receipt_number}.");
    }

    /**
     * Điều kiện gửi duyệt (không áp dụng khi lưu nháp):
     * - Chuyển khoản / VietQR / POS bắt buộc có minh chứng (tiền mặt được miễn).
     * - Mã giao dịch chuyển khoản không được trùng phiếu khác đang chờ duyệt / đã duyệt,
     *   hay giao dịch SePay đã tự động gạch nợ.
     *
     * @return array<string, string>|null
     */
    private function receiptSubmitError(string $method, ?string $transactionCode, bool $hasProof, ?int $ignoreReceiptId = null): ?array
    {
        if (in_array($method, TuitionReceipt::PROOF_REQUIRED_METHODS, true) && ! $hasProof) {
            return ['proof_image' => 'Bắt buộc đính kèm minh chứng (ủy nhiệm chi / ảnh chuyển khoản / biên lai POS) khi gửi duyệt phiếu thu không dùng tiền mặt.'];
        }

        if (in_array($method, TuitionReceipt::TRANSFER_METHODS, true) && TuitionReceipt::normalizeReference($transactionCode) !== null) {
            if ($duplicate = TuitionReceipt::findByTransferReference($transactionCode, $ignoreReceiptId)) {
                return ['transaction_code' => "Mã giao dịch {$transactionCode} đã được ghi nhận ở phiếu {$duplicate->receipt_number} ({$duplicate->status_label}). Không ghi nhận một khoản chuyển khoản 2 lần."];
            }

            if ($sepay = $this->appliedSepayTransactionFor($transactionCode, $ignoreReceiptId)) {
                return ['transaction_code' => "Mã giao dịch {$transactionCode} đã được SePay tự động gạch nợ"
                    .($sepay->receipt ? " (phiếu {$sepay->receipt->receipt_number})" : '').'. Không lập thêm phiếu thu tay cho giao dịch này.'];
            }
        }

        return null;
    }

    /**
     * Giao dịch SePay (theo mã giao dịch / mã tham chiếu) đã được ghi nhận bằng một phiếu thu KHÁC.
     * Giao dịch SePay đến sau phiếu tay chờ duyệt cùng mã được gắn vào chính phiếu tay đó (duplicate_manual):
     * không được coi là "đã gạch nợ" với chính phiếu ấy, nếu không phiếu tay không duyệt / gửi lại được.
     */
    private function appliedSepayTransactionFor(?string $transactionCode, ?int $ignoreReceiptId = null): ?SepayTransaction
    {
        $normalized = TuitionReceipt::normalizeReference($transactionCode);
        if ($normalized === null) {
            return null;
        }

        return SepayTransaction::with('receipt')
            ->whereNotNull('matched_receipt_id')
            ->when($ignoreReceiptId, fn ($q) => $q->where('matched_receipt_id', '!=', $ignoreReceiptId))
            ->where(function ($q) use ($normalized) {
                $q->whereRaw('UPPER(sepay_id) = ?', [$normalized])
                    ->orWhereRaw('UPPER(reference_code) = ?', [$normalized]);
            })
            ->first();
    }

    /** Lưu file minh chứng (ảnh/PDF, kiểm tra theo nội dung) hoặc tham chiếu an toàn; không có -> null. */
    private function storeProof(Request $request): ?string
    {
        if ($request->hasFile('proof_image')) {
            $fileName = SafeUploadService::moveTo($request->file('proof_image'), public_path('uploads/tuition/receipts'), self::PROOF_EXTENSIONS, 'proof_image');

            return '/uploads/tuition/receipts/'.$fileName;
        }

        if ($request->filled('proof_image_preview') && $this->isSafeProofReference((string) $request->input('proof_image_preview'))) {
            return (string) $request->input('proof_image_preview');
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    private function receiptAmountError(array $validated): ?array
    {
        $amount = (float) $validated['amount'];
        $surcharge = (float) ($validated['surcharge_amount'] ?? 0);

        if ($surcharge > 0 && empty($validated['surcharge_reason'])) {
            return ['surcharge_reason' => 'Bắt buộc nhập lý do khi có số tiền phụ thu.'];
        }

        if ($surcharge > $amount) {
            return ['surcharge_amount' => 'Tiền phụ thu không được lớn hơn tổng tiền của phiếu.'];
        }

        if (isset($validated['tuition_amount']) && $validated['tuition_amount'] !== null
            && abs(((float) $validated['tuition_amount'] + $surcharge) - $amount) > 0.5) {
            return ['amount' => 'Tổng thu phải bằng tiền học phí + tiền phụ thu.'];
        }

        return null;
    }

    /**
     * Chặn thu vượt công nợ còn lại: phần học phí + chiết khấu của phiếu không được lớn hơn debt_amount.
     */
    private function overpaymentError(StudentTuition $tuition, float $tuitionPortion, float $discount): ?string
    {
        $remaining = (float) $tuition->debt_amount;
        if ($tuitionPortion + $discount > $remaining + 0.5) {
            return 'Phần học phí ('.number_format($tuitionPortion, 0, ',', '.').' VNĐ) + chiết khấu ('
                .number_format($discount, 0, ',', '.').' VNĐ) vượt quá công nợ còn lại '
                .number_format($remaining, 0, ',', '.').' VNĐ.';
        }

        return null;
    }

    /**
     * Phiếu thu mới / gửi lại chờ duyệt:
     * - Thông báo chung (user_id = NULL) cho Admin / Quản lý như trước.
     * - Thông báo cá nhân cho từng Kế toán của chi nhánh ghi nhận học phí (kế toán không gán chi nhánh = kế toán
     *   toàn hệ thống cũng nhận). Người lập phiếu không tự nhận thông báo duyệt phiếu của mình.
     */
    private function notifyReceiptPending(TuitionReceipt $receipt, ?Student $student): void
    {
        $studentId = $student?->id ?? $receipt->student_id;
        $stName = $student?->name ?? 'Học viên';
        $message = "Nhân viên vừa lập phiếu thu #{$receipt->receipt_number} (".number_format((float) $receipt->amount, 0, ',', '.')." VNĐ) cho học viên {$stName}. Vui lòng đối chiếu chứng từ và phê duyệt.";
        $data = [
            'receipt_id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'amount' => $receipt->amount,
            'student_id' => $studentId,
            'link' => route('tuition.receipts.approve', ['selected_id' => $receipt->id]),
        ];

        try {
            AdminNotification::create([
                'user_id' => null,
                'type' => 'receipt_pending',
                'title' => 'Phiếu thu mới chờ phê duyệt',
                'message' => $message,
                'data' => $data,
                'is_read' => false,
            ]);

            $branchId = $this->receiptBranchId($receipt);
            $accountants = User::query()
                ->where('is_active', true)
                ->whereKeyNot((int) $receipt->creator_id)
                ->whereHas('roles', fn ($q) => $q->where('name', 'accountant'))
                ->where(function ($q) use ($branchId) {
                    $q->where(function ($noBranch) {
                        $noBranch->whereNull('branch_id')->whereDoesntHave('branches');
                    });
                    if ($branchId) {
                        $q->orWhere('branch_id', $branchId)
                            ->orWhereHas('branches', fn ($b) => $b->where('branches.id', $branchId));
                    }
                })
                ->pluck('id');

            foreach ($accountants as $accountantId) {
                AdminNotification::create([
                    'user_id' => $accountantId,
                    'type' => 'receipt_pending',
                    'title' => 'Phiếu thu mới chờ bạn phê duyệt',
                    'message' => $message,
                    'data' => $data,
                    'is_read' => false,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Lỗi tạo thông báo chờ duyệt phiếu: '.$e->getMessage());
        }
    }

    public function approveReceipt(Request $request)
    {
        $scope = $this->branchScope();
        $scoped = fn () => TuitionBranchScope::receipts(TuitionReceipt::query(), $scope);
        $branches = TuitionBranchScope::branches($scope)->get();

        // Metrics
        $pendingCount = $scoped()->where('status', 'pending')->count();
        $pendingTotal = $scoped()->where('status', 'pending')->sum('amount');
        $approvedTodayCount = $scoped()->where('status', 'approved')->whereDate('updated_at', today())->count();
        $rejectedTodayCount = $scoped()->where('status', 'rejected')->whereDate('updated_at', today())->count();

        // Query
        $query = $scoped()->with([
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
            if (! TuitionBranchScope::allowsReceipt($selectedReceipt, $scope)) {
                $selectedReceipt = null;
            }
        }

        if (! $selectedReceipt && $pendingReceipts->isNotEmpty()) {
            $selectedReceipt = $pendingReceipts->first();
        }

        if (! $selectedReceipt) {
            $selectedReceipt = $scoped()->with([
                'tuition.student.branch',
                'tuition.classModel',
                'student.branch',
                'student.currentClass',
                'creator',
                'approver',
            ])->latest()->first();
        }

        // Cảnh báo trùng với giao dịch SePay đã tự động gạch nợ (chỉ phiếu chuyển khoản đang chờ duyệt).
        $sepayWarnings = collect();
        if ($selectedReceipt && $selectedReceipt->status === TuitionReceipt::STATUS_PENDING) {
            $exact = $this->appliedSepayTransactionFor($selectedReceipt->transaction_code, $selectedReceipt->id);
            $sepayWarnings = $exact && in_array($selectedReceipt->payment_method, TuitionReceipt::TRANSFER_METHODS, true)
                ? collect([$exact])
                : $this->similarSepayTransactions($selectedReceipt);
        }

        return view('tuition.approve-receipt', compact(
            'sepayWarnings',
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
        $user = $request->user();
        abort_unless(TuitionBranchScope::allowsReceipt(TuitionReceipt::with(['tuition.student.currentClass', 'student.currentClass'])->findOrFail($id), $this->branchScope()), 403, self::OUT_OF_SCOPE);
        $tuitionId = TuitionReceipt::query()->whereKey($id)->value('student_tuition_id');

        // Khoá công nợ trước (cùng thứ tự với SePay/hoàn phí), rồi khoá phiếu; duyệt + tính lại nợ trong CÙNG transaction.
        $confirmNotDuplicate = $request->boolean('confirm_not_duplicate');

        $result = DB::transaction(function () use ($id, $tuitionId, $user, $confirmNotDuplicate) {
            $tuition = $tuitionId ? StudentTuition::query()->lockForUpdate()->find($tuitionId) : null;
            $receipt = TuitionReceipt::query()->lockForUpdate()->findOrFail($id);

            if ($receipt->status !== TuitionReceipt::STATUS_PENDING) {
                return 'Phiếu thu này đã được xử lý trước đó.';
            }

            if ((int) $receipt->creator_id === (int) $user->id && ! $user->hasRole('admin')) {
                return 'Người lập phiếu không được tự duyệt phiếu của mình. Vui lòng chuyển Kế toán/Quản lý khác duyệt.';
            }

            if ($duplicateError = $this->sepayDuplicateError($receipt, $confirmNotDuplicate)) {
                return $duplicateError;
            }

            if ($tuition) {
                $tuition->recalculateDebt();
                if ($overpayError = $this->overpaymentError($tuition, $receipt->tuitionPortion(), (float) $receipt->discount_amount)) {
                    return 'Không thể duyệt: '.$overpayError;
                }
            }

            try {
                $invoiceNumber = $receipt->invoice_number
                    ?? InvoiceConfiguration::consumeNextInvoiceNumber($this->receiptBranchId($receipt, $tuition));
            } catch (InvoiceRangeExhaustedException $e) {
                return 'Không thể duyệt: '.$e->getMessage();
            }

            $receipt->update([
                'invoice_number' => $invoiceNumber,
                'status' => TuitionReceipt::STATUS_APPROVED,
                'approver_id' => $user->id,
                'rejection_reason' => null,
            ]);

            $tuition?->recalculateDebt();
            $this->linkUnappliedSepayTransaction($receipt);

            return $receipt;
        });

        if (is_string($result)) {
            return redirect()->back()->withErrors(['receipt' => $result]);
        }

        $receipt = $result;
        $receipt->load(['tuition.student', 'student']);
        $invoiceNumber = $receipt->invoice_number;

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

    /**
     * Chống ghi nhận chuyển khoản 2 lần (phiếu tay + SePay tự động):
     * - Cùng mã giao dịch với giao dịch SePay đã gạch nợ -> chặn hẳn.
     * - Có giao dịch SePay đã gạch nợ cho cùng học viên / hợp đồng, cùng số tiền, trong ±N ngày -> cảnh báo,
     *   chỉ duyệt khi kế toán tick xác nhận "không trùng".
     */
    private function sepayDuplicateError(TuitionReceipt $receipt, bool $confirmed): ?string
    {
        if (! in_array($receipt->payment_method, TuitionReceipt::TRANSFER_METHODS, true)) {
            return null;
        }

        if ($applied = $this->appliedSepayTransactionFor($receipt->transaction_code, $receipt->id)) {
            return 'Không thể duyệt: mã giao dịch '.$receipt->transaction_code.' đã được SePay tự động gạch nợ'
                .($applied->receipt ? ' (phiếu '.$applied->receipt->receipt_number.')' : '').'. Vui lòng từ chối phiếu thu tay này.';
        }

        if ($confirmed) {
            return null;
        }

        $similar = $this->similarSepayTransactions($receipt)->first();
        if ($similar) {
            return 'Cảnh báo trùng giao dịch: SePay đã tự động ghi nhận '.number_format((float) $similar->transfer_amount, 0, ',', '.')
                .' VNĐ cho học viên này ngày '.$similar->transaction_date?->format('d/m/Y H:i')
                .($similar->receipt ? ' (phiếu '.$similar->receipt->receipt_number.')' : '')
                .'. Nếu chắc chắn đây là khoản chuyển khác, hãy tick "Xác nhận không trùng giao dịch SePay" rồi duyệt lại.';
        }

        return null;
    }

    /**
     * Giao dịch SePay đã gạch nợ cho cùng học viên / hợp đồng, cùng số tiền, trong khoảng thời gian gần ngày nộp.
     *
     * @return Collection<int, SepayTransaction>
     */
    private function similarSepayTransactions(TuitionReceipt $receipt)
    {
        if (! in_array($receipt->payment_method, TuitionReceipt::TRANSFER_METHODS, true)) {
            return collect();
        }

        $windowDays = (int) config('tuition.sepay_duplicate_window_days', 3);
        $paidAt = $receipt->payment_date ?? $receipt->created_at ?? now();
        $studentId = $receipt->student_id ?? $receipt->tuition?->student_id;

        return SepayTransaction::with('receipt')
            ->whereNotNull('matched_receipt_id')
            ->when($receipt->id, fn ($q) => $q->where('matched_receipt_id', '!=', $receipt->id))
            ->where(function ($q) use ($receipt, $studentId) {
                if ($receipt->student_tuition_id) {
                    $q->orWhere('matched_tuition_id', $receipt->student_tuition_id);
                }
                if ($studentId) {
                    $q->orWhere('matched_student_id', $studentId);
                }
            })
            ->when(! $receipt->student_tuition_id && ! $studentId, fn ($q) => $q->whereRaw('1 = 0'))
            ->whereBetween('transaction_date', [
                $paidAt->copy()->startOfDay()->subDays($windowDays),
                $paidAt->copy()->endOfDay()->addDays($windowDays),
            ])
            ->get()
            ->filter(fn (SepayTransaction $tx) => abs((float) $tx->transfer_amount - (float) $receipt->amount) < 1
                || ($tx->receipt && abs((float) $tx->receipt->amount - (float) $receipt->amount) < 1))
            ->values();
    }

    /**
     * Giao dịch SePay cùng mã nhưng chưa được gạch nợ tự động (không khớp học viên / đã hết nợ) được đánh dấu
     * là đã đối soát bằng phiếu tay này, để lần sau không bị xử lý lại.
     */
    private function linkUnappliedSepayTransaction(TuitionReceipt $receipt): void
    {
        $normalized = TuitionReceipt::normalizeReference($receipt->transaction_code);
        if ($normalized === null || ! in_array($receipt->payment_method, TuitionReceipt::TRANSFER_METHODS, true)) {
            return;
        }

        SepayTransaction::query()
            ->whereNull('matched_receipt_id')
            ->where(function ($q) use ($normalized) {
                $q->whereRaw('UPPER(sepay_id) = ?', [$normalized])
                    ->orWhereRaw('UPPER(reference_code) = ?', [$normalized]);
            })
            ->update([
                'status' => 'manual_matched',
                'matched_receipt_id' => $receipt->id,
                'matched_tuition_id' => $receipt->student_tuition_id,
                'matched_student_id' => $receipt->student_id ?? $receipt->tuition?->student_id,
                'response_message' => 'Đã đối soát thủ công bằng phiếu thu '.$receipt->receipt_number.'.',
                'updated_at' => now(),
            ]);
    }

    /** Chi nhánh ghi nhận phiếu thu: theo hợp đồng học phí, fallback chi nhánh học viên. */
    private function receiptBranchId(TuitionReceipt $receipt, ?StudentTuition $tuition = null): ?int
    {
        $tuition ??= $receipt->tuition;
        $branchId = $tuition?->branch_id
            ?? $tuition?->student?->branch_id
            ?? $receipt->student?->branch_id;

        return $branchId ? (int) $branchId : null;
    }

    /** Chi nhánh cấp số HĐ cho phiếu sinh tự động (hoàn phí / chuyển nhượng) của một khoản học phí. */
    private function tuitionBranchId(StudentTuition $tuition): ?int
    {
        $branchId = $tuition->branch_id ?? $tuition->student?->branch_id;

        return $branchId ? (int) $branchId : null;
    }

    public function rejectReceiptAction(Request $request, $id)
    {
        abort_unless(TuitionBranchScope::allowsReceipt(TuitionReceipt::with(['tuition.student.currentClass', 'student.currentClass'])->findOrFail($id), $this->branchScope()), 403, self::OUT_OF_SCOPE);
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

        if ($receipt->creator_id) {
            try {
                AdminNotification::create([
                    'user_id' => $receipt->creator_id,
                    'type' => 'receipt_rejected',
                    'title' => 'Phiếu thu bị trả về cần sửa',
                    'message' => "Phiếu thu #{$receipt->receipt_number} bị từ chối: {$receipt->rejection_reason}. Vui lòng sửa và gửi duyệt lại.",
                    'data' => [
                        'receipt_id' => $receipt->id,
                        'link' => route('tuition.receipts.edit', $receipt->id),
                    ],
                    'is_read' => false,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Lỗi tạo thông báo trả phiếu thu: '.$e->getMessage());
            }
        }

        return redirect()->back()->with('status', "Đã từ chối phiếu thu {$receipt->receipt_number} và trả về người lập để chỉnh sửa, gửi duyệt lại!");
    }

    public function history(Request $request)
    {
        $receipts = TuitionBranchScope::receipts(TuitionReceipt::query(), $this->branchScope())
            ->with(['tuition.student', 'tuition.classModel', 'student', 'creator', 'approver'])
            ->latest()
            ->paginate($request->perPage(15))
            ->withQueryString();

        return view('tuition.history', compact('receipts'));
    }

    public function invoiceCancellations(Request $request)
    {
        $scope = $this->branchScope();
        $scoped = fn () => $this->scopedCancellations($scope);
        $branches = TuitionBranchScope::branches($scope)->get();

        // Metrics
        $pendingCount = $scoped()->where('status', 'pending')->count();
        $approvedMonthCount = $scoped()->where('status', 'approved')
            ->whereMonth('updated_at', now()->month)
            ->whereYear('updated_at', now()->year)
            ->count();
        $rejectedCount = $scoped()->where('status', 'rejected')->count();

        // Query
        $query = $scoped()->with([
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

        if ($selectedCancellation && $scope !== null && ! $scoped()->whereKey($selectedCancellation->id)->exists()) {
            $selectedCancellation = null;
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

    /** Yêu cầu hủy hóa đơn trong phạm vi chi nhánh: theo phiếu thu gắn kèm, thiếu phiếu thì theo học viên. */
    private function scopedCancellations(?array $scope)
    {
        $query = InvoiceCancellation::query();
        if ($scope === null) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereHas('receipt', fn ($r) => TuitionBranchScope::receipts($r, $scope))
            ->orWhere(fn ($q) => $q->whereNull('tuition_receipt_id')
                ->whereHas('student', fn ($s) => TuitionBranchScope::students($s, $scope)))
            // Yêu cầu không gắn phiếu lẫn học viên (dữ liệu cũ) không thuộc chi nhánh nào: vẫn hiện để xử lý
            // (duyệt sẽ bị chặn vì không có phiếu để hoàn tác công nợ).
            ->orWhere(fn ($q) => $q->whereNull('tuition_receipt_id')->whereNull('student_id')));
    }

    private function abortUnlessCancellationInScope($id): void
    {
        $scope = $this->branchScope();
        abort_if($scope !== null && ! $this->scopedCancellations($scope)->whereKey($id)->exists(), 403, self::OUT_OF_SCOPE);
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

        // Luôn xác định phiếu thu từ số hóa đơn phía server (không tin tuition_receipt_id/amount từ form).
        $receipt = TuitionReceipt::with('tuition')->where('invoice_number', trim($validated['invoice_number']))->first();
        if (! $receipt || $receipt->status !== TuitionReceipt::STATUS_APPROVED) {
            return redirect()->back()->withErrors([
                'invoice_number' => 'Không tìm thấy hóa đơn đã duyệt với số '.$validated['invoice_number'].'.',
            ])->withInput();
        }
        if (abs((float) $validated['amount'] - (float) $receipt->amount) > 0.009) {
            return redirect()->back()->withErrors([
                'amount' => 'Số tiền hủy phải bằng đúng giá trị hóa đơn ('.number_format((float) $receipt->amount, 0, ',', '.').' VNĐ).',
            ])->withInput();
        }
        if (InvoiceCancellation::where('tuition_receipt_id', $receipt->id)->where('status', 'pending')->exists()) {
            return redirect()->back()->withErrors([
                'invoice_number' => 'Hóa đơn này đang có yêu cầu hủy chờ duyệt.',
            ])->withInput();
        }

        $proofPath = null;
        if ($request->hasFile('proof_image')) {
            $fileName = SafeUploadService::moveTo($request->file('proof_image'), public_path('uploads/tuition/cancellations'), self::PROOF_EXTENSIONS, 'proof_image');
            $proofPath = '/uploads/tuition/cancellations/'.$fileName;
        }

        InvoiceCancellation::create([
            'invoice_number' => $receipt->invoice_number,
            'tuition_receipt_id' => $receipt->id,
            'student_id' => $receipt->student_id ?? $receipt->tuition?->student_id,
            'amount' => $receipt->amount,
            'reason' => $validated['reason'],
            'proof_image' => $proofPath,
            'requester_id' => Auth::id(),
            'status' => 'pending',
        ]);

        return redirect()->back()->with('status', 'Đã gửi yêu cầu hủy hóa đơn GTGT lên cấp quản lý phê duyệt!');
    }

    public function approveInvoiceCancellation(Request $request, $id)
    {
        $this->abortUnlessCancellationInScope($id);
        $receiptId = InvoiceCancellation::query()->whereKey($id)->value('tuition_receipt_id');
        $tuitionId = $receiptId ? TuitionReceipt::query()->whereKey($receiptId)->value('student_tuition_id') : null;

        $result = DB::transaction(function () use ($id, $tuitionId) {
            $tuition = $tuitionId ? StudentTuition::query()->lockForUpdate()->find($tuitionId) : null;
            $cancellation = InvoiceCancellation::query()->lockForUpdate()->findOrFail($id);
            if ($cancellation->status !== 'pending') {
                return 'Yêu cầu hủy này đã được xử lý trước đó.';
            }

            $receipt = $cancellation->tuition_receipt_id
                ? TuitionReceipt::query()->lockForUpdate()->find($cancellation->tuition_receipt_id)
                : null;
            if (! $receipt || $receipt->status !== TuitionReceipt::STATUS_APPROVED) {
                return 'Không xác định được phiếu thu đã duyệt gắn với hóa đơn này, không thể hoàn tác công nợ.';
            }

            $cancellation->update([
                'status' => 'approved',
                'approver_id' => Auth::id(),
                'rejection_reason' => null,
            ]);

            // Hoa hồng của phiếu đã chi trong kỳ lương đã duyệt → thu hồi ở kỳ kế tiếp (kỳ chưa duyệt tự tính lại).
            $clawback = app(SalesCommissionService::class)
                ->recordCancellationClawback($receipt, Auth::user(), (string) $cancellation->invoice_number);
            $cancellation->setAttribute('commission_clawback', $clawback);

            // Giữ nguyên invoice_number (không tái sử dụng số HĐ), chỉ vô hiệu phiếu.
            $receipt->update([
                'status' => TuitionReceipt::STATUS_CANCELLED,
                'rejection_reason' => 'Hóa đơn/phiếu thu đã được duyệt hủy: '.$cancellation->reason,
            ]);
            $tuition?->recalculateDebt();

            return $cancellation;
        });

        if (is_string($result)) {
            return redirect()->back()->withErrors(['cancellation' => $result]);
        }

        $message = "Đã duyệt hủy hóa đơn {$result->invoice_number} và hoàn tác công nợ học viên!";
        if ($clawback = $result->getAttribute('commission_clawback')) {
            $message .= ' Kỳ lương chứa phiếu đã duyệt: thu hồi '.number_format(abs((float) $clawback->amount), 0, ',', '.')
                .' VNĐ hoa hồng của '.($clawback->user?->name ?? 'sale').' ở lần tính lương kế tiếp.';
        }

        return redirect()->back()->with('status', $message);
    }

    public function rejectInvoiceCancellation(Request $request, $id)
    {
        $this->abortUnlessCancellationInScope($id);
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
        $scope = $this->branchScope();
        $refundRequests = TuitionRefundRequest::with(['student.currentClass', 'student.tuition', 'targetStudent.currentClass', 'requester', 'approver', 'clawbackUser'])
            ->when($scope !== null, fn ($q) => $q->whereHas('student', fn ($s) => TuitionBranchScope::students($s, $scope)))
            ->latest()->get();
        // Gợi ý thu hồi hoa hồng cho hồ sơ hoàn phí đang chờ duyệt (học < 1 tháng → có).
        $commissionService = app(SalesCommissionService::class);
        $clawbackHints = $refundRequests->where('status', 'pending')->where('type', 'refund')
            ->mapWithKeys(fn (TuitionRefundRequest $refund) => [$refund->id => [
                'suggest' => $commissionService->suggestClawback($refund),
                'amount' => $commissionService->suggestedClawbackAmount($refund),
                'owner' => ($ownerId = $commissionService->ownerOfStudent((int) $refund->student_id)) ? User::find($ownerId)?->name : null,
                'start' => $commissionService->studyStartDate((int) $refund->student_id),
            ]]);
        $students = TuitionBranchScope::students(Student::with(['currentClass.course', 'tuition.classModel.course', 'branch']), $scope)->orderBy('name')->get();

        // Số liệu thật cho bảng tính hoàn phí / chuyển nhượng / bảo lưu (không còn số ghi cứng).
        $studentFinance = $students->mapWithKeys(fn (Student $student) => [$student->id => $this->refundBasis($student)]);
        $adminFeePercent = (float) config('tuition.refund_admin_fee_percent', 10);

        return view('tuition.refunds', compact('refundRequests', 'students', 'clawbackHints', 'studentFinance', 'adminFeePercent'));
    }

    /**
     * Căn cứ tính hoàn phí từ hợp đồng thật: đã nộp, giá trị hợp đồng, tổng buổi / đã học (điểm danh),
     * đơn giá / buổi, phí quản trị theo chính sách và số tiền hoàn đề xuất.
     *
     * @return array<string, mixed>
     */
    private function refundBasis(Student $student): array
    {
        $tuition = $student->tuition;
        $stats = $tuition?->sessionStats();
        $paid = (float) ($tuition?->paid_amount ?? 0);
        $contract = (float) ($tuition?->final_amount ?? 0);
        $total = $stats['total'] ?? null;
        $attended = $stats['attended'] ?? 0;
        $unitPrice = $total ? round($contract / $total, 2) : 0.0;
        $used = min($paid, round($unitPrice * $attended, 2));
        $remainingValue = max(0.0, round($paid - $used, 2));
        $feePercent = (float) config('tuition.refund_admin_fee_percent', 10);
        $adminFee = round($remainingValue * $feePercent / 100, 0);

        return [
            'has_tuition' => (bool) $tuition,
            'paid' => $paid,
            'contract' => $contract,
            'debt' => (float) ($tuition?->debt_amount ?? 0),
            'due_date' => $tuition?->due_date?->format('Y-m-d'),
            'total_sessions' => $total,
            'attended_sessions' => $attended,
            'remaining_sessions' => $stats['remaining'] ?? null,
            'unit_price' => $unitPrice,
            'remaining_value' => $remainingValue,
            'admin_fee' => $adminFee,
            'suggested_refund' => max(0.0, $remainingValue - $adminFee),
            'status_label' => $student->status_label,
        ];
    }

    public function storeRefundRequest(Request $request)
    {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'type' => 'required|string|in:refund,extension,transfer,deferral',
            'total_paid' => 'nullable|numeric|min:0',
            'attended_lessons' => 'nullable|integer|min:0',
            'admin_fee' => 'nullable|numeric|min:0',
            'refund_amount' => 'nullable|required_if:type,refund,transfer|numeric|min:0',
            'target_student_id' => 'nullable|required_if:type,transfer|exists:students,id|different:student_id',
            'extended_due_date' => 'nullable|required_if:type,extension|date|after:today',
            'defer_from' => 'nullable|required_if:type,deferral|date',
            'defer_to' => 'nullable|required_if:type,deferral|date|after:defer_from',
            'reason' => 'required|string|max:1000',
        ], [
            'extended_due_date.required_if' => 'Khất nợ cần chọn hạn đóng mới.',
            'extended_due_date.after' => 'Hạn đóng mới phải sau hôm nay.',
            'defer_from.required_if' => 'Bảo lưu cần chọn ngày bắt đầu.',
            'defer_to.required_if' => 'Bảo lưu cần chọn ngày kết thúc.',
            'defer_to.after' => 'Ngày kết thúc bảo lưu phải sau ngày bắt đầu.',
        ]);

        $student = Student::with(['tuition', 'currentClass'])->find($validated['student_id']);
        $type = $validated['type'];
        abort_unless(TuitionBranchScope::allowsStudent($student, $this->branchScope()), 403, self::OUT_OF_SCOPE);

        if (in_array($type, [TuitionRefundRequest::TYPE_EXTENSION, TuitionRefundRequest::TYPE_DEFERRAL], true) && ! $student?->tuition) {
            return redirect()->back()->withErrors(['student_id' => 'Học viên chưa có hồ sơ học phí để khất nợ / bảo lưu.'])->withInput();
        }

        if ($type === TuitionRefundRequest::TYPE_EXTENSION && $student->tuition->due_date
            && Carbon::parse($validated['extended_due_date'])->lte($student->tuition->due_date)) {
            return redirect()->back()->withErrors(['extended_due_date' => 'Hạn mới phải sau hạn đóng hiện tại ('.$student->tuition->due_date->format('d/m/Y').').'])->withInput();
        }

        $basis = $student ? $this->refundBasis($student) : [];
        $totalPaid = $validated['total_paid'] ?? ($student?->tuition?->paid_amount ?? ($validated['refund_amount'] ?? 0));

        TuitionRefundRequest::create([
            'student_id' => $validated['student_id'],
            'type' => $type,
            'total_paid' => $totalPaid,
            'attended_lessons' => $validated['attended_lessons'] ?? ($basis['attended_sessions'] ?? 0),
            'admin_fee' => $validated['admin_fee'] ?? 0,
            'refund_amount' => in_array($type, [TuitionRefundRequest::TYPE_REFUND, TuitionRefundRequest::TYPE_TRANSFER], true)
                ? $validated['refund_amount']
                : 0,
            'extended_due_date' => $type === TuitionRefundRequest::TYPE_EXTENSION ? $validated['extended_due_date'] : null,
            'defer_from' => $type === TuitionRefundRequest::TYPE_DEFERRAL ? $validated['defer_from'] : null,
            'defer_to' => $type === TuitionRefundRequest::TYPE_DEFERRAL ? $validated['defer_to'] : null,
            'target_student_id' => $type === 'transfer' ? ($validated['target_student_id'] ?? null) : null,
            'reason' => $validated['reason'],
            'requester_id' => Auth::id(),
            'status' => 'pending',
        ]);

        $typeLabel = match ($type) {
            'transfer' => 'chuyển nhượng số dư học phí sang học viên khác',
            'extension' => 'khất nợ (dời hạn đóng)',
            'deferral' => 'bảo lưu học phí',
            default => 'hoàn trả học phí',
        };

        return redirect()->back()->with('status', "Đã lập hồ sơ {$typeLabel} và gửi lên cấp Quản lý/Kế toán phê duyệt!");
    }

    public function approveRefundRequest(Request $request, $id)
    {
        // Phase 3 (A6): người duyệt hoàn phí chọn có thu hồi hoa hồng của sale hay không.
        $clawbackInput = $request->validate([
            'clawback_commission' => ['nullable', 'boolean'],
            'clawback_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        abort_unless(TuitionBranchScope::allowsStudent(TuitionRefundRequest::with('student.currentClass')->findOrFail($id)->student, $this->branchScope()), 403, self::OUT_OF_SCOPE);

        $result = DB::transaction(function () use ($id, $clawbackInput) {
            $refund = TuitionRefundRequest::query()->lockForUpdate()->findOrFail($id);
            $refund->load(['student', 'targetStudent']);

            if ($refund->status !== 'pending') {
                return ['error' => 'Yêu cầu này đã được xử lý trước đó.'];
            }

            if (in_array($refund->type, [TuitionRefundRequest::TYPE_EXTENSION, TuitionRefundRequest::TYPE_DEFERRAL], true)) {
                return $this->applyExtensionOrDeferral($refund);
            }

            $amount = round((float) $refund->refund_amount, 2);
            if ($amount <= 0) {
                return ['error' => 'Số tiền hoàn/chuyển nhượng phải lớn hơn 0.'];
            }

            // Khoá cả 2 dòng công nợ theo thứ tự id để tránh deadlock khi có chuyển nhượng chéo đồng thời.
            $sourceId = StudentTuition::where('student_id', $refund->student_id)->value('id');
            $targetId = $refund->type === 'transfer' && $refund->target_student_id
                ? StudentTuition::where('student_id', $refund->target_student_id)->value('id')
                : null;
            $locked = StudentTuition::query()
                ->whereIn('id', array_filter([$sourceId, $targetId]))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $sourceTuition = $sourceId ? $locked->get($sourceId) : null;
            $targetTuition = $targetId ? $locked->get($targetId) : null;

            if (! $sourceTuition) {
                return ['error' => 'Học viên chưa có khoản học phí để hoàn/chuyển nhượng.'];
            }
            $sourceTuition->recalculateDebt();
            if ($amount > (float) $sourceTuition->paid_amount) {
                return ['error' => 'Số tiền xử lý vượt quá số tiền học viên đã nộp ('.number_format((float) $sourceTuition->paid_amount, 0, ',', '.').' VNĐ).'];
            }

            if ($refund->type === 'transfer') {
                if (! $targetTuition) {
                    return ['error' => 'Học viên nhận chưa có khoản học phí để cấn trừ.', 'field' => 'target_student_id'];
                }
                $targetTuition->recalculateDebt();
                if ($amount > (float) $targetTuition->debt_amount) {
                    return ['error' => 'Số tiền chuyển nhượng vượt quá công nợ còn lại của học viên nhận ('.number_format((float) $targetTuition->debt_amount, 0, ',', '.').' VNĐ). Vui lòng điều chỉnh số tiền.'];
                }
            }

            $refund->update(['status' => 'approved', 'approver_id' => Auth::id()]);
            // Chuyển nhượng phí không bao giờ thu hồi; hoàn phí theo lựa chọn (mặc định: gợi ý theo thời gian đã học).
            app(SalesCommissionService::class)->recordRefundDecision(
                $refund,
                array_key_exists('clawback_commission', $clawbackInput) && $clawbackInput['clawback_commission'] !== null ? (bool) $clawbackInput['clawback_commission'] : null,
                isset($clawbackInput['clawback_amount']) ? (float) $clawbackInput['clawback_amount'] : null,
                Auth::user(),
            );

            $isTransfer = $refund->type === 'transfer';
            $amountLabel = number_format($amount, 0, ',', '.').' VNĐ';

            // Bên nguồn: phiếu âm + giảm giá trị hợp đồng tương ứng để công nợ không tăng giả.
            TuitionReceipt::create([
                'receipt_number' => TuitionReceipt::generateReceiptNumber(),
                // Số HĐ lấy theo dải của chi nhánh học viên (hết/không có dải → dải mặc định), như phiếu thu thường.
                'invoice_number' => InvoiceConfiguration::consumeNextInvoiceNumber($this->tuitionBranchId($sourceTuition)),
                'student_tuition_id' => $sourceTuition->id,
                'student_id' => $sourceTuition->student_id,
                'amount' => -$amount,
                'tuition_amount' => -$amount,
                'payment_method' => 'transfer',
                'transaction_code' => ($isTransfer ? 'XFER-OUT-' : 'REFUND-').$refund->id,
                'payment_date' => now(),
                'creator_id' => $refund->requester_id ?? Auth::id(),
                'approver_id' => Auth::id(),
                'status' => TuitionReceipt::STATUS_APPROVED,
                'notes' => $isTransfer
                    ? "Trừ chuyển nhượng học phí (-{$amountLabel}) sang cho học viên {$refund->targetStudent?->name} ({$refund->targetStudent?->code}). Lý do: {$refund->reason}"
                    : "Hoàn trả rút tiền học phí (-{$amountLabel}). Lý do: {$refund->reason}",
            ]);

            $oldFinal = (float) $sourceTuition->final_amount;
            $sourceTuition->final_amount = max(0, $oldFinal - $amount);
            $sourceTuition->notes = trim(($sourceTuition->notes ? $sourceTuition->notes."\n" : '')
                .'['.now()->format('d/m/Y H:i').'] '.($isTransfer ? 'Chuyển nhượng' : 'Hoàn phí')." #{$refund->id}: giảm giá trị hợp đồng "
                .number_format($oldFinal, 0, ',', '.').' → '.number_format((float) $sourceTuition->final_amount, 0, ',', '.')
                ." VNĐ (-{$amountLabel}), duyệt bởi ".(Auth::user()?->name ?? 'hệ thống').'.');
            $sourceTuition->recalculateDebt();

            if (! $isTransfer) {
                return ['status' => "Đã duyệt hoàn trả {$amountLabel} cho học viên {$refund->student?->name} và cập nhật lại sổ nợ học phí!"];
            }

            // Bên nhận: phiếu dương cấn trừ công nợ (đã chặn vượt nợ ở trên nên không thất thoát).
            TuitionReceipt::create([
                'receipt_number' => TuitionReceipt::generateReceiptNumber(),
                'invoice_number' => InvoiceConfiguration::consumeNextInvoiceNumber($this->tuitionBranchId($targetTuition)),
                'student_tuition_id' => $targetTuition->id,
                'student_id' => $targetTuition->student_id,
                'amount' => $amount,
                'tuition_amount' => $amount,
                'payment_method' => 'transfer',
                'transaction_code' => 'XFER-IN-'.$refund->id,
                'payment_date' => now(),
                'creator_id' => $refund->requester_id ?? Auth::id(),
                'approver_id' => Auth::id(),
                'status' => TuitionReceipt::STATUS_APPROVED,
                'notes' => "Nhận chuyển nhượng học phí (+{$amountLabel}) từ học viên {$refund->student?->name} ({$refund->student?->code}). Lý do: {$refund->reason}",
            ]);
            $targetTuition->recalculateDebt();

            return ['status' => "Đã duyệt chuyển nhượng {$amountLabel} từ học viên {$refund->student?->name} sang {$refund->targetStudent?->name}, tự động cấn trừ công nợ & xuất biên lai đối soát!"];
        });

        if (isset($result['error'])) {
            return redirect()->back()->withErrors([$result['field'] ?? 'refund' => $result['error']]);
        }

        return redirect()->back()->with('status', $result['status']);
    }

    /**
     * Duyệt khất nợ / bảo lưu (trong transaction của approveRefundRequest):
     * - Khất nợ: dời hạn đóng sang hạn mới, tạm dừng nhắc nợ tới hạn mới (lệnh nhắc nợ tự bỏ qua), bỏ trạng thái quá hạn.
     * - Bảo lưu: học viên sang "Bảo lưu" với thời gian từ/đến, đóng băng số buổi còn lại và công nợ,
     *   tạm dừng nhắc nợ tới hết ngày bảo lưu (không tính quá hạn trong thời gian này).
     *
     * @return array{status?: string, error?: string}
     */
    private function applyExtensionOrDeferral(TuitionRefundRequest $refund): array
    {
        $tuition = StudentTuition::query()->where('student_id', $refund->student_id)->lockForUpdate()->first();
        if (! $tuition) {
            return ['error' => 'Học viên chưa có hồ sơ học phí để khất nợ / bảo lưu.'];
        }

        $approver = Auth::user()?->name ?? 'hệ thống';
        $stamp = '['.now()->format('d/m/Y H:i').'] ';

        if ($refund->type === TuitionRefundRequest::TYPE_EXTENSION) {
            if (! $refund->extended_due_date) {
                return ['error' => 'Hồ sơ khất nợ chưa có hạn đóng mới. Vui lòng từ chối và lập lại hồ sơ kèm hạn mới.'];
            }

            $oldDue = $tuition->due_date?->format('d/m/Y') ?? 'chưa có';
            $tuition->due_date = $refund->extended_due_date;
            $tuition->reminder_paused_until = $refund->extended_due_date;
            $tuition->notes = trim(($tuition->notes ? $tuition->notes."\n" : '').$stamp
                ."Khất nợ #{$refund->id}: hạn đóng {$oldDue} → {$refund->extended_due_date->format('d/m/Y')}, tạm dừng nhắc nợ tới hạn mới. Duyệt bởi {$approver}.");
            $tuition->recalculateDebt();

            $refund->update(['status' => 'approved', 'approver_id' => Auth::id()]);

            return ['status' => "Đã duyệt khất nợ cho học viên {$refund->student?->name}: hạn đóng mới {$refund->extended_due_date->format('d/m/Y')}, tạm dừng nhắc nợ tới ngày này."];
        }

        if (! $refund->defer_from || ! $refund->defer_to) {
            return ['error' => 'Hồ sơ bảo lưu chưa có thời gian từ ngày / đến ngày.'];
        }

        $tuition->recalculateDebt();
        $stats = $tuition->sessionStats();
        $resumeOn = $refund->defer_to->copy()->addDay();

        $tuition->deferred_from = $refund->defer_from;
        $tuition->deferred_until = $refund->defer_to;
        $tuition->frozen_remaining_sessions = $stats['remaining'] ?? null;
        $tuition->frozen_debt_amount = $tuition->debt_amount;
        $tuition->reminder_paused_until = ($tuition->reminder_paused_until && $tuition->reminder_paused_until->gt($resumeOn))
            ? $tuition->reminder_paused_until
            : $resumeOn;
        // Hạn đóng còn nằm trong thời gian bảo lưu -> dời sang ngày học lại để không bị tính quá hạn.
        if ((float) $tuition->debt_amount > 0 && (! $tuition->due_date || $tuition->due_date->lt($resumeOn))) {
            $tuition->due_date = $resumeOn;
        }
        $tuition->notes = trim(($tuition->notes ? $tuition->notes."\n" : '').$stamp
            ."Bảo lưu #{$refund->id}: {$refund->defer_from->format('d/m/Y')} – {$refund->defer_to->format('d/m/Y')}, đóng băng "
            .($stats ? $stats['remaining'].' buổi còn lại' : 'số buổi còn lại').' và công nợ '
            .number_format((float) $tuition->debt_amount, 0, ',', '.')." VNĐ. Duyệt bởi {$approver}.");
        $tuition->recalculateDebt();

        $student = $refund->student;
        if ($student) {
            $student->update([
                'status' => 'deferred',
                'notes' => trim(($student->notes ? $student->notes."\n" : '').$stamp
                    ."Bảo lưu {$refund->defer_from->format('d/m/Y')} – {$refund->defer_to->format('d/m/Y')} (hồ sơ #{$refund->id}). Lý do: {$refund->reason}"),
            ]);
        }

        $refund->update(['status' => 'approved', 'approver_id' => Auth::id()]);

        return ['status' => "Đã duyệt bảo lưu cho học viên {$student?->name} từ {$refund->defer_from->format('d/m/Y')} đến {$refund->defer_to->format('d/m/Y')}; đã đóng băng số buổi còn lại và công nợ."];
    }

    public function rejectRefundRequest($id)
    {
        abort_unless(TuitionBranchScope::allowsStudent(TuitionRefundRequest::with('student.currentClass')->findOrFail($id)->student, $this->branchScope()), 403, self::OUT_OF_SCOPE);
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

    /**
     * Danh sách thu phí quá hạn (mockup "Thu phí quá hạn"):
     * - Quá hạn nghiêm trọng (≥ N ngày, N = "Mốc quá hạn bắt buộc liên hệ") và Mới quá hạn (1 → N-1 ngày), có số ngày quá hạn.
     * - Sắp đến hạn (từ hôm nay tới 14 ngày tới).
     * - Khoản đang khất nợ / bảo lưu (tạm dừng nhắc nợ) tách riêng, không tính quá hạn.
     * - Mỗi dòng có trạng thái đôn đốc gần nhất: "Đã liên hệ — chờ thu", "Đã báo cáo Admin".
     */
    public function overdue(Request $request)
    {
        $seriousDays = max(1, (int) SystemSetting::get('debt_reminder.must_contact_days', config('tuition.overdue_serious_days', 7)));
        $upcomingDays = (int) config('tuition.upcoming_days', 14);
        $today = now()->startOfDay();

        $scope = $this->branchScope();
        $query = TuitionBranchScope::tuitions(StudentTuition::query(), $scope)
            ->with(['student', 'classModel.course', 'branch', 'contactLogs.user'])
            ->where('debt_amount', '>', 0)
            ->whereNotNull('due_date')
            ->where(function ($q) use ($today, $upcomingDays) {
                $q->whereDate('due_date', '<=', $today->copy()->addDays($upcomingDays)->toDateString())
                    ->orWhereDate('reminder_paused_until', '>', $today->toDateString());
            });

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

        $rows = $query->orderBy('due_date')->get()->map(function (StudentTuition $tuition) {
            $tuition->setAttribute('days_overdue', $tuition->daysOverdue());
            $tuition->setAttribute('session_stats', $tuition->sessionStats());
            $tuition->setAttribute('last_contact', $tuition->contactLogs->firstWhere('action', TuitionContactLog::ACTION_CONTACTED));
            $tuition->setAttribute('last_report', $tuition->contactLogs->firstWhere('action', TuitionContactLog::ACTION_REPORTED));

            return $tuition;
        });

        $paused = $rows->filter(fn (StudentTuition $t) => $t->remindersPausedOn())->values();
        $active = $rows->reject(fn (StudentTuition $t) => $t->remindersPausedOn());

        $seriousOverdue = $active->filter(fn (StudentTuition $t) => $t->days_overdue >= $seriousDays)->sortByDesc('days_overdue')->values();
        $newOverdue = $active->filter(fn (StudentTuition $t) => $t->days_overdue >= 1 && $t->days_overdue < $seriousDays)->sortByDesc('days_overdue')->values();
        $upcoming = $active->filter(fn (StudentTuition $t) => $t->days_overdue <= 0)->sortByDesc('days_overdue')->values();

        $type = $request->input('type', 'all');
        if ($type === 'overdue') {
            $upcoming = collect();
        } elseif ($type === 'upcoming') {
            $seriousOverdue = collect();
            $newOverdue = collect();
        }

        $overdueTuitions = $seriousOverdue->concat($newOverdue);

        // Thống kê công nợ quá hạn theo chi nhánh / lớp (toàn bộ khoản đang nợ, không theo bộ lọc tìm kiếm).
        $allDebts = TuitionBranchScope::tuitions(StudentTuition::query(), $scope)->with(['branch', 'classModel'])->where('debt_amount', '>', 0)->get();
        $isOverdue = fn (StudentTuition $t) => $t->due_date && $t->due_date->lt($today) && ! $t->remindersPausedOn();
        $statsByBranch = $allDebts->groupBy('branch_id')->map(fn ($items) => [
            'branch_name' => $items->first()->branch?->name ?? 'Chưa gán chi nhánh',
            'total_debt' => $items->sum('debt_amount'),
            'count' => $items->count(),
            'overdue_count' => $items->filter($isOverdue)->count(),
        ])->values();
        $statsByClass = $allDebts->groupBy('class_id')->map(fn ($items) => [
            'class_name' => $items->first()->classModel?->name ?? 'Chưa xếp lớp',
            'class_code' => $items->first()->classModel?->code ?? '—',
            'total_debt' => $items->sum('debt_amount'),
            'count' => $items->count(),
            'overdue_count' => $items->filter($isOverdue)->count(),
        ])->values();

        $branches = TuitionBranchScope::branches($scope)->where('is_active', true)->get();
        $classes = ClassModel::when($scope !== null, fn ($q) => $q->whereIn('branch_id', $scope))->orderBy('name')->get();

        return view('tuition.overdue', compact(
            'seriousOverdue',
            'newOverdue',
            'upcoming',
            'paused',
            'overdueTuitions',
            'statsByBranch',
            'statsByClass',
            'branches',
            'classes',
            'type',
            'seriousDays',
            'upcomingDays'
        ));
    }

    /** "Đã liên hệ": ghi nhật ký gọi điện / nhắn tin phụ huynh kèm ghi chú và thời gian. */
    public function markContacted(Request $request, $id)
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:1000',
            'contacted_at' => 'nullable|date|before_or_equal:now',
        ], [
            'contacted_at.before_or_equal' => 'Thời gian liên hệ không được ở tương lai.',
        ]);

        $tuition = StudentTuition::with('student.currentClass')->findOrFail($id);
        abort_unless(TuitionBranchScope::allowsTuition($tuition, $this->branchScope()), 403, self::OUT_OF_SCOPE);

        TuitionContactLog::create([
            'student_tuition_id' => $tuition->id,
            'user_id' => $request->user()->id,
            'action' => TuitionContactLog::ACTION_CONTACTED,
            'note' => $validated['note'] ?? null,
            'contacted_at' => $validated['contacted_at'] ?? now(),
        ]);

        return redirect()->back()->with('status', "Đã ghi nhận liên hệ với phụ huynh học viên {$tuition->student?->name} — chờ thu.");
    }

    /** "Báo cáo Admin": gửi thông báo cá nhân tới toàn bộ Admin và ghi nhật ký. */
    public function reportOverdueToAdmin(Request $request, $id)
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:1000',
        ]);

        $tuition = StudentTuition::with(['student.currentClass', 'classModel', 'branch'])->findOrFail($id);
        abort_unless(TuitionBranchScope::allowsTuition($tuition, $this->branchScope()), 403, self::OUT_OF_SCOPE);
        $days = $tuition->daysOverdue();
        $reporter = $request->user();

        $admins = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->pluck('id');

        $message = "{$reporter->name} báo cáo học viên {$tuition->student?->name} ({$tuition->student?->code}) lớp "
            .($tuition->classModel?->name ?? 'chưa xếp lớp').' quá hạn '.max(0, (int) $days).' ngày, còn nợ '
            .number_format((float) $tuition->debt_amount, 0, ',', '.').' VNĐ'
            .(! empty($validated['note']) ? '. Ghi chú: '.$validated['note'] : '.');

        foreach ($admins as $adminId) {
            AdminNotification::create([
                'user_id' => $adminId,
                'type' => 'overdue_report',
                'title' => 'Báo cáo học phí quá hạn cần xử lý',
                'message' => $message,
                'data' => [
                    'student_tuition_id' => $tuition->id,
                    'student_id' => $tuition->student_id,
                    'days_overdue' => $days,
                    'link' => route('tuition.overdue', ['search' => $tuition->student?->code]),
                ],
                'is_read' => false,
            ]);
        }

        TuitionContactLog::create([
            'student_tuition_id' => $tuition->id,
            'user_id' => $reporter->id,
            'action' => TuitionContactLog::ACTION_REPORTED,
            'note' => $validated['note'] ?? null,
            'contacted_at' => now(),
        ]);

        return redirect()->back()->with('status', "Đã báo cáo Admin ({$admins->count()} người nhận) về khoản quá hạn của học viên {$tuition->student?->name}.");
    }

    public function sendUpcomingReminder($id)
    {
        $tuition = StudentTuition::with(['student.currentClass', 'classModel'])->findOrFail($id);
        abort_unless(TuitionBranchScope::allowsTuition($tuition, $this->branchScope()), 403, self::OUT_OF_SCOPE);

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
        $tuition = StudentTuition::with(['student.currentClass', 'classModel'])->findOrFail($id);
        abort_unless(TuitionBranchScope::allowsTuition($tuition, $this->branchScope()), 403, self::OUT_OF_SCOPE);

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

    public function config(Request $request)
    {
        $ranges = InvoiceConfiguration::with('branch')
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('branch_id')
            ->orderBy('start_number')
            ->get();

        // Số lớn nhất đã cấp trong từng dải (đọc từ phiếu thật) để hiển thị & chặn lùi số.
        $maxIssued = $ranges->mapWithKeys(fn (InvoiceConfiguration $range) => [$range->id => $range->maxIssuedNumber()]);

        $recentInvoices = $ranges->mapWithKeys(fn (InvoiceConfiguration $range) => [
            $range->id => InvoiceConfiguration::issuedInvoicesQuery((string) $range->series_code, (int) ($range->start_number ?? 1), $range->end_number)
                ->orderByDesc('invoice_number')
                ->limit(5)
                ->get(['invoice_number', 'status', 'updated_at']),
        ]);

        $branches = Branch::orderBy('name')->get();
        $editing = $request->filled('edit') ? $ranges->firstWhere('id', (int) $request->input('edit')) : null;

        return view('tuition.config', compact('ranges', 'maxIssued', 'recentInvoices', 'branches', 'editing'));
    }

    /**
     * Cập nhật một dải số (config_id) — hoặc dải mặc định dùng chung khi không truyền config_id.
     * current_number (số kế tiếp) không được lùi về số đã cấp và phải nằm trong dải.
     */
    public function updateConfig(Request $request)
    {
        $validated = $request->validate([
            'config_id' => 'nullable|integer|exists:invoice_configurations,id',
            'template_code' => 'required|string|max:50',
            'series_code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]+$/'],
            'start_number' => 'nullable|integer|min:1',
            'end_number' => 'nullable|integer|min:1',
            'current_number' => 'required|integer|min:1',
            'provider' => 'nullable|string|max:50',
        ], [
            'series_code.regex' => 'Ký hiệu hóa đơn chỉ gồm chữ và số, không dấu, không khoảng trắng.',
        ]);

        $config = ! empty($validated['config_id'])
            ? InvoiceConfiguration::findOrFail($validated['config_id'])
            : (InvoiceConfiguration::whereNull('branch_id')->orderBy('id')->first() ?? new InvoiceConfiguration(['branch_id' => null]));

        $series = strtoupper($validated['series_code']);
        $start = (int) ($validated['start_number'] ?? $config->start_number ?? 1);
        $end = array_key_exists('end_number', $validated) && $validated['end_number'] !== null
            ? (int) $validated['end_number']
            : ($request->has('end_number') ? null : $config->end_number);

        if ($error = $this->invoiceRangeError($series, $start, $end, (int) $validated['current_number'], $config->id)) {
            return redirect()->back()->withErrors($error)->withInput();
        }

        $config->fill([
            'template_code' => $validated['template_code'],
            'series_code' => $series,
            'start_number' => $start,
            'end_number' => $end,
            'current_number' => (int) $validated['current_number'],
            'provider' => $validated['provider'] ?? $config->provider ?? 'vnpt',
        ]);
        $config->save();

        return redirect()->route('tuition.config')->with('status', 'Đã lưu cấu hình dải số hóa đơn điện tử thành công!');
    }

    /**
     * Thêm dải số mới cho một chi nhánh (hoặc dải mặc định). Dải không được chồng lấn dải khác cùng ký hiệu.
     */
    public function storeInvoiceRange(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'template_code' => 'required|string|max:50',
            'series_code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]+$/'],
            'start_number' => 'required|integer|min:1',
            'end_number' => 'required|integer|gte:start_number',
            'provider' => 'nullable|string|max:50',
        ], [
            'branch_id.exists' => 'Chi nhánh không tồn tại.',
            'end_number.gte' => 'Số kết thúc phải lớn hơn hoặc bằng số bắt đầu.',
            'series_code.regex' => 'Ký hiệu hóa đơn chỉ gồm chữ và số, không dấu, không khoảng trắng.',
        ]);

        $series = strtoupper($validated['series_code']);
        $start = (int) $validated['start_number'];
        $end = (int) $validated['end_number'];
        // Số kế tiếp = số bắt đầu, nhưng không bao giờ thấp hơn số đã cấp của cùng ký hiệu.
        $current = max($start, (int) (InvoiceConfiguration::maxIssuedNumberFor($series, $start, $end) ?? 0) + 1);

        if ($error = $this->invoiceRangeError($series, $start, $end, $current, null)) {
            return redirect()->back()->withErrors($error)->withInput();
        }

        $range = InvoiceConfiguration::create([
            'branch_id' => $validated['branch_id'] ?? null,
            'template_code' => $validated['template_code'],
            'series_code' => $series,
            'start_number' => $start,
            'end_number' => $end,
            'current_number' => $current,
            'provider' => $validated['provider'] ?? 'vnpt',
            'auto_issue' => true,
            'is_active' => true,
        ]);

        $branchName = $range->branch?->name ?? 'Dải mặc định (dùng chung)';

        return redirect()->route('tuition.config')
            ->with('status', "Đã thêm dải số {$series} ".str_pad((string) $start, InvoiceConfiguration::NUMBER_PAD, '0', STR_PAD_LEFT)
                .' – '.str_pad((string) $end, InvoiceConfiguration::NUMBER_PAD, '0', STR_PAD_LEFT)." cho {$branchName}.");
    }

    /** Ngừng dùng / dùng lại một dải số. Số đã cấp của dải vẫn giữ nguyên, không cấp lại. */
    public function toggleInvoiceRange($id)
    {
        $range = InvoiceConfiguration::findOrFail($id);
        $range->update(['is_active' => ! $range->is_active]);

        return redirect()->route('tuition.config')->with('status', $range->is_active
            ? "Đã kích hoạt lại dải số {$range->series_code}."
            : "Đã ngừng dùng dải số {$range->series_code}. Hóa đơn mới sẽ lấy từ dải khác của chi nhánh hoặc dải mặc định.");
    }

    /**
     * @return array<string, string>|null
     */
    private function invoiceRangeError(string $series, int $start, ?int $end, int $current, ?int $ignoreId): ?array
    {
        if ($end !== null && $end < $start) {
            return ['end_number' => 'Số kết thúc phải lớn hơn hoặc bằng số bắt đầu.'];
        }

        if ($overlap = InvoiceConfiguration::overlapping($series, $start, $end, $ignoreId)) {
            return ['start_number' => 'Dải số chồng lấn với dải '.$overlap->series_code.' '
                .($overlap->start_number ?? 1).' – '.($overlap->end_number ?? '∞')
                .' ('.($overlap->branch?->name ?? 'dải mặc định').'). Mỗi dải phải duy nhất trên toàn hệ thống.'];
        }

        if ($current < $start) {
            return ['current_number' => 'Số hiện tại (số kế tiếp) không được nhỏ hơn số bắt đầu của dải.'];
        }

        if ($end !== null && $current > $end + 1) {
            return ['current_number' => 'Số hiện tại vượt quá số kết thúc của dải.'];
        }

        $maxIssued = InvoiceConfiguration::maxIssuedNumberFor($series, $start, $end);
        if ($maxIssued !== null && $current <= $maxIssued) {
            return ['current_number' => 'Không được lùi số: số '.InvoiceConfiguration::format($series, $maxIssued)
                .' đã được cấp. Số kế tiếp phải từ '.($maxIssued + 1).' trở lên.'];
        }

        return null;
    }

    /**
     * Minh chứng dạng tham chiếu (ảnh xem trước base64 hoặc link) chỉ nhận ảnh
     * base64 hoặc URL http(s)/uploads, không nhận javascript:, data:text/html...
     */
    private function isSafeProofReference(string $value): bool
    {
        return (bool) preg_match('#^(https?://|/uploads/|data:image/(png|jpe?g|webp);base64,)#i', $value);
    }
}
