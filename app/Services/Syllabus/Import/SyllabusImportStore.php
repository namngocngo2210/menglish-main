<?php

namespace App\Services\Syllabus\Import;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * File tạm của gói import giáo trình giữa bước "xem trước" và "xác nhận".
 * Ghi thẳng bằng storage_path() (không qua disk `local` vì disk này chuyển sang R2 khi UPLOAD_STORAGE=r2);
 * phiên (session) của request hiện tại giữ token → đường dẫn, người upload, thời điểm, quyền tài liệu.
 */
class SyllabusImportStore
{
    /** Phiên kiểm tra hết hạn sau số phút này. */
    public const TTL_MINUTES = 120;

    private const EXTENSIONS = ['zip', 'xlsx'];

    public static function root(): string
    {
        return storage_path('app/private/syllabus-imports');
    }

    /**
     * Lưu file upload, trả token (uuid).
     *
     * @param  array{visible_to_teachers: bool, visible_to_assistants: bool, downloadable: bool}  $visibility
     */
    /** @param  array<string, mixed>  $context  dữ liệu kèm phiên (vd. import chặng: curriculum_id, stage_id) */
    public function put(UploadedFile $file, User $user, array $visibility, array $context = []): string
    {
        $token = (string) Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, self::EXTENSIONS, true)) {
            $extension = 'zip';
        }

        File::ensureDirectoryExists(self::root());
        $path = self::root()."/{$token}.{$extension}";
        File::copy($file->getRealPath(), $path);

        session([self::key($token) => [
            'path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'user_id' => $user->id,
            'created_at' => now()->timestamp,
            'visibility' => $visibility,
            'context' => $context,
        ]]);

        return $token;
    }

    /** Meta của phiên import kèm `work_dir`; null khi không có / khác người upload / hết hạn / file đã mất. */
    public function get(string $token, User $user): ?array
    {
        if (! Str::isUuid($token)) {
            return null;
        }
        $meta = session(self::key($token));
        if (! is_array($meta) || ($meta['user_id'] ?? null) !== $user->id) {
            return null;
        }
        if (now()->timestamp - (int) ($meta['created_at'] ?? 0) > self::TTL_MINUTES * 60) {
            return null;
        }
        // Đường dẫn dựng lại từ token (không tin đường dẫn trong phiên).
        $path = $this->pathFor($token);
        if ($path === null) {
            return null;
        }

        return ['path' => $path, 'work_dir' => self::root()."/{$token}"] + $meta + ['context' => []];
    }

    /** Xoá file, thư mục giải nén và khoá phiên của token. */
    public function forget(string $token): void
    {
        $this->deleteFiles($token);
        $this->release($token);
    }

    /** Bỏ khoá phiên (không xác nhận lại được nữa) nhưng giữ file: job import chạy nền còn đọc, xong tự xoá (deleteFiles). */
    public function release(string $token): void
    {
        session()->forget(self::key($token));
    }

    /** Xoá file và thư mục giải nén của token (không cần phiên — dùng được trong job). */
    public function deleteFiles(string $token): void
    {
        if (! Str::isUuid($token)) {
            return;
        }
        foreach (self::EXTENSIONS as $extension) {
            File::delete(self::root()."/{$token}.{$extension}");
        }
        File::deleteDirectory(self::root()."/{$token}");
    }

    /** Xoá mọi file / thư mục trong root có mtime < $before (gồm cả zip mẫu mồ côi). Trả số mục đã xoá. */
    public function prune(DateTimeInterface $before): int
    {
        if (! File::isDirectory(self::root())) {
            return 0;
        }
        $cutoff = $before->getTimestamp();
        $removed = 0;
        foreach (File::glob(self::root().'/*') as $entry) {
            if (filemtime($entry) >= $cutoff) {
                continue;
            }
            $ok = is_dir($entry) && ! is_link($entry) ? File::deleteDirectory($entry) : File::delete($entry);
            $removed += $ok ? 1 : 0;
        }

        return $removed;
    }

    private function pathFor(string $token): ?string
    {
        foreach (self::EXTENSIONS as $extension) {
            $path = self::root()."/{$token}.{$extension}";
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private static function key(string $token): string
    {
        return "syllabus_import.{$token}";
    }
}
