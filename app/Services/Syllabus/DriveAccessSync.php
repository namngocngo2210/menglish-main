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
        $result = ['granted' => 0, 'revoked' => 0, 'failed' => 0];
        if (! $this->configured()) {
            return $result;
        }
        $desired = $this->desired();
        $existing = SyllabusDriveGrant::all()->keyBy(fn (SyllabusDriveGrant $g) => SyllabusDriveGrant::key($g->email, $g->file_id));

        foreach ($desired as $key => $row) {
            $this->grant($existing->get($key), $row, $result);
        }
        foreach ($existing as $key => $grant) {
            if (! $desired->has($key)) {
                $this->release($grant, $result);
            }
        }

        return $result;
    }

    /** "Đồng bộ lại" của 1 lớp: thử lại ngay các quyền của chặng lớp đang học (kể cả quyền đã lỗi đủ số lần). */
    public function syncAssignment(SyllabusAssignment $assignment): array
    {
        $result = ['granted' => 0, 'revoked' => 0, 'failed' => 0];
        if (! $this->configured()) {
            return $result;
        }
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
            $existing = collect($this->drive->permissions($row['file_id']))
                ->first(fn (array $p) => mb_strtolower((string) $p['emailAddress']) === $row['email']);
            if ($existing) {
                $grant->fill(['pre_existing' => true, 'permission_id' => $existing['id']]);
            } else {
                $grant->fill(['pre_existing' => false, 'permission_id' => $this->drive->grantReader($row['file_id'], $row['email'])]);
                if ($row['kind'] === 'file' && ! isset($this->restricted[$row['file_id']])) {
                    $this->drive->restrictCopy($row['file_id']);
                    $this->restricted[$row['file_id']] = true;
                }
            }
            $grant->fill(['status' => SyllabusDriveGrant::STATUS_GRANTED, 'error' => null, 'attempts' => 0, 'granted_at' => now(), 'revoked_at' => null])->save();
            $result['granted']++;
        } catch (DriveException $e) {
            $grant->fill(['status' => SyllabusDriveGrant::STATUS_FAILED, 'error' => $e->getMessage(), 'attempts' => $grant->attempts + 1])->save();
            $result['failed']++;
            if (! $grant->failure_notified_at) {
                $grant->update(['failure_notified_at' => now()]);
                AdminNotification::notifyUsers(NotificationService::userIdsWithPermission('syllabus.assign'), SyllabusNotifier::TYPE,
                    "Không mở được quyền xem Google Drive cho {$row['user']}",
                    "{$row['email']} · {$row['url']} — {$e->getMessage()} Kiểm tra file đã chia sẻ quyền Chỉnh sửa cho service account và email là tài khoản Google.");
            }
        }
    }

    private function release(SyllabusDriveGrant $grant, array &$result): void
    {
        if ($grant->status !== SyllabusDriveGrant::STATUS_GRANTED || $grant->pre_existing) {
            // Quyền có từ trước / chưa từng mở được: chỉ xoá theo dõi, không đụng tới Drive.
            if ($grant->status !== SyllabusDriveGrant::STATUS_REVOKED) {
                $grant->delete();
            }

            return;
        }
        try {
            if ($grant->permission_id) {
                $this->drive->revoke($grant->file_id, $grant->permission_id);
            }
            $grant->update(['status' => SyllabusDriveGrant::STATUS_REVOKED, 'permission_id' => null, 'revoked_at' => now(), 'error' => null]);
            $result['revoked']++;
        } catch (DriveException $e) {
            $grant->update(['error' => 'Chưa thu hồi được: '.$e->getMessage()]);
            $result['failed']++;
        }
    }
}
