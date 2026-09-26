<?php

namespace App\Support\Approvals;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Nền cho nguồn duyệt dựa trên 1 truy vấn Eloquent: adapter chỉ cần khai báo truy vấn phạm vi (giống màn gốc),
 * quan hệ cần eager load và cách dựng dòng hiển thị. Mặc định không hỗ trợ duyệt trong inbox (chỉ link màn gốc).
 */
abstract class QueryApprovalSource implements ApprovableSource
{
    /** Mục đang chờ user duyệt, đúng phạm vi màn gốc (chưa eager load). */
    abstract protected function query(User $user): Builder;

    abstract protected function toItem(Model $model): ApprovalItem;

    /** @return array<int|string, mixed> quan hệ cần cho toItem() */
    protected function with(): array
    {
        return [];
    }

    public function count(User $user): int
    {
        return $this->query($user)->count();
    }

    public function pending(User $user, int $limit): Collection
    {
        $query = $this->query($user);

        return $query->with($this->with())
            ->latest($query->getModel()->qualifyColumn('created_at'))
            ->latest($query->getModel()->getQualifiedKeyName())
            ->limit($limit)
            ->get()
            ->map(fn (Model $model) => $this->toItem($model))
            ->values();
    }

    public function find(User $user, int $id): ?ApprovalItem
    {
        $model = $this->query($user)->with($this->with())->whereKey($id)->first();

        return $model ? $this->toItem($model) : null;
    }

    public function supports(User $user, string $action): bool
    {
        return false;
    }

    public function approve(User $user, int $id): ApprovalResult
    {
        return ApprovalResult::failure('Mục này cần duyệt ở màn gốc (cần nhập thêm thông tin).');
    }

    public function reject(User $user, int $id, string $reason): ApprovalResult
    {
        return ApprovalResult::failure('Mục này cần xử lý ở màn gốc.');
    }

    /** User thỏa mọi ability. */
    protected static function allows(User $user, string ...$abilities): bool
    {
        foreach ($abilities as $ability) {
            if (! $user->can($ability)) {
                return false;
            }
        }

        return true;
    }

    protected static function limit(?string $text, int $length = 90): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : Str::limit(preg_replace('/\s+/', ' ', $text), $length);
    }
}
