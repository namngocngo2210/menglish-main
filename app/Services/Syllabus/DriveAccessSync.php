<?php

namespace App\Services\Syllabus;

use App\Models\AdminNotification;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusDriveGrant;
use App\Models\User;
use App\Services\GoogleDrive\DriveClient;
use App\Services\GoogleDrive\DriveException;
use App\Services\GoogleDrive\DriveLinks;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Đồng bộ quyền xem Google Drive theo chặng lớp đang học (docs/kho-syllabus.md §7.4) kiểu đối chiếu: tập (email, file) cần
 * có = link Drive trong syllabus hiệu lực của mọi chặng đang mở × (GV được giao + GV chính + GVNN) → tạo quyền thiếu, thu hồi
 * quyền thừa. Người đã có quyền từ trước không bao giờ bị thu hồi. Idempotent; chạy bằng lệnh syllabus:sync-drive-access.
 */
class DriveAccessSync
{
    /** Trường link của buổi được đọc để mở quyền Drive. */
    private const LINK_FIELDS = ['ppt_url', 'ppt_foreign_url', 'quizizz_url', 'test_url'];

    /** @var array<string, true> file đã chặn tải / in / sao chép trong lần chạy này */
    private array $restricted = [];

    /** @var list<array{0: SyllabusDriveGrant, 1: array}> quyền lỗi lần đầu trong lượt chạy (báo gộp) */
    private array $failures = [];

    public function __construct(private readonly DriveClient $drive, private readonly ClassSyllabus $syllabus) {}

    public function configured(): bool
    {
        return $this->drive->configured();
    }

    /** @return Collection<string, array{email: string, file_id: string, kind: string, url: string, user: string}> khóa email|file */
    public function desired(): Collection
    {
        return SyllabusAssignment::open()->with(['classModel', 'stage'])->orderBy('id')->get()
            ->flatMap(fn (SyllabusAssignment $assignment) => $this->desiredFor($assignment))
            ->unique(fn (array $row, string $key) => $key);
    }

    /** @return Collection<string, array{email: string, file_id: string, kind: string, url: string, user: string}> */
    public function desiredFor(SyllabusAssignment $assignment): Collection
    {
        $class = $assignment->classModel;
        if (! $class) {
            return collect();
        }
        $users = User::whereIn('id', array_filter([$assignment->user_id, $class->teacher_id, $class->foreign_teacher_id]))
            ->where('is_active', true)->whereNotNull('email')->get(['id', 'name', 'email']);
        $urls = $this->syllabus->lessons($assignment)
            ->flatMap(fn (array $row) => array_values(array_intersect_key($row['data'], array_flip(self::LINK_FIELDS))))
            ->push($assignment->stage?->overview_link);
        $files = $urls->map(fn (?string $url) => ($file = DriveLinks::parse($url)) ? $file + ['url' => $url] : null)
            ->filter()->unique('id');

        $rows = collect();
        foreach ($users as $user) {
            foreach ($files as $file) {
                $rows->put(SyllabusDriveGrant::key($user->email, $file['id']),
                    ['email' => mb_strtolower($user->email), 'file_id' => $file['id'], 'kind' => $file['kind'], 'url' => $file['url'], 'user' => $user->name]);
            }
        }

        return $rows;
    }

    /** @return array{granted: int, revoked: int, failed: int} */
    public function sync(): array
    {
        return $this->locked(function () {
            $result = ['granted' => 0, 'revoked' => 0, 'failed' => 0];
            $desired = $this->desired();
            $existing = SyllabusDriveGrant::where('status', '!=', SyllabusDriveGrant::STATUS_REVOKED)
                ->orWhereIn('file_id', $desired->pluck('file_id')->unique())->get()
                ->keyBy(fn (SyllabusDriveGrant $g) => SyllabusDriveGrant::key($g->email, $g->file_id));

            foreach ($desired as $key => $row) {
                $this->grant($existing->get($key), $row, $result);
            }
            foreach ($existing as $key => $grant) {
                if (! $desired->has($key)) {
                    $this->release($grant, $result);
                }
            }

            return $result;
        });
    }

    /** "Đồng bộ lại" của 1 lớp: thử lại ngay các quyền của chặng lớp đang học (kể cả quyền đã lỗi đủ số lần). */
    public function syncAssignment(SyllabusAssignment $assignment): array
    {
        return $this->locked(function () use ($assignment) {
            $result = ['granted' => 0, 'revoked' => 0, 'failed' => 0];
            $desired = $this->desiredFor($assignment);
            $existing = SyllabusDriveGrant::whereIn('file_id', $desired->pluck('file_id')->unique())->get()
                ->keyBy(fn (SyllabusDriveGrant $g) => SyllabusDriveGrant::key($g->email, $g->file_id));
            foreach ($desired as $key => $row) {
                $grant = $existing->get($key);
                if ($grant?->status === SyllabusDriveGrant::STATUS_FAILED) {
                    $grant->update(['attempts' => 0]);
                }
                $this->grant($grant, $row, $result);
            }

            return $result;
        });
    }

    /**
     * Một lượt đồng bộ tại một thời điểm (lệnh mỗi phút và nút "Đồng bộ lại" không giẫm nhau). Lấy token trước: khóa / mạng /
     * Google lỗi thì dừng lượt, báo Học thuật 1 lần mỗi 6 giờ thay vì báo theo từng quyền.
     */
    private function locked(callable $run): array
    {
        $empty = ['granted' => 0, 'revoked' => 0, 'failed' => 0];
        if (! $this->configured()) {
            return $empty;
        }

        return Cache::lock('syllabus:drive-sync:lock', 300)->get(function () use ($run, $empty) {
            try {
                $this->drive->ensureToken();
            } catch (DriveException $e) {
                if (Cache::add('syllabus:drive-sync:token-alert', true, now()->addHours(6))) {
                    AdminNotification::notifyUsers(NotificationService::userIdsWithPermission('syllabus.assign'), SyllabusNotifier::TYPE,
                        'Không kết nối được Google Drive — chưa mở / đóng được quyền xem file syllabus',
                        $e->getMessage().' Kiểm tra khóa service account (GOOGLE_DRIVE_SA_KEY_PATH) và Drive API.');
                }

                return $empty + ['error' => $e->getMessage()];
            }
            $this->failures = [];
            $result = $run();
            $this->notifyFailures();

            return $result;
        }) ?? $empty;
    }

    private function grant(?SyllabusDriveGrant $grant, array $row, array &$result): void
    {
        if ($grant?->status === SyllabusDriveGrant::STATUS_GRANTED) {
            return;
        }
        if ($grant?->status === SyllabusDriveGrant::STATUS_FAILED && $grant->attempts >= SyllabusDriveGrant::MAX_ATTEMPTS) {
            return;
        }
        $grant ??= new SyllabusDriveGrant(['email' => $row['email'], 'file_id' => $row['file_id']]);
        $grant->file_kind = $row['kind'];

        try {
            if (! $grant->permission_id) {
                $found = collect($this->drive->permissions($row['file_id']))
                    ->first(fn (array $p) => mb_strtolower((string) $p['emailAddress']) === $row['email']);
                if ($found) {
                    // Chưa từng gửi yêu cầu tạo quyền → quyền có từ trước (không bao giờ thu hồi); đã gửi (phản hồi lỗi /
                    // timeout) → chính hệ thống đã tạo.
                    $grant->fill(['pre_existing' => $grant->grant_requested_at === null, 'permission_id' => $found['id']]);
                } else {
                    $grant->fill(['pre_existing' => false, 'grant_requested_at' => now()])->save();
                    $grant->permission_id = $this->drive->grantReader($row['file_id'], $row['email']);
                }
            }
            $grant->fill(['status' => SyllabusDriveGrant::STATUS_GRANTED, 'error' => null, 'attempts' => 0, 'granted_at' => now(),
                'revoked_at' => null, 'failure_notified_at' => null])->save();
            $result['granted']++;
        } catch (DriveException $e) {
            $grant->fill(['status' => SyllabusDriveGrant::STATUS_FAILED, 'error' => $e->getMessage(), 'attempts' => $grant->attempts + 1])->save();
            $result['failed']++;
            if (! $grant->failure_notified_at) {
                $this->failures[] = [$grant, $row];
            }

            return;
        }

        // Chặn tải / in / sao chép: lỗi không làm mất quyền đã mở (quyền vẫn được theo dõi để thu hồi).
        if ($grant->file_kind === 'file' && ! $grant->pre_existing && ! isset($this->restricted[$row['file_id']])) {
            try {
                $this->drive->restrictCopy($row['file_id']);
                $this->restricted[$row['file_id']] = true;
            } catch (DriveException $e) {
                $grant->update(['error' => 'Chưa chặn tải / in / sao chép: '.$e->getMessage()]);
            }
        }
    }

    /** Thu hồi quyền hệ thống đã tạo (mọi trạng thái); quyền có từ trước / chưa từng tạo được: chỉ bỏ theo dõi. */
    private function release(SyllabusDriveGrant $grant, array &$result): void
    {
        if ($grant->status === SyllabusDriveGrant::STATUS_REVOKED) {
            return;
        }
        if ($grant->pre_existing || ! $grant->permission_id) {
            $grant->delete();

            return;
        }
        try {
            $this->drive->revoke($grant->file_id, $grant->permission_id);
            $grant->update(['status' => SyllabusDriveGrant::STATUS_REVOKED, 'permission_id' => null, 'grant_requested_at' => null,
                'revoked_at' => now(), 'error' => null]);
            $result['revoked']++;
        } catch (DriveException $e) {
            $grant->update(['error' => 'Chưa thu hồi được: '.$e->getMessage()]);
            $result['failed']++;
        }
    }

    /** Gộp các quyền lỗi lần đầu trong lượt chạy thành 1 thông báo cho Học thuật. */
    private function notifyFailures(): void
    {
        if ($this->failures === []) {
            return;
        }
        $lines = collect($this->failures)->take(5)
            ->map(fn (array $f) => "{$f[1]['user']} ({$f[1]['email']}) · {$f[1]['url']} — {$f[0]->error}")->implode("\n");
        $more = count($this->failures) > 5 ? "\n… và ".(count($this->failures) - 5).' quyền khác.' : '';
        AdminNotification::notifyUsers(NotificationService::userIdsWithPermission('syllabus.assign'), SyllabusNotifier::TYPE,
            'Không mở được '.count($this->failures).' quyền xem Google Drive',
            $lines.$more."\nKiểm tra file đã chia sẻ quyền Chỉnh sửa cho service account và email GV là tài khoản Google.");
        SyllabusDriveGrant::whereKey(collect($this->failures)->map(fn (array $f) => $f[0]->id))->update(['failure_notified_at' => now()]);
        $this->failures = [];
    }
}
