<?php

namespace App\Services;

use App\Exceptions\CrmStageTransitionException;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\User;

/**
 * Nguồn luật duy nhất cho giai đoạn Lead CRM (BA chốt 2026-09-25).
 *
 * Pipeline: new → consulting → test_scheduled → testing → tested → result_sent → waiting_class → won (+ lost).
 *
 * - move(): thao tác tay (Kanban / nút trong hồ sơ).
 *     + CM (admin, manager, academic_staff) chỉ chuyển TIẾN đúng 1 bước. Sales không đổi giai đoạn.
 *     + Chờ xếp lớp / Đã chốt chỉ đạt được qua luồng Chốt & Xếp lớp / Gán lớp, không kéo tay.
 *     + Lùi bước: chỉ Admin, bắt buộc lý do. Không "hủy chốt": lead đã chốt không lùi, không sang thất bại.
 *     + Thất bại: chỉ lead chưa chốt, bắt buộc lý do; lead thất bại không bao giờ mở lại.
 * - advanceTo(): hook tự động cho các luồng nghiệp vụ (mở link test → testing, chấm xong → tested,
 *   hẹn test, chốt, gán lớp). Chỉ đi tiến; trả về false (no-op) nếu không áp dụng được.
 *
 * Mọi thay đổi đều ghi crm_customer_histories (type stage_change, from/to, lý do).
 */
class CrmStageService
{
    /**
     * Nguồn hợp lệ cho các bước chuyển tự động: stage đích => stage nguồn.
     * testing/tested chấp nhận nguồn sớm hơn vì thí sinh có thể làm bài khi chưa hẹn (link / offline).
     */
    private const AUTOMATIC_SOURCES = [
        'consulting' => ['new'],
        'test_scheduled' => ['consulting'],
        'testing' => ['consulting', 'test_scheduled'],
        'tested' => ['consulting', 'test_scheduled', 'testing'],
        'result_sent' => ['tested'],
        'waiting_class' => CrmCustomer::CLOSABLE_STAGES,
        'won' => [...CrmCustomer::CLOSABLE_STAGES, 'waiting_class'],
    ];

    /**
     * Hook tự động, dùng cho mọi luồng nghiệp vụ đổi giai đoạn.
     *
     * Ví dụ (chấm xong bài test): app(CrmStageService::class)->advanceTo($lead, 'tested', Auth::user(), 'Học vụ chấm xong bài test #12');
     *
     * @return bool true nếu đã chuyển; false nếu lead đã ở / đã qua bước đó, đã chốt, thất bại, hoặc nguồn không hợp lệ.
     */
    public function advanceTo(CrmCustomer $customer, string $stage, ?User $actor, string $reason): bool
    {
        if (! in_array($customer->stage, self::AUTOMATIC_SOURCES[$stage] ?? [], true)) {
            return false;
        }

        $this->apply($customer, $stage, $actor, $reason);

        return true;
    }

    /**
     * Chuyển giai đoạn bằng tay, áp đủ luật vai trò / thứ tự.
     *
     * @throws CrmStageTransitionException
     */
    public function move(CrmCustomer $customer, string $stage, User $user, ?string $reason = null): void
    {
        $from = $customer->stage;
        $reason = $reason !== null ? trim($reason) : null;

        if ($stage === $from) {
            return;
        }
        if ($from === CrmCustomer::STAGE_LOST) {
            throw new CrmStageTransitionException('Lead đã thất bại được lưu để đối soát, không thể mở lại.');
        }
        if (! $this->canMoveForward($user)) {
            throw CrmStageTransitionException::forbidden('Chỉ Học vụ / Quản lý cơ sở / Admin được chuyển giai đoạn Lead.');
        }

        if ($stage === CrmCustomer::STAGE_LOST) {
            if ($customer->isClosed()) {
                throw new CrmStageTransitionException('Lead đã chốt (đã có hồ sơ học viên) không thể chuyển sang thất bại.');
            }
            if (! $reason) {
                throw new CrmStageTransitionException('Vui lòng nhập lý do thất bại.');
            }
            $this->apply($customer, $stage, $user, $reason, ['lost_reason' => $reason, 'lost_at' => now()]);

            return;
        }

        $fromIndex = $this->indexOf($from);
        $toIndex = $this->indexOf($stage);
        if ($fromIndex === null || $toIndex === null) {
            throw new CrmStageTransitionException('Giai đoạn không hợp lệ.');
        }

        if ($toIndex < $fromIndex) {
            if (! $this->canMoveBackward($user)) {
                throw CrmStageTransitionException::forbidden('Chỉ Admin được lùi giai đoạn Lead.');
            }
            if ($customer->isClosed()) {
                throw new CrmStageTransitionException('Lead đã chốt không thể lùi giai đoạn (không hỗ trợ hủy chốt).');
            }
            if (! $reason) {
                throw new CrmStageTransitionException('Lùi giai đoạn bắt buộc nhập lý do.');
            }
            $this->apply($customer, $stage, $user, $reason);

            return;
        }

        if (in_array($stage, CrmCustomer::CLOSED_STAGES, true)) {
            throw new CrmStageTransitionException($stage === 'won' && $from === 'waiting_class'
                ? 'Hãy gán lớp cho học viên để chuyển sang Đã chốt.'
                : 'Hãy dùng Chốt & Xếp lớp để chốt Lead (tạo hồ sơ học viên, học phí).');
        }
        if ($toIndex !== $fromIndex + 1) {
            throw new CrmStageTransitionException('Chỉ được chuyển tiến 1 bước: '.CrmCustomer::stageLabel($from).' → '.CrmCustomer::stageLabel($this->nextStage($from)).'.');
        }

        $this->apply($customer, $stage, $user, $reason ?: null);
    }

    public function nextStage(?string $stage): ?string
    {
        $index = $this->indexOf($stage);
        $stages = array_keys(CrmCustomer::PIPELINE_STAGES);

        return $index === null ? null : ($stages[$index + 1] ?? null);
    }

    /** Bước kế tiếp mà người dùng được kéo tay (null nếu không có / cần luồng Chốt). */
    public function manualNextStage(CrmCustomer $customer, User $user): ?string
    {
        $next = $this->nextStage($customer->stage);

        return $this->canMoveForward($user) && $next && ! in_array($next, CrmCustomer::CLOSED_STAGES, true)
            ? $next
            : null;
    }

    /** Các bước Admin có thể lùi về (rỗng nếu lead đã chốt / thất bại). */
    public function backwardTargets(CrmCustomer $customer, User $user): array
    {
        if (! $this->canMoveBackward($user) || $customer->isClosed() || $customer->stage === CrmCustomer::STAGE_LOST) {
            return [];
        }
        $index = $this->indexOf($customer->stage) ?? 0;

        return array_slice(array_keys(CrmCustomer::PIPELINE_STAGES), 0, $index);
    }

    /** CM chuyển tiến từng bước (lead.stage_forward — mặc định Admin, Quản lý cơ sở, Học vụ). */
    public function canMoveForward(User $user): bool
    {
        return $user->can('lead.stage_forward');
    }

    /** Lùi giai đoạn (lead.stage_back — A6 Q1: mặc định chỉ Admin). */
    public function canMoveBackward(User $user): bool
    {
        return $user->can('lead.stage_back');
    }

    private function indexOf(?string $stage): ?int
    {
        $index = array_search($stage, array_keys(CrmCustomer::PIPELINE_STAGES), true);

        return $index === false ? null : $index;
    }

    private function apply(CrmCustomer $customer, string $stage, ?User $actor, ?string $reason, array $extra = []): void
    {
        $from = $customer->stage;
        $customer->update(['stage' => $stage] + $extra);

        CrmCustomerHistory::create([
            'customer_id' => $customer->id,
            'user_id' => $actor?->id,
            'type' => 'stage_change',
            'from_stage' => $from,
            'to_stage' => $stage,
            'reason' => $reason,
            'content' => 'Chuyển giai đoạn: '.CrmCustomer::stageLabel($from).' → '.CrmCustomer::stageLabel($stage)
                .($reason ? ". Lý do: {$reason}" : '.'),
        ]);
    }
}
