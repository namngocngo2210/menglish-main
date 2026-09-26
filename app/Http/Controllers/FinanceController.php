<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\OperatingExpense;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\TuitionReceipt;
use App\Models\User;
use App\Support\TuitionBranchScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    /** Ngưỡng tỷ suất gộp (%) đánh giá chi nhánh — dùng chung cho màn hình và file xuất. */
    private const MARGIN_GOOD = 69;

    private const MARGIN_TARGET = 60;

    /**
     * Màn hình: Sổ khoản chi vận hành (Epic 13)
     */
    public function expenses(Request $request)
    {
        // 1. Filter parameters
        $month = $request->input('month', Carbon::now()->format('Y-m'));
        $branchId = $this->resolveBranchFilter($request, $request->input('branch_id', 'all'));
        $search = trim($request->input('search', ''));

        try {
            $parsedDate = Carbon::createFromFormat('Y-m', $month);
        } catch (\Throwable $e) {
            $month = Carbon::now()->format('Y-m');
            $parsedDate = Carbon::createFromFormat('Y-m', $month);
        }

        $startDate = $parsedDate->copy()->startOfMonth()->toDateString();
        $endDate = $parsedDate->copy()->endOfMonth()->toDateString();
        $prevMonth = $parsedDate->copy()->subMonth()->format('Y-m');

        // Danh sách chi nhánh (Quản lý cơ sở chỉ thấy chi nhánh của mình)
        $branches = $this->visibleBranches($request);

        // 2. Query manual expenses
        $query = OperatingExpense::with(['branch', 'creator'])
            ->whereBetween('expense_date', [$startDate, $endDate]);

        if ($branchId !== 'all' && is_numeric($branchId)) {
            $query->where('branch_id', $branchId);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('creator', fn($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }

        $manualExpenses = (clone $query)->orderBy('expense_date', 'desc')->get();
        $totalManualExpense = $manualExpenses->sum('amount');
        $manualExpensesCount = $manualExpenses->count();

        // 3. Tự động lấy Chi lương (Epic 7) theo tháng đã chọn
        $periodMonth = (int)$parsedDate->format('m');
        $periodYear = (int)$parsedDate->format('Y');

        $period = PayrollPeriod::where('month', $periodMonth)
            ->where('year', $periodYear)
            ->whereIn('status', ['approved', 'paid'])
            ->first();

        $autoSalaryRow = null;
        $autoSalaryAmount = 0;
        $autoSalaryStaffCount = 0;

        if ($period) {
            if ($branchId !== 'all' && is_numeric($branchId)) {
                $branchRecords = PayrollRecord::where('payroll_period_id', $period->id)
                    ->whereHas('user', fn($u) => $u->where('branch_id', $branchId));
                $autoSalaryAmount = (float)$branchRecords->sum('net_salary');
                $autoSalaryStaffCount = $branchRecords->count();
            } else {
                $autoSalaryAmount = (float)$period->total_amount ?: (float)$period->records()->sum('net_salary');
                $autoSalaryStaffCount = $period->total_staff ?: $period->records()->count();
            }

            // Chỉ hiển thị dòng lương khi > 0 (theo spec: nếu không có bảng lương hợp lệ thì ẩn hoàn toàn)
            if ($autoSalaryAmount > 0) {
                $targetBranchName = 'Toàn hệ thống (' . $branches->count() . ' CS)';
                if ($branchId !== 'all' && is_numeric($branchId)) {
                    $b = $branches->firstWhere('id', $branchId);
                    $targetBranchName = $b ? $b->name : 'Chi nhánh được chọn';
                }

                $autoSalaryRow = (object)[
                    'is_auto' => true,
                    'expense_date' => $period->end_date ? Carbon::parse($period->end_date)->format('d/m/Y') : $parsedDate->copy()->endOfMonth()->format('d/m/Y'),
                    'date_sub' => 'Cuối kỳ lương',
                    'title' => "Chi lương tháng {$parsedDate->format('m/Y')}",
                    'description' => 'Tổng hợp từ bảng lương (trạng thái: Đã chốt, Đã trả) cho toàn bộ GV, TA và Nhân viên',
                    'amount' => $autoSalaryAmount,
                    'staff_count' => $autoSalaryStaffCount,
                    'payment_method' => 'chuyen_khoan',
                    'branch_name' => $targetBranchName,
                    'notes' => "Bảng lương mã {$period->code}. Đã hạch toán chi trả.",
                ];
            }
        }

        // 4. Tổng chi kỳ này & So sánh tháng trước
        $grandTotalExpense = $totalManualExpense + $autoSalaryAmount;

        // Tính tổng chi tháng trước để so sánh
        $prevParsed = Carbon::createFromFormat('Y-m', $prevMonth);
        $prevStart = $prevParsed->copy()->startOfMonth()->toDateString();
        $prevEnd = $prevParsed->copy()->endOfMonth()->toDateString();

        $prevManual = OperatingExpense::whereBetween('expense_date', [$prevStart, $prevEnd]);
        if ($branchId !== 'all' && is_numeric($branchId)) {
            $prevManual->where('branch_id', $branchId);
        }
        $prevManualSum = (float)$prevManual->sum('amount');

        $prevPeriod = PayrollPeriod::where('month', (int)$prevParsed->format('m'))
            ->where('year', (int)$prevParsed->format('Y'))
            ->whereIn('status', ['approved', 'paid'])
            ->first();
        $prevSalarySum = 0;
        if ($prevPeriod) {
            if ($branchId !== 'all' && is_numeric($branchId)) {
                $prevSalarySum = (float)PayrollRecord::where('payroll_period_id', $prevPeriod->id)
                    ->whereHas('user', fn($u) => $u->where('branch_id', $branchId))
                    ->sum('net_salary');
            } else {
                $prevSalarySum = (float)$prevPeriod->total_amount ?: (float)$prevPeriod->records()->sum('net_salary');
            }
        }
        $prevGrandTotal = $prevManualSum + $prevSalarySum;

        $percentDiff = 0;
        $isDecreased = false;
        if ($prevGrandTotal > 0) {
            $percentDiff = round((($grandTotalExpense - $prevGrandTotal) / $prevGrandTotal) * 100, 1);
            $isDecreased = $percentDiff < 0;
        }

        // 5. Cơ cấu hình thức chi (Chuyển khoản vs Tiền mặt)
        $cashManual = (float)$manualExpenses->where('payment_method', 'tien_mat')->sum('amount');
        $transferManual = (float)$manualExpenses->where('payment_method', 'chuyen_khoan')->sum('amount');
        
        // Chi lương mặc định là chuyển khoản ngân hàng
        $totalTransfer = $transferManual + $autoSalaryAmount;
        $totalCash = $cashManual;

        $transferPercent = $grandTotalExpense > 0 ? round(($totalTransfer / $grandTotalExpense) * 100) : 0;
        $cashPercent = $grandTotalExpense > 0 ? (100 - $transferPercent) : 0;

        // Tổng số mục chi hiển thị
        $totalItemsCount = $manualExpensesCount + ($autoSalaryRow ? 1 : 0);

        // Danh sách các tháng có thể chọn (6 tháng gần đây)
        $monthOptions = [];
        for ($i = 0; $i < 6; $i++) {
            $dt = Carbon::now()->subMonths($i);
            $val = $dt->format('Y-m');
            $label = "Tháng {$dt->format('m/Y')}" . ($i === 0 ? ' (Hiện tại)' : '');
            $monthOptions[$val] = $label;
        }

        $branchScoped = $this->scopedBranchIds($request->user()) !== null;

        return view('finance.expenses.index', compact(
            'branchScoped',
            'month',
            'branchId',
            'search',
            'branches',
            'manualExpenses',
            'autoSalaryRow',
            'grandTotalExpense',
            'autoSalaryAmount',
            'autoSalaryStaffCount',
            'totalManualExpense',
            'manualExpensesCount',
            'totalItemsCount',
            'prevMonth',
            'percentDiff',
            'isDecreased',
            'totalTransfer',
            'totalCash',
            'transferPercent',
            'cashPercent',
            'monthOptions'
        ));
    }

    /**
     * Thêm khoản chi mới (Modal - Lưu ngay không cần duyệt)
     */
    public function storeExpense(Request $request)
    {
        $validated = $request->validate([
            'expense_date' => 'required|date',
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:1000',
            'payment_method' => 'required|in:chuyen_khoan,tien_mat',
            'branch_id' => 'required|exists:branches,id',
            'notes' => 'nullable|string|max:1000',
            'category' => 'nullable|string|in:mat_bang_tien_ich,giao_trinh_van_hanh,khac',
        ], [
            'expense_date.required' => 'Vui lòng chọn ngày chi.',
            'title.required' => 'Vui lòng nhập nội dung khoản chi.',
            'amount.required' => 'Vui lòng nhập số tiền chi.',
            'amount.min' => 'Số tiền chi tối thiểu là 1.000 đ.',
            'payment_method.required' => 'Vui lòng chọn hình thức chi.',
            'branch_id.required' => 'Vui lòng chọn chi nhánh áp dụng.',
        ]);

        $this->authorizeExpenseBranch($request, $validated['branch_id']);

        // Tự động phân loại danh mục nếu không chọn
        $category = $validated['category'] ?? null;
        if (!$category) {
            $lowerTitle = mb_strtolower($validated['title']);
            if (preg_match('/mặt bằng|thuê|tiền điện|tiền nước|internet|wifi|cáp quang|vệ sinh/u', $lowerTitle)) {
                $category = 'mat_bang_tien_ich';
            } elseif (preg_match('/in ấn|giáo trình|sách|văn phòng phẩm|bút|mực|điều hòa|nước uống|sửa chữa/u', $lowerTitle)) {
                $category = 'giao_trinh_van_hanh';
            } else {
                $category = 'khac';
            }
        }

        OperatingExpense::create([
            'expense_date' => $validated['expense_date'],
            'title' => $validated['title'],
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'branch_id' => $validated['branch_id'],
            'category' => $category,
            'notes' => $validated['notes'] ?? null,
            'creator_id' => Auth::id(),
        ]);

        return redirect()->back()->with('status', 'Khoản chi đã được lưu thành công vào sổ chi vận hành!');
    }

    /**
     * Cập nhật khoản chi tự nhập
     */
    public function updateExpense(Request $request, $id)
    {
        $expense = OperatingExpense::findOrFail($id);

        $validated = $request->validate([
            'expense_date' => 'required|date',
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:1000',
            'payment_method' => 'required|in:chuyen_khoan,tien_mat',
            'branch_id' => 'required|exists:branches,id',
            'notes' => 'nullable|string|max:1000',
            'category' => 'nullable|string|in:mat_bang_tien_ich,giao_trinh_van_hanh,khac',
        ]);

        $this->authorizeExpenseBranch($request, $expense->branch_id);
        $this->authorizeExpenseBranch($request, $validated['branch_id']);

        $expense->update($validated);

        return redirect()->back()->with('status', 'Đã cập nhật thông tin khoản chi thành công!');
    }

    /**
     * Xóa khoản chi tự nhập
     */
    public function destroyExpense(Request $request, $id)
    {
        $expense = OperatingExpense::findOrFail($id);
        $this->authorizeExpenseBranch($request, $expense->branch_id);
        $title = $expense->title;
        $expense->delete();

        return redirect()->back()->with('status', "Đã xóa khoản chi: \"{$title}\" khỏi sổ chi vận hành.");
    }

    /**
     * Xuất danh sách khoản chi ra Excel (CSV định dạng UTF-8 BOM)
     */
    public function exportExpenses(Request $request)
    {
        $month = $request->input('month', Carbon::now()->format('Y-m'));
        $branchId = $this->resolveBranchFilter($request, $request->input('branch_id', 'all'));

        try {
            $parsedDate = Carbon::createFromFormat('Y-m', $month);
        } catch (\Throwable $e) {
            $month = Carbon::now()->format('Y-m');
            $parsedDate = Carbon::createFromFormat('Y-m', $month);
        }
        $startDate = $parsedDate->copy()->startOfMonth()->toDateString();
        $endDate = $parsedDate->copy()->endOfMonth()->toDateString();

        $query = OperatingExpense::with(['branch', 'creator'])
            ->whereBetween('expense_date', [$startDate, $endDate]);

        if ($branchId !== 'all' && is_numeric($branchId)) {
            $query->where('branch_id', $branchId);
        }

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")
                ->orWhereHas('creator', fn ($u) => $u->where('name', 'like', "%{$search}%")));
        }

        $expenses = $query->orderBy('expense_date', 'asc')->get();

        $filename = "So_Khoan_Chi_Van_Hanh_{$month}.csv";

        return new StreamedResponse(function () use ($expenses, $parsedDate, $branchId) {
            $handle = fopen('php://output', 'w');
            // Xuất UTF-8 BOM để Excel đọc tiếng Việt không bị lỗi font
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, ['SỔ KHOẢN CHI VẬN HÀNH - MENGLISH ADMIN']);
            fputcsv($handle, ["Kỳ tháng: {$parsedDate->format('m/Y')}"]);
            fputcsv($handle, []);
            fputcsv($handle, ['STT', 'Ngày chi', 'Nội dung khoản chi', 'Số tiền (VNĐ)', 'Hình thức', 'Chi nhánh', 'Người lập', 'Ghi chú & Chứng từ']);

            $stt = 1;
            foreach ($expenses as $exp) {
                fputcsv($handle, [
                    $stt++,
                    Carbon::parse($exp->expense_date)->format('d/m/Y'),
                    $exp->title,
                    number_format($exp->amount, 0, ',', '.'),
                    $exp->payment_method === 'chuyen_khoan' ? 'Chuyển khoản' : 'Tiền mặt',
                    $exp->branch?->name ?? 'Toàn hệ thống',
                    $exp->creator?->name ?? 'Admin',
                    $exp->notes ?? '',
                ]);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Màn hình: Báo cáo Doanh thu tạm tính (Epic 13)
     */
    public function provisionalRevenue(Request $request)
    {
        // 1. Filter parameters
        $month = $request->input('month', Carbon::now()->format('Y-m'));
        $branchId = $this->resolveBranchFilter($request, $request->input('branch_id', 'all'));

        try {
            $parsedDate = Carbon::createFromFormat('Y-m', $month);
        } catch (\Throwable $e) {
            $month = Carbon::now()->format('Y-m');
            $parsedDate = Carbon::createFromFormat('Y-m', $month);
        }

        $startDate = $parsedDate->copy()->startOfMonth()->toDateString();
        $endDate = $parsedDate->copy()->endOfMonth()->toDateString();
        $prevMonth = $parsedDate->copy()->subMonth()->format('Y-m');

        $branches = $this->visibleBranches($request);

        // 2. Query Doanh thu (Tổng thu thực tế)
        // Tất cả phiếu thu còn hiệu lực (không bị hủy hóa đơn / rejected)
        $receipts = $this->revenueReceiptQuery($startDate, $endDate, $branchId)
            ->with(['student.branch', 'tuition.branch'])
            ->get();
        $totalRevenue = (float)$receipts->sum('amount');
        $validReceiptsCount = $receipts->count();

        // Bóc tách nguồn thu: Học phí vs Phụ thu
        $tuitionRevenue = (float)$receipts->sum(fn (TuitionReceipt $r) => $r->tuitionPortion());
        $surchargeRevenue = (float)$receipts->sum('surcharge_amount');

        $tuitionPercent = $totalRevenue > 0 ? round(($tuitionRevenue / $totalRevenue) * 100, 1) : 0;
        $surchargePercent = $totalRevenue > 0 ? round(($surchargeRevenue / $totalRevenue) * 100, 1) : 0;

        // Hình thức thanh toán thu (Chuyển khoản vs Tiền mặt)
        $revenueTransfer = (float)$receipts->filter(fn($r) => in_array($r->payment_method, ['transfer', 'chuyen_khoan']))->sum('amount');
        $revenueCash = (float)$receipts->filter(fn($r) => in_array($r->payment_method, ['cash', 'tien_mat']))->sum('amount');
        $revenueTransferPercent = $totalRevenue > 0 ? round(($revenueTransfer / $totalRevenue) * 100) : 0;
        $revenueCashPercent = $totalRevenue > 0 ? (100 - $revenueTransferPercent) : 0;

        // So sánh doanh thu với tháng trước
        $prevParsed = Carbon::createFromFormat('Y-m', $prevMonth);
        $prevStart = $prevParsed->copy()->startOfMonth()->toDateString();
        $prevEnd = $prevParsed->copy()->endOfMonth()->toDateString();

        $prevRevenue = (float)$this->revenueReceiptQuery($prevStart, $prevEnd, $branchId)->sum('amount');
        $revenueDiffPercent = 0;
        $revenueDiffIsUp = true;
        if ($prevRevenue > 0) {
            $diff = (($totalRevenue - $prevRevenue) / $prevRevenue) * 100;
            $revenueDiffPercent = abs(round($diff, 1));
            $revenueDiffIsUp = $diff >= 0;
        }

        // 3. Query Chi phí vận hành
        // Nguồn 1: Khoản chi tự nhập
        $expenseQuery = OperatingExpense::whereBetween('expense_date', [$startDate, $endDate]);
        if ($branchId !== 'all' && is_numeric($branchId)) {
            $expenseQuery->where('branch_id', $branchId);
        }
        $manualExpenses = $expenseQuery->get();
        $manualExpenseTotal = (float)$manualExpenses->sum('amount');

        // Nguồn 2: Chi lương tự động từ Epic 7
        $period = PayrollPeriod::where('month', (int)$parsedDate->format('m'))
            ->where('year', (int)$parsedDate->format('Y'))
            ->whereIn('status', ['approved', 'paid'])
            ->first();

        $salaryTotal = 0;
        if ($period) {
            if ($branchId !== 'all' && is_numeric($branchId)) {
                $salaryTotal = (float)PayrollRecord::where('payroll_period_id', $period->id)
                    ->whereHas('user', fn($u) => $u->where('branch_id', $branchId))
                    ->sum('net_salary');
            } else {
                $salaryTotal = (float)$period->total_amount ?: (float)$period->records()->sum('net_salary');
            }
        }

        $totalExpense = $manualExpenseTotal + $salaryTotal;
        $expensePercentageOfRevenue = $totalRevenue > 0 ? round(($totalExpense / $totalRevenue) * 100, 1) : 0;
        $totalExpenseItemsCount = $manualExpenses->count() + ($salaryTotal > 0 ? 1 : 0);

        // Bóc tách cơ cấu chi
        $rentUtilitiesExpense = (float)$manualExpenses->where('category', 'mat_bang_tien_ich')->sum('amount');
        $curriculumOperationsExpense = (float)$manualExpenses->where('category', 'giao_trinh_van_hanh')->sum('amount');
        $otherExpense = (float)$manualExpenses->where('category', 'khac')->sum('amount');

        $salaryPercentOfExpense = $totalExpense > 0 ? round(($salaryTotal / $totalExpense) * 100, 1) : 0;
        $rentPercentOfExpense = $totalExpense > 0 ? round(($rentUtilitiesExpense / $totalExpense) * 100, 1) : 0;
        $curriculumPercentOfExpense = $totalExpense > 0 ? round(($curriculumOperationsExpense / $totalExpense) * 100, 1) : 0;

        // 4. Doanh thu tạm tính (= Tổng thu - Chi vận hành)
        $provisionalRevenue = $totalRevenue - $totalExpense;
        $isProfitPositive = $provisionalRevenue >= 0;
        $grossProfitMargin = $totalRevenue > 0 ? round(($provisionalRevenue / $totalRevenue) * 100, 1) : 0;

        // 5. Ma trận so sánh hiệu quả giữa các Chi nhánh (Branch Matrix)
        $branchMatrix = [];
        $totalMatrixRevenue = 0;
        $totalMatrixExpense = 0;

        foreach ($branches as $b) {
            // Doanh thu chi nhánh
            $bRevenue = (float)$this->revenueReceiptQuery($startDate, $endDate, $b->id)->sum('amount');

            // Chi phí tự nhập chi nhánh
            $bManualExpense = (float)OperatingExpense::whereBetween('expense_date', [$startDate, $endDate])
                ->where('branch_id', $b->id)
                ->sum('amount');

            // Chi lương nhân sự chi nhánh
            $bSalary = 0;
            if ($period) {
                $bSalary = (float)PayrollRecord::where('payroll_period_id', $period->id)
                    ->whereHas('user', fn($u) => $u->where('branch_id', $b->id))
                    ->sum('net_salary');
            }

            $bExpense = $bManualExpense + $bSalary;
            $bProfit = $bRevenue - $bExpense;
            $bMargin = $bRevenue > 0 ? round(($bProfit / $bRevenue) * 100, 1) : 0;

            // Đánh giá tình trạng chi nhánh
            $statusBadge = $this->branchMarginStatus($bMargin);

            $branchMatrix[] = [
                'branch' => $b,
                'revenue' => $bRevenue,
                'expense' => $bExpense,
                'profit' => $bProfit,
                'margin' => $bMargin,
                'status' => $statusBadge,
            ];

            $totalMatrixRevenue += $bRevenue;
            $totalMatrixExpense += $bExpense;
        }

        $totalMatrixProfit = $totalMatrixRevenue - $totalMatrixExpense;
        $totalMatrixMargin = $totalMatrixRevenue > 0 ? round(($totalMatrixProfit / $totalMatrixRevenue) * 100, 1) : 0;
        $totalMatrixStatus = $this->branchMarginStatus($totalMatrixMargin);

        // Month selector options
        $monthOptions = [];
        for ($i = 0; $i < 6; $i++) {
            $dt = Carbon::now()->subMonths($i);
            $val = $dt->format('Y-m');
            $label = "Tháng {$dt->format('m/Y')}" . ($i === 0 ? ' (Hiện tại)' : '');
            $monthOptions[$val] = $label;
        }

        $branchScoped = $this->scopedBranchIds($request->user()) !== null;

        return view('finance.reports.provisional-revenue', compact(
            'branchScoped',
            'month',
            'branchId',
            'branches',
            'totalRevenue',
            'validReceiptsCount',
            'tuitionRevenue',
            'surchargeRevenue',
            'tuitionPercent',
            'surchargePercent',
            'revenueTransfer',
            'revenueCash',
            'revenueTransferPercent',
            'revenueCashPercent',
            'prevMonth',
            'revenueDiffPercent',
            'revenueDiffIsUp',
            'totalExpense',
            'expensePercentageOfRevenue',
            'totalExpenseItemsCount',
            'salaryTotal',
            'rentUtilitiesExpense',
            'curriculumOperationsExpense',
            'otherExpense',
            'salaryPercentOfExpense',
            'rentPercentOfExpense',
            'curriculumPercentOfExpense',
            'provisionalRevenue',
            'isProfitPositive',
            'grossProfitMargin',
            'branchMatrix',
            'totalMatrixRevenue',
            'totalMatrixExpense',
            'totalMatrixProfit',
            'totalMatrixMargin',
            'totalMatrixStatus',
            'monthOptions'
        ));
    }

    /**
     * Xuất Báo cáo Doanh thu tạm tính ra Excel (CSV)
     */
    public function exportRevenueReport(Request $request)
    {
        $month = $request->input('month', Carbon::now()->format('Y-m'));
        try {
            $parsedDate = Carbon::createFromFormat('Y-m', $month);
        } catch (\Throwable $e) {
            $month = Carbon::now()->format('Y-m');
            $parsedDate = Carbon::createFromFormat('Y-m', $month);
        }
        $startDate = $parsedDate->copy()->startOfMonth()->toDateString();
        $endDate = $parsedDate->copy()->endOfMonth()->toDateString();

        // Xuất theo đúng bộ lọc chi nhánh đang xem (trong phạm vi được phép).
        $branchId = $this->resolveBranchFilter($request, $request->input('branch_id', 'all'));
        $branches = $this->visibleBranches($request)
            ->when($branchId !== 'all' && is_numeric($branchId), fn ($c) => $c->where('id', (int) $branchId)->values());
        $scoped = $this->scopedBranchIds($request->user()) !== null || ($branchId !== 'all' && is_numeric($branchId));
        $period = PayrollPeriod::where('month', (int)$parsedDate->format('m'))
            ->where('year', (int)$parsedDate->format('Y'))
            ->whereIn('status', ['approved', 'paid'])
            ->first();

        $filename = "Bao_Cao_Doanh_Thu_Tam_Tinh_{$month}.csv";

        return new StreamedResponse(function () use ($branches, $startDate, $endDate, $parsedDate, $period, $scoped) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, ['BÁO CÁO DOANH THU TẠM TÍNH - MENGLISH ADMIN']);
            fputcsv($handle, ["Kỳ tháng: {$parsedDate->format('m/Y')}"]);
            fputcsv($handle, []);
            fputcsv($handle, ['Chi nhánh / Cơ sở', 'Tổng thu (VNĐ)', 'Chi vận hành (VNĐ)', 'Doanh thu tạm tính (VNĐ)', 'Tỷ suất gộp (%)', 'Tình trạng']);

            $sumRev = 0;
            $sumExp = 0;

            foreach ($branches as $b) {
                $bRevenue = (float)$this->revenueReceiptQuery($startDate, $endDate, $b->id)->sum('amount');

                $bManual = (float)OperatingExpense::whereBetween('expense_date', [$startDate, $endDate])
                    ->where('branch_id', $b->id)->sum('amount');

                $bSalary = 0;
                if ($period) {
                    $bSalary = (float)PayrollRecord::where('payroll_period_id', $period->id)
                        ->whereHas('user', fn($u) => $u->where('branch_id', $b->id))->sum('net_salary');
                }

                $bExp = $bManual + $bSalary;
                $bProfit = $bRevenue - $bExp;
                $bMargin = $bRevenue > 0 ? round(($bProfit / $bRevenue) * 100, 1) : 0;

                fputcsv($handle, [
                    $b->name,
                    number_format($bRevenue, 0, ',', '.'),
                    number_format($bExp, 0, ',', '.'),
                    number_format($bProfit, 0, ',', '.'),
                    $bMargin . '%',
                    $this->branchMarginStatus($bMargin)['label'],
                ]);

                $sumRev += $bRevenue;
                $sumExp += $bExp;
            }

            $sumProfit = $sumRev - $sumExp;
            $sumMargin = $sumRev > 0 ? round(($sumProfit / $sumRev) * 100, 1) : 0;

            fputcsv($handle, [
                $scoped ? 'TỔNG CỘNG' : 'TỔNG CỘNG TOÀN HỆ THỐNG',
                number_format($sumRev, 0, ',', '.'),
                number_format($sumExp, 0, ',', '.'),
                number_format($sumProfit, 0, ',', '.'),
                $sumMargin . '%',
                $this->branchMarginStatus($sumMargin)['label'],
            ]);

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Phiếu thu tính doanh thu: CHỈ phiếu đã duyệt (pending/draft/rejected/cancelled không tính).
     * Chi nhánh ghi nhận theo hợp đồng (tuition.branch_id); chỉ fallback chi nhánh học viên khi phiếu không gắn hợp đồng
     * -> mỗi phiếu thuộc đúng 1 chi nhánh, không cộng trùng.
     */
    private function revenueReceiptQuery(string $startDate, string $endDate, int|string|null $branchId = null): Builder
    {
        $query = TuitionReceipt::query()
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->where('status', TuitionReceipt::STATUS_APPROVED);

        if ($branchId !== null && $branchId !== 'all' && is_numeric($branchId)) {
            // Chi nhánh của phiếu = chi nhánh hợp đồng; hợp đồng chưa gán chi nhánh thì theo học viên (như màn Học phí).
            TuitionBranchScope::receipts($query, [(int) $branchId]);
        }

        return $query;
    }

    /**
     * Chi nhánh người dùng được xem trong báo cáo thu chi — cùng quy tắc với các màn Học phí (TuitionBranchScope,
     * Phần D "Vòng 2"): Admin toàn hệ thống; Quản lý cơ sở / Học vụ / Học thuật chỉ chi nhánh mình; Kế toán không gán
     * chi nhánh = kế toán tổng (toàn hệ thống), kế toán có gán chi nhánh chỉ các chi nhánh đó. null = không giới hạn.
     *
     * @return \Illuminate\Support\Collection<int, int>|null
     */
    private function scopedBranchIds(?User $user): ?\Illuminate\Support\Collection
    {
        $ids = TuitionBranchScope::branchIds($user);
        if ($ids === null) {
            return null;
        }

        abort_if($ids === [], 403, 'Tài khoản chưa được gán chi nhánh nên không xem được báo cáo thu chi.');

        return collect($ids)->map(fn ($id) => (int) $id)->unique()->values();
    }

    /**
     * Chuẩn hoá bộ lọc chi nhánh theo phạm vi: người bị giới hạn không được chọn "Tất cả" hay chi nhánh ngoài phạm vi.
     */
    private function resolveBranchFilter(Request $request, int|string|null $branchId): int|string
    {
        $scope = $this->scopedBranchIds($request->user());
        if ($scope === null) {
            return $branchId ?? 'all';
        }

        return is_numeric($branchId) && $scope->contains((int) $branchId) ? (int) $branchId : $scope->first();
    }

    private function visibleBranches(Request $request)
    {
        $scope = $this->scopedBranchIds($request->user());

        return Branch::where('is_active', true)
            ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))
            ->orderBy('id')
            ->get();
    }

    private function authorizeExpenseBranch(Request $request, int|string|null $branchId): void
    {
        $scope = $this->scopedBranchIds($request->user());
        abort_if($scope !== null && ! $scope->contains((int) $branchId), 403, 'Bạn chỉ được quản lý khoản chi của chi nhánh mình.');
    }

    /**
     * @return array{label: string, class: string, dot: string, badge_bg: string}
     */
    private function branchMarginStatus(float $margin): array
    {
        return match (true) {
            $margin >= self::MARGIN_GOOD => ['label' => 'Tăng trưởng tốt', 'class' => 'text-emerald-700', 'dot' => 'bg-emerald-500', 'badge_bg' => 'bg-emerald-50 text-emerald-700'],
            $margin >= self::MARGIN_TARGET => ['label' => 'Đạt chỉ tiêu', 'class' => 'text-emerald-700', 'dot' => 'bg-emerald-500', 'badge_bg' => 'bg-emerald-50 text-emerald-700'],
            $margin >= 0 => ['label' => 'Ổn định', 'class' => 'text-blue-700', 'dot' => 'bg-blue-500', 'badge_bg' => 'bg-blue-50 text-blue-700'],
            default => ['label' => 'Cần tối ưu', 'class' => 'text-rose-700', 'dot' => 'bg-rose-500', 'badge_bg' => 'bg-rose-50 text-rose-700'],
        };
    }
}
