<?php

namespace App\Services\Sla;

use App\Models\AdminNotification;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\CrmTrialBooking;
use App\Models\PlacementTestSubmission;
use App\Models\SlaEvent;
use App\Models\SystemSetting;
use App\Services\BranchStaff;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * SLA CRM tự động (đếm ngược từ lúc mốc được kích hoạt → quá hạn tự lập biên bản + báo Admin / người phụ trách):
 * liên hệ lần đầu, chuyển trạng thái tối đa, trả kết quả test, phản hồi sau học thử, theo dõi học phí tuần đầu,
 * liên hệ thất bại liên tiếp. Chỉ áp dụng cho mốc phát sinh sau thời điểm bật (sla_crm_live_since) — không phạt hồi tố.
 */
class CrmSlaService
{
    public function __construct(private SlaBreachService $breaches) {}

    /** @return array<string, int> số mốc mở mới / quá hạn mới theo từng SLA */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $since = $this->liveSince($now);
        $stats = [];

        foreach (['crm.first_contact', 'crm.status_move'] as $rule) {
            $stats[$rule] = Sla::enabled($rule) ? $this->customerWindows($rule, $since, $now) : 0;
        }
        $stats['crm.test_result'] = Sla::enabled('crm.test_result') ? $this->testResults($since, $now) : 0;
        $stats['crm.trial_feedback'] = Sla::enabled('crm.trial_feedback') ? $this->trialFeedback($since, $now) : 0;
        $stats['crm.tuition_followup'] = Sla::enabled('crm.tuition_followup') ? $this->tuitionFollowUp($since, $now) : 0;

        return $stats;
    }

    /** Thời điểm bắt đầu áp dụng (lần chạy đầu tiên); mốc trước đó được miễn. */
    private function liveSince(Carbon $now): Carbon
    {
        $value = SystemSetting::get('sla_crm_live_since');
        if (! $value) {
            SystemSetting::set('sla_crm_live_since', $now->toIso8601String(), 'Thời điểm bắt đầu áp dụng SLA CRM tự động (mốc trước đó không bị phạt hồi tố)');

            return $now->copy();
        }

        return Carbon::parse($value);
    }

    /** Khung giờ kể từ lúc thêm khách: liên hệ lần đầu / chuyển trạng thái. */
    private function customerWindows(string $rule, Carbon $since, Carbon $now): int
    {
        $hours = Sla::value($rule);
        $done = SlaEvent::query()->where('rule_key', $rule)->where('subject_type', 'crm_customer')
            ->where(fn ($q) => $q->whereNotNull('breached_at')->orWhereNotNull('resolved_at'))->select('subject_id');
        $count = 0;

        CrmCustomer::with(['assignedUser', 'histories'])
            ->where('created_at', '>=', $since)->where('created_at', '<=', $now->copy()->subHours($hours))
            ->whereNotIn('id', $done)
            ->orderBy('id')->each(function (CrmCustomer $customer) use ($rule, $hours, $now, &$count) {
                $due = $customer->created_at->copy()->addHours($hours);
                $event = $this->breaches->open($rule, 'crm_customer', $customer->id, $customer->assignedUser, $customer->created_at, $due);
                $types = $rule === 'crm.first_contact' ? CrmCustomerHistory::CARE_TYPES : ['stage_change', 'lost'];
                $firstAt = $customer->histories->whereIn('type', $types)
                    ->filter(fn ($h) => $h->outcome !== CrmCustomerHistory::OUTCOME_FAILED)->min('created_at');
                if ($firstAt && Carbon::parse($firstAt)->lte($due)) {
                    $this->breaches->resolve($event, Carbon::parse($firstAt));

                    return;
                }
                $this->breaches->breach($event, "{$customer->code} {$customer->name}", [
                    'Khách hàng' => "{$customer->code} — {$customer->name} ({$customer->phone})",
                    'Thêm vào hệ thống lúc' => $customer->created_at->format('H:i d/m/Y'),
                    'Trạng thái hiện tại' => CrmCustomer::PIPELINE_STAGES[$customer->stage] ?? $customer->stage,
                    'Hoạt động hợp lệ đầu tiên' => $firstAt ? Carbon::parse($firstAt)->format('H:i d/m/Y') : 'Chưa có',
                ], route('crm.customers.show', $customer->id), $now);
                $count++;
            });

        return $count;
    }

    private function testResults(Carbon $since, Carbon $now): int
    {
        $hours = Sla::value('crm.test_result');
        $count = 0;

        PlacementTestSubmission::query()->whereNotNull('customer_id')->where('created_at', '>=', $since)->orderBy('id')
            ->each(function (PlacementTestSubmission $submission) use ($hours, $now, &$count) {
                $customer = CrmCustomer::with('assignedUser')->find($submission->customer_id);
                if (! $customer || $customer->stage === CrmCustomer::STAGE_LOST) {
                    return;
                }
                $due = $submission->created_at->copy()->addHours($hours);
                $event = $this->breaches->open('crm.test_result', 'placement_submission', $submission->id, $customer->assignedUser, $submission->created_at, $due, [
                    'title' => "Trả kết quả test đầu vào cho {$customer->name}",
                    'description' => "Khách {$customer->code} đã nộp bài test lúc {$submission->created_at->format('H:i d/m/Y')}. Chấm / xác nhận điểm và ghi nhận \"Gửi kết quả\" cho phụ huynh trước {$due->format('H:i d/m/Y')}.",
                    'branch_id' => $customer->branch_id,
                ], $opened);
                $count += $opened ? 1 : 0;

                $sentAt = $customer->histories()->where('type', 'result')->where('created_at', '>=', $submission->created_at)->min('created_at');
                $this->settle($event, $sentAt ? Carbon::parse($sentAt) : null, $now, "{$customer->code} {$customer->name}", [
                    'Khách hàng' => "{$customer->code} — {$customer->name}",
                    'Nộp bài test lúc' => $submission->created_at->format('H:i d/m/Y'),
                    'Đã gửi kết quả' => $sentAt ? Carbon::parse($sentAt)->format('H:i d/m/Y') : 'Chưa gửi',
                ], route('crm.customers.show', $customer->id));
            });

        return $count;
    }

    private function trialFeedback(Carbon $since, Carbon $now): int
    {
        $hours = Sla::value('crm.trial_feedback');
        $count = 0;

        CrmTrialBooking::with(['customer.assignedUser', 'session', 'classModel'])
            ->whereIn('status', ['scheduled', 'attended'])->orderBy('id')
            ->each(function (CrmTrialBooking $booking) use ($hours, $since, $now, &$count) {
                $ends = $booking->sessionEndsAt();
                $customer = $booking->customer;
                if (! $ends || $ends->gt($now) || $ends->lt($since) || ! $customer || $customer->stage === CrmCustomer::STAGE_LOST) {
                    return;
                }
                $due = $ends->copy()->addHours($hours);
                $event = $this->breaches->open('crm.trial_feedback', 'crm_trial_booking', $booking->id, $customer->assignedUser, $ends, $due, [
                    'title' => "Phản hồi phụ huynh sau học thử: {$customer->name}",
                    'description' => "Buổi học thử lớp {$booking->classModel?->name} kết thúc lúc {$ends->format('H:i d/m/Y')}. Liên hệ phụ huynh lấy phản hồi và ghi nhật ký trước {$due->format('H:i d/m/Y')}.",
                    'branch_id' => $customer->branch_id,
                ], $opened);
                $count += $opened ? 1 : 0;

                $contactAt = $customer->histories()->counted()->whereIn('type', ['call', 'message', 'meet', 'result'])
                    ->where('created_at', '>=', $ends)->min('created_at');
                $this->settle($event, $contactAt ? Carbon::parse($contactAt) : null, $now, "{$customer->code} {$customer->name}", [
                    'Khách hàng' => "{$customer->code} — {$customer->name}",
                    'Lớp học thử' => (string) $booking->classModel?->name,
                    'Buổi học thử kết thúc lúc' => $ends->format('H:i d/m/Y'),
                    'Liên hệ phản hồi đầu tiên' => $contactAt ? Carbon::parse($contactAt)->format('H:i d/m/Y') : 'Chưa có',
                ], route('crm.customers.show', $customer->id));
            });

        return $count;
    }

    private function tuitionFollowUp(Carbon $since, Carbon $now): int
    {
        $hours = Sla::value('crm.tuition_followup');
        $count = 0;

        CrmCustomer::with(['assignedUser', 'convertedStudent.tuition'])
            ->whereIn('stage', CrmCustomer::CLOSED_STAGES)->whereNotNull('converted_at')->where('converted_at', '>=', $since)
            ->orderBy('id')->each(function (CrmCustomer $customer) use ($hours, $now, &$count) {
                $due = $customer->converted_at->copy()->addHours($hours);
                $event = $this->breaches->open('crm.tuition_followup', 'crm_customer', $customer->id, $customer->assignedUser, $customer->converted_at, $due, [
                    'title' => "Theo dõi thu học phí: {$customer->name}",
                    'description' => "Khách {$customer->code} đã chốt lúc {$customer->converted_at->format('H:i d/m/Y')}. Theo dõi và thu đủ học phí trước {$due->format('H:i d/m/Y')}.",
                    'branch_id' => $customer->branch_id,
                ], $opened);
                $count += $opened ? 1 : 0;

                $tuition = $customer->convertedStudent?->tuition;
                $paid = $tuition && (float) $tuition->paid_amount > 0 && (float) $tuition->debt_amount <= 0;
                $this->settle($event, $paid ? $now : null, $now, "{$customer->code} {$customer->name}", [
                    'Khách hàng' => "{$customer->code} — {$customer->name}",
                    'Chốt lúc' => $customer->converted_at->format('H:i d/m/Y'),
                    'Còn nợ học phí' => $tuition ? Money::format($tuition->debt_amount) : 'Chưa có hồ sơ học phí',
                ], route('crm.customers.show', $customer->id));
            });

        return $count;
    }

    /** Xong đúng hạn → đóng mốc; quá hạn mà chưa xong (hoặc xong trễ) → lập biên bản / cảnh báo một lần. */
    private function settle(SlaEvent $event, ?Carbon $doneAt, Carbon $now, string $label, array $facts, string $link): void
    {
        if ($event->resolved_at || $event->breached_at) {
            if ($doneAt && ! $event->resolved_at) {
                $this->breaches->resolve($event, $doneAt);
            }

            return;
        }
        if ($doneAt && $doneAt->lte($event->due_at)) {
            $this->breaches->resolve($event, $doneAt);

            return;
        }
        if ($now->gt($event->due_at)) {
            $this->breaches->breach($event, $label, $facts, $link, $now);
            if ($doneAt) {
                $this->breaches->resolve($event, $doneAt);
            }
        }
    }

    /**
     * Liên hệ thất bại liên tiếp ≥ ngưỡng (kể từ lần liên hệ được gần nhất) → cảnh báo Admin + giao việc báo cáo cho người phụ trách (một lần / chuỗi).
     * Gọi ngay sau khi ghi nhật ký liên hệ.
     */
    public function checkFailedContacts(CrmCustomer $customer): void
    {
        if (! Sla::enabled('crm.failed_contacts') || $customer->stage === CrmCustomer::STAGE_LOST) {
            return;
        }
        $threshold = max(1, Sla::value('crm.failed_contacts'));
        $streak = [];
        foreach ($customer->histories()->whereIn('type', CrmCustomerHistory::CONTACT_TYPES)->orderByDesc('id')->get(['id', 'outcome', 'created_at']) as $history) {
            if ($history->outcome !== CrmCustomerHistory::OUTCOME_FAILED) {
                break;
            }
            $streak[] = $history;
        }
        if (count($streak) < $threshold) {
            return;
        }
        $first = end($streak);
        $now = now();
        $owner = $customer->assignedUser;
        $event = $this->breaches->open('crm.failed_contacts', 'crm_failed_streak', $first->id, $owner, $first->created_at, $now->copy()->addDay(), [
            'title' => "Báo cáo liên hệ thất bại {$threshold} lần: {$customer->name}",
            'description' => "Đã liên hệ {$customer->code} thất bại ".count($streak).' lần liên tiếp. Báo cáo nguyên nhân và hướng xử lý tiếp theo (đổi kênh, nhờ người thân, chuyển Thất bại…).',
            'branch_id' => $customer->branch_id,
        ], $created);
        if (! $created) {
            return;
        }
        $event->update(['breached_at' => $now]);
        foreach (collect([$owner])->merge(BranchStaff::admins())->filter()->unique('id') as $user) {
            AdminNotification::create([
                'user_id' => $user->id,
                'type' => 'sla_breach',
                'title' => "Cảnh báo: {$customer->code} liên hệ thất bại ".count($streak).' lần liên tiếp',
                'message' => "{$customer->name} ({$customer->phone}) — người phụ trách ".($owner?->name ?? 'chưa phân công').'.',
                'data' => ['link' => route('crm.customers.show', $customer->id), 'sla_rule' => 'crm.failed_contacts'],
                'is_read' => false,
            ]);
        }
    }
}
