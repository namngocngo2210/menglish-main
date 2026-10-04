<?php

namespace App\Support;

use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use Illuminate\Support\Collection;

/**
 * Toàn bộ lịch sử thu học phí của một học viên (mọi đợt nộp của MỌI khóa học / khoản học phí, mỗi lần thu ghi rõ khóa · lớp) — dùng chung cho modal
 * "Lịch sử thu học phí" ở màn Học vụ (hồ sơ học viên / công nợ) và cổng học sinh.
 *
 * - Chỉ phiếu đã duyệt mới tính vào số đã đóng / còn nợ (như StudentTuition::recalculateDebt): mỗi đợt kèm
 *   "còn nợ sau đợt này" = tổng phải thu − Σ (tiền cấn học phí + ưu đãi) của các phiếu đã duyệt tính tới đợt đó.
 * - Học viên chỉ thấy phiếu đã duyệt; phần "Thu khác" chỉ hiện tổng tiền, không liệt kê từng món (như bill, PR #66).
 * - Học vụ thấy thêm chi tiết món thu khác, người lập / duyệt và các phiếu chưa tính (chờ duyệt, nháp, bị trả về, đã hủy).
 */
class TuitionPaymentHistory
{
    private const METHOD_ICONS = ['transfer' => 'account_balance', 'vietqr' => 'qr_code_2', 'cash' => 'payments', 'pos' => 'credit_card'];

    /**
     * @return array{summary: array<string, mixed>, tuitions: list<array<string, mixed>>, payments: list<array<string, mixed>>, others: list<array<string, mixed>>}
     */
    public static function forStudent(Student $student, bool $forStaff = false): array
    {
        $tuitions = StudentTuition::with('classModel.course')
            ->where('student_id', $student->id)
            ->orderBy('id')
            ->get();

        $receipts = TuitionReceipt::query()
            ->with($forStaff ? ['creator', 'approver'] : [])
            ->where(fn ($q) => $q->where('student_id', $student->id)
                ->when($tuitions->isNotEmpty(), fn ($q) => $q->orWhereIn('student_tuition_id', $tuitions->modelKeys())))
            ->get()
            ->sortBy(fn (TuitionReceipt $rc) => sprintf(
                '%s|%010d',
                ($rc->payment_date ?? $rc->approved_at ?? $rc->created_at)?->format('Y-m-d') ?? '0000-00-00',
                $rc->id,
            ))
            ->values();

        $approved = $receipts->where('status', TuitionReceipt::STATUS_APPROVED)->values();
        $tuitionsById = $tuitions->keyBy('id');
        $remaining = $tuitions->mapWithKeys(fn (StudentTuition $t) => [$t->id => (float) $t->final_amount])->all();

        $payments = [];
        $installment = 0;
        $perTuition = [];
        foreach ($approved as $rc) {
            $tuitionId = $rc->student_tuition_id;
            $debtAfter = null;
            if ($tuitionId !== null && array_key_exists($tuitionId, $remaining)) {
                $remaining[$tuitionId] -= $rc->tuitionPortion() + (float) $rc->discount_amount;
                $debtAfter = max(0, round($remaining[$tuitionId], 2));
            }
            $kind = self::kind($rc);
            // Số đợt đánh theo từng khóa (Đợt 1, 2… của khoản học phí đó); tổng số lần đóng ở summary.
            $number = null;
            if ($kind === 'payment' && $tuitionId !== null) {
                $installment++;
                $number = $perTuition[$tuitionId] = ($perTuition[$tuitionId] ?? 0) + 1;
            }

            $payments[] = self::paymentRow($rc, $kind, $number, $debtAfter, $forStaff)
                + ['tuition_label' => $tuitionId ? self::courseLabel($tuitionsById->get($tuitionId)) : null];
        }

        $finalTotal = (float) $tuitions->sum('final_amount');
        $debtTotal = (float) $tuitions->sum('debt_amount');
        $nextDue = $tuitions->filter(fn (StudentTuition $t) => (float) $t->debt_amount > 0 && $t->due_date)->min('due_date');

        return [
            'summary' => [
                'final_amount' => $finalTotal,
                'paid_amount' => (float) $tuitions->sum('paid_amount'),
                'discount_amount' => (float) $approved->sum(fn (TuitionReceipt $rc) => (float) $rc->discount_amount),
                'debt_amount' => $debtTotal,
                'other_fees' => (float) $tuitions->sum('other_fees'),
                'surcharge_amount' => (float) $approved->sum(fn (TuitionReceipt $rc) => (float) $rc->surcharge_amount),
                'installments' => $installment,
                'next_due_date' => $nextDue?->format('d/m/Y'),
                'is_settled' => $tuitions->isNotEmpty() && $debtTotal <= 0,
            ],
            'tuitions' => $tuitions->map(fn (StudentTuition $t) => [
                'id' => $t->id,
                'label' => $forStaff ? $t->fee_label : 'Học phí '.($t->classModel?->course?->name ?? $t->classModel?->name ?? 'khóa học'),
                'course_label' => self::courseLabel($t),
                'class_name' => $t->classModel?->name,
                'final_amount' => (float) $t->final_amount,
                'other_fees' => (float) $t->other_fees,
                'paid_amount' => (float) $t->paid_amount,
                'debt_amount' => (float) $t->debt_amount,
                'due_date' => (float) $t->debt_amount > 0 ? $t->due_date?->format('d/m/Y') : null,
                'status_label' => self::tuitionStatusLabel($t),
                'status_color' => $t->status_color,
            ])->values()->all(),
            // Mới nhất lên đầu; số "Đợt" vẫn đánh theo thứ tự thời gian.
            'payments' => array_reverse($payments),
            'others' => $forStaff
                ? $receipts->where('status', '!=', TuitionReceipt::STATUS_APPROVED)->sortByDesc('id')->map(fn (TuitionReceipt $rc) => [
                    'id' => $rc->id,
                    'number' => $rc->receipt_number ?? ('PT-'.$rc->id),
                    'date' => ($rc->payment_date ?? $rc->created_at)?->format('d/m/Y'),
                    'amount' => (float) $rc->amount,
                    'method' => TuitionReceipt::METHOD_LABELS[$rc->payment_method] ?? ($rc->payment_method ?: '—'),
                    'status_label' => $rc->status_label,
                    'status_color' => $rc->status_color,
                    'rejection_reason' => $rc->status === TuitionReceipt::STATUS_REJECTED ? $rc->rejection_reason : null,
                ])->values()->all()
                : [],
        ];
    }

    /** payment | refund | transfer_in | transfer_out — theo mã giao dịch hệ thống tự sinh khi hoàn / chuyển phí. */
    private static function kind(TuitionReceipt $rc): string
    {
        $code = (string) $rc->transaction_code;

        return match (true) {
            str_starts_with($code, 'XFER-OUT-') => 'transfer_out',
            str_starts_with($code, 'XFER-IN-') => 'transfer_in',
            str_starts_with($code, 'REFUND-') || (float) $rc->amount < 0 => 'refund',
            default => 'payment',
        };
    }

    private static function paymentRow(TuitionReceipt $rc, string $kind, ?int $installment, ?float $debtAfter, bool $forStaff): array
    {
        $row = [
            'id' => $rc->id,
            'kind' => $kind,
            'title' => match ($kind) {
                'transfer_out' => 'Chuyển phí sang học viên khác',
                'transfer_in' => 'Nhận chuyển phí',
                'refund' => 'Hoàn học phí',
                default => $installment !== null ? 'Đợt '.$installment : 'Thu khác',
            },
            'installment' => $installment,
            'number' => $rc->receipt_number ?? ('PT-'.$rc->id),
            'date' => ($rc->payment_date ?? $rc->approved_at ?? $rc->created_at)?->format('d/m/Y'),
            'method' => TuitionReceipt::METHOD_LABELS[$rc->payment_method] ?? ($rc->payment_method ?: '—'),
            'method_icon' => self::METHOD_ICONS[$rc->payment_method] ?? 'payments',
            'amount' => (float) $rc->amount,
            'surcharge' => (float) $rc->surcharge_amount,
            'discount' => (float) $rc->discount_amount,
            'debt_after' => $debtAfter,
        ];

        if ($forStaff) {
            $row += [
                'invoice_number' => $rc->invoice_number,
                'surcharge_detail' => self::surchargeDetail($rc),
                'creator_name' => $rc->creator?->name,
                'approver_name' => $rc->approver?->name,
                'notes' => $rc->notes,
            ];
        }

        return $row;
    }

    /** Chi tiết món thu khác (chỉ cho Học vụ): lý do phụ thu + tên món × số lượng đã ghi trên phiếu. */
    private static function surchargeDetail(TuitionReceipt $rc): ?string
    {
        $items = Collection::make(is_array($rc->collected_items) ? $rc->collected_items : [])
            ->filter(fn ($line) => is_array($line) && filled($line['name'] ?? null))
            ->map(fn (array $line) => $line['name'].(((int) ($line['quantity'] ?? 1)) > 1 ? ' ×'.(int) $line['quantity'] : ''))
            ->implode(', ');

        $detail = trim(implode(' · ', array_filter([$rc->surcharge_reason, $items])));

        return $detail !== '' ? $detail : null;
    }

    /** Khóa học · lớp của khoản học phí (cột "Khóa học" ở từng lần thu — học viên học nhiều khóa vẫn phân biệt được). */
    private static function courseLabel(?StudentTuition $t): ?string
    {
        if (! $t) {
            return null;
        }
        $parts = array_values(array_unique(array_filter([$t->classModel?->course?->name, $t->classModel?->name])));

        return $parts ? implode(' · ', $parts) : 'Khoản học phí #'.$t->id;
    }

    private static function tuitionStatusLabel(StudentTuition $t): string
    {
        if ((float) $t->debt_amount <= 0) {
            return 'Đã đóng đủ';
        }

        return (float) $t->paid_amount > 0 ? 'Đang đóng dần' : 'Chưa đóng';
    }
}
