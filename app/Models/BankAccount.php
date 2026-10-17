<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection as SupportCollection;

class BankAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'bank_accounts';

    /** Loại tài khoản (mockup): Công ty — chủ sở hữu chính; Khác — cá nhân / đại diện. */
    public const TYPES = [
        'company' => 'Công ty',
        'other' => 'Khác',
    ];

    protected $fillable = [
        'account_type',
        'bank_code',
        'bank_name',
        'account_number',
        'account_holder',
        'branch_location',
        'is_default_vietqr',
        'is_active',
    ];

    protected $casts = [
        'is_default_vietqr' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->account_type] ?? self::TYPES['company'];
    }

    /** Ảnh VietQR xem trước (không kèm số tiền) cho màn cấu hình tài khoản. */
    public function getVietqrPreviewUrlAttribute(): ?string
    {
        if (! $this->bank_code || ! $this->account_number) {
            return null;
        }

        return 'https://img.vietqr.io/image/'.rawurlencode($this->bank_code).'-'.rawurlencode($this->account_number)
            .'-compact2.png?accountName='.rawurlencode((string) $this->account_holder);
    }

    /**
     * Cơ sở dùng tài khoản này; không gắn cơ sở nào = tài khoản dùng chung cho mọi cơ sở.
     * Gồm cả cơ sở đã xoá để tài khoản riêng của cơ sở đó không bị coi là dùng chung.
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'bank_account_branch')->withTimestamps()->withTrashed();
    }

    public function isShared(): bool
    {
        return $this->branches->isEmpty();
    }

    /** Tài khoản dùng được cho cơ sở: tài khoản dùng chung hoặc đã gắn cơ sở đó. Chưa rõ cơ sở thì dùng được mọi tài khoản. */
    public function appliesToBranch(?int $branchId): bool
    {
        return ! $branchId || $this->isShared() || $this->branches->contains('id', $branchId);
    }

    /** Tài khoản đang hoạt động (kèm cơ sở) để resolveFor() chọn trong bộ nhớ. */
    public static function activeForResolve(): Collection
    {
        return static::query()->with('branches')->where('is_active', true)->get();
    }

    /**
     * Tài khoản nhận tiền cho một cơ sở, chọn trong danh sách tài khoản đang hoạt động:
     * tài khoản chỉ định (nếu dùng được cho cơ sở) → tài khoản gắn cơ sở → tài khoản dùng chung.
     * Trong mỗi nhóm ưu tiên cờ mặc định VietQR rồi tài khoản tạo trước. Không lấy tài khoản của cơ sở khác.
     * Chưa rõ cơ sở thì lấy tài khoản mặc định toàn hệ thống.
     */
    public static function resolveFor(?int $branchId, ?int $preferredId = null, ?SupportCollection $activeAccounts = null): ?self
    {
        $accounts = $activeAccounts ?? static::activeForResolve();

        if ($preferredId && ($own = $accounts->firstWhere('id', $preferredId)) && $own->appliesToBranch($branchId)) {
            return $own;
        }

        $byDefault = fn ($a, $b) => (int) $b->is_default_vietqr <=> (int) $a->is_default_vietqr;
        $byId = fn ($a, $b) => $a->id <=> $b->id;

        if (! $branchId) {
            return $accounts->sortBy([$byDefault, fn ($a, $b) => (int) ! $a->isShared() <=> (int) ! $b->isShared(), $byId])->first();
        }

        return $accounts->filter(fn (self $a) => $a->branches->contains('id', $branchId))->sortBy([$byDefault, $byId])->first()
            ?? $accounts->filter(fn (self $a) => $a->isShared())->sortBy([$byDefault, $byId])->first();
    }

    /** Tài khoản mặc định toàn hệ thống (đang hoạt động, ưu tiên cờ mặc định VietQR, rồi tài khoản dùng chung). */
    public static function defaultAccount(): ?self
    {
        return static::resolveFor(null);
    }

    /** Chuẩn hoá số tài khoản để so khớp (chỉ giữ chữ số và chữ cái, bỏ khoảng trắng/gạch). */
    public static function normalizeNumber(?string $number): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $number));
    }

    /**
     * Số tài khoản (hoặc tài khoản ảo) nhận tiền có thuộc một tài khoản ngân hàng đã cấu hình không.
     */
    public static function isConfiguredNumber(?string ...$numbers): bool
    {
        $wanted = collect($numbers)->map(fn ($n) => static::normalizeNumber($n))->filter()->values();
        if ($wanted->isEmpty()) {
            return false;
        }

        return static::query()->pluck('account_number')
            ->map(fn ($n) => static::normalizeNumber($n))
            ->intersect($wanted)
            ->isNotEmpty();
    }
}
