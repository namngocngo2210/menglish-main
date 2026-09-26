<?php

namespace App\Support\Approvals;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use WeakMap;

/**
 * Hộp "Việc cần duyệt": gom các ApprovableSource user được duyệt (đăng ký ở ApprovalServiceProvider).
 *
 * Số đếm (badge sidebar + chip lọc):
 *  - Cache 60s theo user, kèm "phiên bản" chung; đọc bằng 1 lệnh Cache::many (1 truy vấn với cache database).
 *  - Lưu / xoá model của nguồn bất kỳ (observer ở provider) hoặc duyệt qua inbox → đổi phiên bản → mọi user tính lại
 *    ở lần đọc sau. Đổi quyền → danh sách nguồn khác → tính lại.
 *  - Nhớ theo request (WeakMap theo Request) nên sidebar + trang inbox chỉ đọc cache 1 lần.
 */
final class ApprovalInboxService
{
    public const CACHE_TTL = 60;

    /** Số mục tối đa mỗi lần duyệt / từ chối hàng loạt. */
    public const MAX_BULK = 100;

    private const VERSION_KEY = 'approvals:version';

    /** Thứ tự nhóm trên chip lọc. */
    private const GROUP_ORDER = [ApprovableSource::GROUP_TUITION, ApprovableSource::GROUP_ACADEMIC, ApprovableSource::GROUP_WORK];

    /** @var array<string, ApprovableSource> */
    private array $sources = [];

    /** @var WeakMap<object, array<string, mixed>> */
    private WeakMap $memo;

    /** Lúc đổi phiên bản gần nhất mà process này chưa tính lại số đếm (0 = đã tính lại). */
    private float $staleSince = 0.0;

    /**
     * @param  iterable<ApprovableSource>  $sources
     */
    public function __construct(iterable $sources)
    {
        foreach ($sources as $source) {
            $this->sources[$source->key()] = $source;
        }
        $this->memo = new WeakMap;
    }

    /** @return array<string, ApprovableSource> */
    public function sources(): array
    {
        return $this->sources;
    }

    /** @return array<string, ApprovableSource> nguồn user được duyệt (chỉ hỏi Gate) */
    public function visibleSources(User $user): array
    {
        return $this->remember($user, 'sources', fn () => array_filter($this->sources, fn (ApprovableSource $s) => $s->canView($user)));
    }

    public function canView(?User $user): bool
    {
        return $user !== null && $this->visibleSources($user) !== [];
    }

    /** @return array<string, int> số mục chờ theo nguồn (đã cache) */
    public function counts(User $user): array
    {
        return $this->remember($user, 'counts', function () use ($user) {
            $visible = $this->visibleSources($user);
            if ($visible === []) {
                return [];
            }

            $key = 'approvals:counts:'.$user->getKey();
            $cached = Cache::many([self::VERSION_KEY, $key]);
            $version = $cached[self::VERSION_KEY] ?? null;
            $entry = $cached[$key] ?? null;
            if (is_array($entry) && ($entry['v'] ?? null) === $version && array_keys($entry['counts'] ?? []) === array_keys($visible)) {
                return $entry['counts'];
            }

            $counts = array_map(fn (ApprovableSource $source) => $source->count($user), $visible);
            Cache::put($key, ['v' => $version, 'counts' => $counts], self::CACHE_TTL);
            $this->staleSince = 0.0;

            return $counts;
        });
    }

    /** Số hiện trên badge sidebar; null = không hiện mục "Việc cần duyệt". */
    public function badge(?User $user): ?int
    {
        return $this->canView($user) ? array_sum($this->counts($user)) : null;
    }

    /**
     * Chip lọc: slug => [label, count], theo thứ tự nhóm cố định.
     *
     * @return array<string, array{label: string, count: int}>
     */
    public function groups(User $user): array
    {
        $counts = $this->counts($user);
        $groups = [];
        foreach ($this->visibleSources($user) as $key => $source) {
            $slug = self::groupSlug($source->group());
            $groups[$slug] ??= ['label' => $source->group(), 'count' => 0];
            $groups[$slug]['count'] += $counts[$key] ?? 0;
        }
        uksort($groups, fn (string $a, string $b) => self::groupRank($groups[$a]['label']) <=> self::groupRank($groups[$b]['label']));

        return $groups;
    }

    /**
     * Danh sách theo nguồn (nguồn có mục chờ lên trước), giới hạn $limit mục / nguồn.
     *
     * @return list<array{source: ApprovableSource, count: int, items: Collection<int, ApprovalItem>, approve: bool, reject: bool}>
     */
    public function sections(User $user, ?string $group, int $limit): array
    {
        $counts = $this->counts($user);
        $sections = [];
        foreach ($this->visibleSources($user) as $key => $source) {
            if ($group !== null && self::groupSlug($source->group()) !== $group) {
                continue;
            }
            $count = $counts[$key] ?? 0;
            $sections[] = [
                'source' => $source,
                'count' => $count,
                'items' => $count > 0 ? $source->pending($user, $limit) : collect(),
                'approve' => $source->supports($user, ApprovableSource::APPROVE),
                'reject' => $source->supports($user, ApprovableSource::REJECT),
            ];
        }
        usort($sections, fn (array $a, array $b) => [self::groupRank($a['source']->group()), $a['count'] === 0]
            <=> [self::groupRank($b['source']->group()), $b['count'] === 0]);

        return $sections;
    }

    /** @return array{0: ApprovableSource, 1: ApprovalItem}|null */
    public function find(User $user, string $key, int $id): ?array
    {
        $source = $this->visibleSources($user)[$key] ?? null;
        $item = $source?->find($user, $id);

        return $item ? [$source, $item] : null;
    }

    /**
     * Duyệt / từ chối từng mục, mỗi mục một transaction riêng: mục lỗi không ảnh hưởng mục khác.
     *
     * @param  list<string>  $refs  "<source>:<id>"
     * @return list<array{ref: string, title: string, ok: bool, message: string}>
     */
    public function process(User $user, string $action, array $refs, ?string $reason = null): array
    {
        $results = [];
        foreach (array_slice(array_values(array_unique($refs)), 0, self::MAX_BULK) as $ref) {
            [$key, $id] = array_pad(explode(':', (string) $ref, 2), 2, '');
            $source = $this->visibleSources($user)[$key] ?? null;
            $item = $source && ctype_digit($id) ? $source->find($user, (int) $id) : null;

            if (! $item) {
                $results[] = ['ref' => (string) $ref, 'title' => (string) $ref, 'ok' => false, 'message' => 'Mục không còn chờ duyệt hoặc ngoài phạm vi của bạn.'];

                continue;
            }
            if (! $source->supports($user, $action)) {
                $results[] = ['ref' => $item->ref(), 'title' => $item->title, 'ok' => false, 'message' => 'Mục này cần xử lý ở màn gốc.'];

                continue;
            }

            $results[] = ['ref' => $item->ref(), 'title' => $item->title] + $this->runOne($user, $source, $action, $item->id, (string) $reason);
        }

        $this->staleSince = 0.0; // luôn ghi: kết quả ngay sau đây phải thấy số mới
        $this->invalidate();

        return $results;
    }

    /**
     * Đổi phiên bản số đếm → mọi user tính lại ở lần đọc sau. Lưu hàng loạt (nhập Excel…) trong 1 giây chỉ ghi cache
     * một lần; trễ tối đa vẫn là CACHE_TTL.
     */
    public function invalidate(): void
    {
        $this->memo = new WeakMap;
        $now = microtime(true);
        if ($this->staleSince > 0 && $now - $this->staleSince < 1.0) {
            return;
        }
        Cache::forever(self::VERSION_KEY, Str::random(16));
        $this->staleSince = $now;
    }

    public static function groupSlug(string $group): string
    {
        return Str::slug($group);
    }

    /** @return array{ok: bool, message: string} */
    private function runOne(User $user, ApprovableSource $source, string $action, int $id, string $reason): array
    {
        DB::beginTransaction();
        try {
            $result = $action === ApprovableSource::APPROVE
                ? $source->approve($user, $id)
                : $source->reject($user, $id, $reason);
            $result->ok ? DB::commit() : DB::rollBack();
        } catch (Throwable $e) {
            DB::rollBack();
            report($e);
            $result = ApprovalResult::failure('Có lỗi hệ thống khi xử lý mục này, vui lòng thử lại ở màn gốc.');
        }

        return ['ok' => $result->ok, 'message' => $result->message];
    }

    private static function groupRank(string $group): int
    {
        $rank = array_search($group, self::GROUP_ORDER, true);

        return $rank === false ? count(self::GROUP_ORDER) : $rank;
    }

    /**
     * Nhớ kết quả theo request hiện tại + user.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function remember(User $user, string $name, callable $callback): mixed
    {
        $request = request();
        $bucket = $this->memo[$request] ?? [];
        $key = $name.'|'.$user->getKey();
        if (! array_key_exists($key, $bucket)) {
            $bucket[$key] = $callback();
            $this->memo[$request] = $bucket;
        }

        return $bucket[$key];
    }
}
