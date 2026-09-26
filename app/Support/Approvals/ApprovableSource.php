<?php

namespace App\Support\Approvals;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Một nguồn việc chờ duyệt của hộp "Việc cần duyệt" (IX-5). Mỗi module tự cài đặt adapter cạnh module của mình
 * (vd. App\Services\Tuition\Approvals\ReceiptApprovalSource) và đăng ký ở App\Providers\ApprovalServiceProvider.
 * Inbox chỉ đọc qua interface này, không biết nghiệp vụ của module.
 *
 * Quy tắc:
 *  - Truy vấn phạm vi (chi nhánh / vai trò) phải giống hệt màn duyệt gốc của module.
 *  - approve() / reject() CHỈ uỷ quyền cho logic sẵn có của module (service hoặc action controller qua
 *    ControllerActionInvoker) — không viết lại luật nghiệp vụ. Nguồn cần nhập thêm thông tin khi duyệt
 *    (ảnh chứng từ, link đề…) trả supports() = false: inbox chỉ liệt kê + link sang màn gốc.
 */
interface ApprovableSource
{
    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    public const GROUP_TUITION = 'Học phí';

    public const GROUP_ACADEMIC = 'Đào tạo';

    public const GROUP_WORK = 'Công việc';

    /** Khoá ổn định (dùng trong URL / checkbox: "<key>:<id>"). */
    public function key(): string;

    public function label(): string;

    /** Nhóm hiển thị trên chip lọc (GROUP_*). */
    public function group(): string;

    /** Màn duyệt gốc của module ("Xem tất cả"). */
    public function indexUrl(): string;

    /** User được duyệt nguồn này (không phải chỉ được xem màn gốc). Không truy vấn DB ngoài Gate. */
    public function canView(User $user): bool;

    public function count(User $user): int;

    /** @return Collection<int, ApprovalItem> mới nhất trước */
    public function pending(User $user, int $limit): Collection;

    /** Một mục đang chờ trong phạm vi của user (null nếu không thấy / đã xử lý). */
    public function find(User $user, int $id): ?ApprovalItem;

    /** User được duyệt / từ chối hàng loạt ngay trong inbox không (APPROVE | REJECT). */
    public function supports(User $user, string $action): bool;

    public function approve(User $user, int $id): ApprovalResult;

    public function reject(User $user, int $id, string $reason): ApprovalResult;

    /**
     * Model mà khi lưu / xoá thì số đếm có thể đổi → xoá cache badge.
     *
     * @return list<class-string<Model>>
     */
    public function watchedModels(): array;
}
