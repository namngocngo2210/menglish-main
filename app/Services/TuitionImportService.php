<?php

namespace App\Services;

use App\Imports\RawRowsImport;
use App\Models\ClassModel;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Nhập học phí từ Excel / CSV (Phase 4):
 * 1. parse(): đọc file, hiểu cột theo tiêu đề (có dấu / không dấu), kiểm tra từng dòng -> bản xem trước kèm lỗi.
 * 2. import(): ghi các dòng hợp lệ: tạo hồ sơ học phí (học viên chưa có) và/hoặc phiếu thu ở trạng thái
 *    CHỜ DUYỆT cho số tiền đã đóng (không bao giờ tự duyệt, không tự cấp số hóa đơn).
 */
class TuitionImportService
{
    public const MAX_ROWS = 1000;

    /** Cột trong file mẫu: key nội bộ => tiêu đề. */
    public const TEMPLATE_HEADINGS = [
        'student_code' => 'Mã học viên',
        'class_code' => 'Mã lớp',
        'total_amount' => 'Học phí niêm yết',
        'discount_amount' => 'Giảm trừ',
        'other_fees' => 'Phí khác',
        'due_date' => 'Hạn đóng',
        'paid_amount' => 'Số tiền đã đóng',
        'payment_method' => 'Hình thức',
        'transaction_code' => 'Mã giao dịch',
        'payment_date' => 'Ngày đóng',
        'notes' => 'Ghi chú',
    ];

    /** Tiêu đề cột (đã slug) được chấp nhận => key nội bộ. */
    private const HEADER_ALIASES = [
        'ma_hoc_vien' => 'student_code', 'ma_hv' => 'student_code', 'student_code' => 'student_code', 'ma_hoc_sinh' => 'student_code',
        'ma_lop' => 'class_code', 'class_code' => 'class_code',
        'hoc_phi_niem_yet' => 'total_amount', 'hoc_phi' => 'total_amount', 'tong_hoc_phi' => 'total_amount', 'total_amount' => 'total_amount',
        'giam_tru' => 'discount_amount', 'uu_dai' => 'discount_amount', 'giam_gia' => 'discount_amount', 'discount_amount' => 'discount_amount',
        'phi_khac' => 'other_fees', 'phu_phi' => 'other_fees', 'hoc_lieu' => 'other_fees', 'other_fees' => 'other_fees',
        'han_dong' => 'due_date', 'han_nop' => 'due_date', 'han_thanh_toan' => 'due_date', 'due_date' => 'due_date',
        'so_tien_da_dong' => 'paid_amount', 'da_dong' => 'paid_amount', 'so_tien_thu' => 'paid_amount', 'paid_amount' => 'paid_amount',
        'hinh_thuc' => 'payment_method', 'hinh_thuc_thanh_toan' => 'payment_method', 'phuong_thuc' => 'payment_method', 'payment_method' => 'payment_method',
        'ma_giao_dich' => 'transaction_code', 'ma_gd' => 'transaction_code', 'transaction_code' => 'transaction_code',
        'ngay_dong' => 'payment_date', 'ngay_thu' => 'payment_date', 'payment_date' => 'payment_date',
        'ghi_chu' => 'notes', 'notes' => 'notes',
    ];

    private const METHOD_ALIASES = [
        'tien_mat' => 'cash', 'tm' => 'cash', 'cash' => 'cash',
        'chuyen_khoan' => 'transfer', 'ck' => 'transfer', 'transfer' => 'transfer', 'vietqr' => 'vietqr',
        'pos' => 'pos', 'quet_the' => 'pos', 'the' => 'pos',
    ];

    public const METHOD_LABELS = ['cash' => 'Tiền mặt', 'transfer' => 'Chuyển khoản', 'vietqr' => 'VietQR', 'pos' => 'Quẹt thẻ POS'];

    /**
     * @return array{rows: list<array<string, mixed>>, missing_columns: list<string>}
     */
    public function parse(UploadedFile $file, int $branchId): array
    {
        $sheets = Excel::toArray(new RawRowsImport, $file);
        $raw = array_values(array_filter($sheets[0] ?? [], fn ($row) => collect($row)->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty()));

        if ($raw === []) {
            return ['rows' => [], 'missing_columns' => array_values(self::TEMPLATE_HEADINGS)];
        }

        $columns = [];
        foreach (array_shift($raw) as $index => $heading) {
            $slug = Str::slug(Str::ascii(preg_replace('/^\xEF\xBB\xBF/', '', (string) $heading)), '_');
            if (isset(self::HEADER_ALIASES[$slug]) && ! in_array(self::HEADER_ALIASES[$slug], $columns, true)) {
                $columns[$index] = self::HEADER_ALIASES[$slug];
            }
        }

        if (! in_array('student_code', $columns, true)) {
            return ['rows' => [], 'missing_columns' => [self::TEMPLATE_HEADINGS['student_code']]];
        }

        $raw = array_slice($raw, 0, self::MAX_ROWS);
        $students = Student::with('tuition')->whereIn('code', collect($raw)->map(fn ($r) => trim((string) ($r[array_search('student_code', $columns, true)] ?? '')))->filter()->unique())
            ->get()->keyBy(fn (Student $s) => mb_strtoupper($s->code));
        $classes = ClassModel::query()->get(['id', 'code', 'name', 'branch_id'])->keyBy(fn (ClassModel $c) => mb_strtoupper((string) $c->code));

        $rows = [];
        $newTuitionFor = [];      // mã HV đã có dòng tạo hồ sơ trong file
        $plannedDebt = [];        // công nợ còn lại dự kiến theo HV (sau các dòng trước trong file)
        $referencesInFile = [];

        foreach ($raw as $offset => $cells) {
            $line = $offset + 2; // dòng 1 là tiêu đề
            $data = [];
            foreach ($columns as $index => $key) {
                $data[$key] = is_string($cells[$index] ?? null) ? trim($cells[$index]) : ($cells[$index] ?? null);
            }

            $errors = [];
            $code = mb_strtoupper(trim((string) ($data['student_code'] ?? '')));
            $student = $code !== '' ? $students->get($code) : null;

            if ($code === '') {
                $errors[] = 'Thiếu mã học viên.';
            } elseif (! $student) {
                $errors[] = "Không tìm thấy học viên mã {$code}.";
            } elseif ((int) $student->branch_id !== $branchId) {
                $errors[] = "Học viên {$code} không thuộc chi nhánh đã chọn.";
            }

            $class = null;
            if (filled($data['class_code'] ?? null)) {
                $class = $classes->get(mb_strtoupper((string) $data['class_code']));
                if (! $class) {
                    $errors[] = "Không tìm thấy lớp mã {$data['class_code']}.";
                } elseif ($class->branch_id && (int) $class->branch_id !== $branchId) {
                    $errors[] = "Lớp {$data['class_code']} không thuộc chi nhánh đã chọn.";
                }
            }

            $total = $this->money($data['total_amount'] ?? null, 'Học phí niêm yết', $errors);
            $discount = $this->money($data['discount_amount'] ?? null, 'Giảm trừ', $errors) ?? 0.0;
            $otherFees = $this->money($data['other_fees'] ?? null, 'Phí khác', $errors) ?? 0.0;
            $paid = $this->money($data['paid_amount'] ?? null, 'Số tiền đã đóng', $errors) ?? 0.0;
            $dueDate = $this->date($data['due_date'] ?? null, 'Hạn đóng', $errors);
            $paymentDate = $this->date($data['payment_date'] ?? null, 'Ngày đóng', $errors) ?? now()->toDateString();

            $existing = $student?->tuition;
            $createsTuition = false;
            if ($student) {
                if ($existing || isset($newTuitionFor[$code])) {
                    if ($total !== null && $total > 0) {
                        $errors[] = 'Học viên đã có hồ sơ học phí — không ghi đè giá trị hợp đồng, chỉ nhập "Số tiền đã đóng".';
                    }
                } elseif ($total === null || $total <= 0) {
                    $errors[] = 'Học viên chưa có hồ sơ học phí: bắt buộc nhập "Học phí niêm yết" > 0.';
                } else {
                    $createsTuition = true;
                    if (! $dueDate) {
                        $errors[] = 'Hồ sơ học phí mới cần "Hạn đóng".';
                    }
                }
            }

            $final = $createsTuition ? round((float) $total - $discount + $otherFees, 2) : null;
            if ($final !== null && $final < 0) {
                $errors[] = 'Giảm trừ lớn hơn học phí + phí khác.';
            }

            $method = null;
            $reference = null;
            if ($paid > 0) {
                $methodKey = Str::slug(Str::ascii((string) ($data['payment_method'] ?? '')), '_');
                $method = self::METHOD_ALIASES[$methodKey] ?? null;
                if (! $method) {
                    $errors[] = 'Hình thức thanh toán không hợp lệ (tien_mat / chuyen_khoan / pos).';
                }

                if (in_array($method, TuitionReceipt::TRANSFER_METHODS, true)) {
                    $reference = TuitionReceipt::normalizeReference((string) ($data['transaction_code'] ?? ''));
                    if ($reference === null) {
                        $errors[] = 'Chuyển khoản cần "Mã giao dịch" để đối soát.';
                    } elseif (isset($referencesInFile[$reference]) || TuitionReceipt::findByTransferReference($reference)) {
                        $errors[] = "Mã giao dịch {$data['transaction_code']} đã được ghi nhận (trùng trong file hoặc đã có phiếu thu).";
                    }
                }

                $debt = $plannedDebt[$code] ?? ($existing ? (float) $existing->debt_amount : (float) $final);
                if ($student && $paid > $debt + 0.5) {
                    $errors[] = 'Số tiền đã đóng ('.number_format($paid, 0, ',', '.').'đ) vượt công nợ còn lại ('.number_format($debt, 0, ',', '.').'đ).';
                }
            }

            if ($errors === []) {
                if ($createsTuition) {
                    $newTuitionFor[$code] = true;
                }
                $plannedDebt[$code] = ($plannedDebt[$code] ?? ($existing ? (float) $existing->debt_amount : (float) $final)) - $paid;
                if ($reference) {
                    $referencesInFile[$reference] = true;
                }
            }

            $rows[] = [
                'line' => $line,
                'student_id' => $student?->id,
                'student_code' => $code,
                'student_name' => $student?->name,
                'class_id' => $class?->id,
                'class_code' => $data['class_code'] ?? null,
                'creates_tuition' => $createsTuition,
                'total_amount' => $total,
                'discount_amount' => $discount,
                'other_fees' => $otherFees,
                'final_amount' => $final,
                'due_date' => $dueDate,
                'paid_amount' => $paid,
                'payment_method' => $method,
                'transaction_code' => $reference ? trim((string) $data['transaction_code']) : (filled($data['transaction_code'] ?? null) ? trim((string) $data['transaction_code']) : null),
                'payment_date' => $paymentDate,
                'notes' => filled($data['notes'] ?? null) ? mb_substr((string) $data['notes'], 0, 500) : null,
                'errors' => $errors,
            ];
        }

        return ['rows' => $rows, 'missing_columns' => []];
    }

    /**
     * Ghi các dòng hợp lệ. Mỗi dòng một transaction: lỗi dòng nào thì chỉ dòng đó không vào.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{tuitions: int, receipts: int, failed: list<array{line: int, error: string}>}
     */
    public function import(array $rows, int $branchId, User $user): array
    {
        $result = ['tuitions' => 0, 'receipts' => 0, 'failed' => []];

        foreach ($rows as $row) {
            if (! empty($row['errors'])) {
                continue;
            }

            try {
                DB::transaction(function () use ($row, $branchId, $user, &$result) {
                    $student = Student::query()->findOrFail($row['student_id']);
                    $tuition = StudentTuition::query()->where('student_id', $student->id)->lockForUpdate()->first();

                    if ($row['creates_tuition']) {
                        if ($tuition) {
                            throw new \RuntimeException('Học viên vừa được tạo hồ sơ học phí ở nơi khác.');
                        }
                        $tuition = StudentTuition::create([
                            'student_id' => $student->id,
                            'class_id' => $row['class_id'] ?? $student->current_class_id,
                            'branch_id' => $branchId,
                            'total_amount' => $row['total_amount'],
                            'discount_amount' => $row['discount_amount'],
                            'other_fees' => $row['other_fees'],
                            'final_amount' => $row['final_amount'],
                            'paid_amount' => 0,
                            'debt_amount' => $row['final_amount'],
                            'due_date' => $row['due_date'],
                            'status' => 'unpaid',
                            'notes' => '['.now()->format('d/m/Y H:i').'] Nhập từ Excel (dòng '.$row['line'].') bởi '.$user->name.'.',
                        ]);
                        $tuition->recalculateDebt();
                        $result['tuitions']++;
                    }

                    if ((float) $row['paid_amount'] > 0) {
                        if (! $tuition) {
                            throw new \RuntimeException('Không tìm thấy hồ sơ học phí để ghi nhận khoản đã đóng.');
                        }
                        TuitionReceipt::create([
                            'receipt_number' => TuitionReceipt::generateReceiptNumber(),
                            'student_tuition_id' => $tuition->id,
                            'student_id' => $student->id,
                            'amount' => $row['paid_amount'],
                            'tuition_amount' => $row['paid_amount'],
                            'payment_method' => $row['payment_method'],
                            'transaction_code' => $row['transaction_code'],
                            'payment_date' => $row['payment_date'],
                            'creator_id' => $user->id,
                            'status' => TuitionReceipt::STATUS_PENDING,
                            'notes' => trim('Nhập từ Excel (dòng '.$row['line'].'), chờ Kế toán đối soát & duyệt. '.($row['notes'] ?? '')),
                        ]);
                        $result['receipts']++;
                    }
                });
            } catch (\Throwable $e) {
                $result['failed'][] = ['line' => (int) $row['line'], 'error' => $e instanceof \RuntimeException ? $e->getMessage() : 'Lỗi ghi dữ liệu: '.$e->getMessage()];
            }
        }

        return $result;
    }

    private function money(mixed $value, string $label, array &$errors): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        // Số thật từ ô Excel dùng nguyên giá trị; chuỗi (CSV / ô text) coi "." và "," là dấu phân cách hàng nghìn (VNĐ không có số lẻ).
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
        } else {
            $digits = preg_replace('/[^\d-]/', '', (string) $value);
            if ($digits === '' || ! is_numeric($digits)) {
                $errors[] = "{$label} không phải số tiền hợp lệ ({$value}).";

                return null;
            }
            $number = (float) $digits;
        }

        if ($number < 0) {
            $errors[] = "{$label} không được âm.";

            return null;
        }

        return round($number, 0);
    }

    private function date(mixed $value, string $label, array &$errors): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            }
            foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $format) {
                $parsed = \DateTime::createFromFormat('!'.$format, trim((string) $value));
                $problems = \DateTime::getLastErrors();
                if ($parsed && (! $problems || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))) {
                    return Carbon::instance($parsed)->toDateString();
                }
            }
        } catch (\Throwable) {
            // rơi xuống báo lỗi
        }

        $errors[] = "{$label} không đúng định dạng ngày dd/mm/yyyy ({$value}).";

        return null;
    }
}
