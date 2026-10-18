<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FileAttributes;

class MediaManagerService
{
    /**
     * Các đuôi file không bao giờ được ghi vào thư mục public dù nội dung thế nào
     * (chống polyglot upload: JPEG đặt tên .php vượt qua validate mimes: theo nội dung).
     */
    public const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar',
        'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'dll', 'so',
        'jar', 'html', 'htm', 'js', 'mjs', 'svg', 'htaccess', 'htpasswd',
    ];

    /**
     * Thư mục con (tính từ public/) do Media Manager sở hữu. Chỉ file trong thư mục này
     * mới được liệt kê/di chuyển/xóa. File của module khác (CV ứng viên, minh chứng công việc,
     * bài nộp, ghi âm phát âm, báo cáo lớp, học phí, ticket, tài liệu giáo trình...) nằm ngoài
     * vùng này nên Media Manager không thể đụng tới.
     */
    public const MEDIA_DIRECTORY = 'uploads/media';

    /** Disk chứa thư mục /uploads (máy chủ hoặc R2 khi UPLOAD_STORAGE=r2). */
    public const DISK = 'uploads';

    /** Thư mục gốc của Media Manager trên disk 'uploads'. */
    public const DISK_ROOT = 'media';

    protected function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /** Đường dẫn trên disk tới thư mục gốc Media Manager (có thể kèm thư mục con đã chuẩn hóa). */
    public function mediaRootPath(string $relative = ''): string
    {
        return self::DISK_ROOT.($relative !== '' ? '/'.$relative : '');
    }

    /**
     * Đuôi file an toàn suy ra từ NỘI DUNG file (finfo), bỏ hoàn toàn đuôi client gửi lên.
     * Trả về null nếu nội dung không đoán được loại hoặc đuôi bị cấm.
     */
    public static function safeExtension(UploadedFile $file): ?string
    {
        $guessed = strtolower((string) $file->guessExtension());

        if ($guessed === '' || in_array($guessed, self::BLOCKED_EXTENSIONS, true)) {
            return null;
        }

        return $guessed;
    }

    /**
     * Lấy toàn bộ danh sách file trong thư mục Media (một lần liệt kê, kèm dung lượng và thời gian).
     */
    public function getAllFiles(): Collection
    {
        return $this->listFiles(self::DISK_ROOT)
            ->map(fn (FileAttributes $file) => $this->buildFileMetadata($file->path(), $file->fileSize() ?? 0, $file->lastModified() ?? time()))
            ->values();
    }

    /** @return Collection<int, FileAttributes> */
    protected function listFiles(string $directory): Collection
    {
        return collect($this->disk()->listContents($directory, true))
            ->filter(fn ($item) => $item instanceof FileAttributes
                && ! in_array(basename($item->path()), ['.gitignore', '.DS_Store'], true));
    }

    /**
     * Xây dựng metadata cho từng file ($path tính từ gốc disk 'uploads', vd. media/2026/10/a.png)
     */
    protected function buildFileMetadata(string $path, int $size, int $mtime): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $createdAt = Carbon::createFromTimestamp($mtime);

        // Thư mục tính từ gốc Media Manager
        $relFromRoot = Str::after($path, self::DISK_ROOT.'/');
        $folder = trim(dirname($relFromRoot), '/');
        if ($folder === '.') {
            $folder = '';
        }

        $type = $this->classifyFileType($extension);

        return [
            'id' => base64_encode($path),
            'filename' => basename($path),
            'extension' => $extension ?: 'file',
            'type' => $type,
            'size' => $size,
            'size_human' => $this->formatBytes($size),
            'directory' => $folder,
            'path' => $path,
            'relative_path' => 'uploads/'.$path,
            'url' => $this->disk()->url($path),
            'created_at' => $createdAt,
            'created_at_human' => $createdAt->format('d/m/Y H:i'),
            'is_image' => $type === 'image',
        ];
    }

    /**
     * Phân loại loại tệp dựa vào đuôi mở rộng
     */
    public function classifyFileType(string $extension): string
    {
        $ext = strtolower($extension);

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'])) {
            return 'image';
        }
        if (in_array($ext, ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt'])) {
            return 'document';
        }
        if (in_array($ext, ['xls', 'xlsx', 'csv', 'ods'])) {
            return 'spreadsheet';
        }
        if (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm'])) {
            return 'video';
        }
        if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'])) {
            return 'audio';
        }
        if (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'])) {
            return 'archive';
        }

        return 'other';
    }

    /**
     * Lọc danh sách file theo bộ lọc người dùng
     */
    public function filterFiles(Collection $files, array $filters = []): Collection
    {
        $filtered = $files;

        // 1. Tìm kiếm theo tên file
        if (! empty($filters['search'])) {
            $search = mb_strtolower(trim($filters['search']));
            $filtered = $filtered->filter(function ($item) use ($search) {
                return str_contains(mb_strtolower($item['filename']), $search)
                    || str_contains(mb_strtolower($item['relative_path']), $search);
            });
        }

        // 2. Lọc theo Loại tệp (image, document, spreadsheet, video, other)
        if (! empty($filters['type']) && $filters['type'] !== 'all') {
            $filtered = $filtered->where('type', $filters['type']);
        }

        // 3. Lọc theo Thư mục
        if (! empty($filters['directory']) && $filters['directory'] !== 'all') {
            $filtered = $filtered->where('directory', $filters['directory']);
        }

        // 4. Lọc theo Kích thước dung lượng
        if (! empty($filters['size_range']) && $filters['size_range'] !== 'all') {
            $filtered = $filtered->filter(function ($item) use ($filters) {
                $size = $item['size'];

                return match ($filters['size_range']) {
                    'lt_1mb' => $size < 1048576, // < 1MB
                    '1mb_10mb' => $size >= 1048576 && $size <= 10485760, // 1MB - 10MB
                    'gt_10mb' => $size > 10485760, // > 10MB
                    default => true,
                };
            });
        }

        // 5. Lọc theo Khoảng thời gian
        if (! empty($filters['date_range']) && $filters['date_range'] !== 'all') {
            $now = Carbon::now();
            $filtered = $filtered->filter(function ($item) use ($filters, $now) {
                /** @var Carbon $created */
                $created = $item['created_at'];

                return match ($filters['date_range']) {
                    'today' => $created->isToday(),
                    'last_7_days' => $created->greaterThanOrEqualTo($now->copy()->subDays(7)->startOfDay()),
                    'last_30_days' => $created->greaterThanOrEqualTo($now->copy()->subDays(30)->startOfDay()),
                    'this_month' => $created->month === $now->month && $created->year === $now->year,
                    default => true,
                };
            });
        }

        // 6. Sắp xếp
        $sort = $filters['sort'] ?? 'latest';
        $filtered = match ($sort) {
            'oldest' => $filtered->sortBy('created_at'),
            'size_desc' => $filtered->sortByDesc('size'),
            'size_asc' => $filtered->sortBy('size'),
            'name_asc' => $filtered->sortBy('filename', SORT_NATURAL | SORT_FLAG_CASE),
            default => $filtered->sortByDesc('created_at'),
        };

        return $filtered->values();
    }

    /**
     * Phân trang danh sách Collection
     */
    public function paginate(Collection $items, int $perPage = 15, ?int $page = null, array $options = []): LengthAwarePaginator
    {
        $page = $page ?: (LengthAwarePaginator::resolveCurrentPage() ?: 1);
        $offset = ($page - 1) * $perPage;
        $sliced = $items->slice($offset, $perPage)->values();

        return new LengthAwarePaginator(
            $sliced,
            $items->count(),
            $perPage,
            $page,
            $options
        );
    }

    /**
     * Thống kê dung lượng lưu trữ trên đĩa
     */
    public function getStorageStats(Collection $allFiles): array
    {
        $totalBytes = $allFiles->sum('size');
        $totalCount = $allFiles->count();

        $images = $allFiles->where('type', 'image');
        $docs = $allFiles->whereIn('type', ['document', 'spreadsheet']);
        $media = $allFiles->whereIn('type', ['video', 'audio']);
        $others = $allFiles->whereNotIn('type', ['image', 'document', 'spreadsheet', 'video', 'audio']);

        $directories = $allFiles->pluck('directory')->unique()->values()->all();

        return [
            'total_files' => $totalCount,
            'total_size' => $totalBytes,
            'total_size_human' => $this->formatBytes($totalBytes),
            'images_count' => $images->count(),
            'images_size' => $this->formatBytes($images->sum('size')),
            'docs_count' => $docs->count(),
            'docs_size' => $this->formatBytes($docs->sum('size')),
            'media_count' => $media->count(),
            'media_size' => $this->formatBytes($media->sum('size')),
            'others_count' => $others->count(),
            'others_size' => $this->formatBytes($others->sum('size')),
            'directories' => $directories,
        ];
    }

    /**
     * Xóa vĩnh viễn 1 file
     */
    public function deletePhysicalFile(string $encodedId): array
    {
        $path = $this->resolveManagedFile($encodedId);

        if (! $path) {
            return [
                'success' => false,
                'message' => 'Tệp tin không tồn tại, đã bị xóa trước đó hoặc nằm ngoài thư mục được phép quản lý!',
            ];
        }

        $filename = basename($path);
        $size = $this->disk()->size($path);

        if ($this->disk()->delete($path)) {
            // Tự động dọn dẹp thư mục cha nếu rỗng
            $this->cleanEmptyDirectory(dirname($path));

            return [
                'success' => true,
                'message' => "Đã xóa vĩnh viễn tệp '{$filename}' ({$this->formatBytes($size)})!",
                'freed_bytes' => $size,
            ];
        }

        return [
            'success' => false,
            'message' => "Không thể xóa tệp tin '{$filename}'!",
        ];
    }

    /**
     * Xóa hàng loạt danh sách file trên đĩa
     */
    public function deleteMultipleFiles(array $encodedIds): array
    {
        $deletedCount = 0;
        $totalFreedBytes = 0;
        $failedCount = 0;

        foreach ($encodedIds as $id) {
            $result = $this->deletePhysicalFile($id);
            if ($result['success']) {
                $deletedCount++;
                $totalFreedBytes += ($result['freed_bytes'] ?? 0);
            } else {
                $failedCount++;
            }
        }

        return [
            'success' => $deletedCount > 0,
            'deleted_count' => $deletedCount,
            'failed_count' => $failedCount,
            'freed_bytes' => $totalFreedBytes,
            'freed_human' => $this->formatBytes($totalFreedBytes),
            'message' => "Đã xóa vĩnh viễn {$deletedCount} tệp tin trên đĩa (Giải phóng {$this->formatBytes($totalFreedBytes)})!".($failedCount > 0 ? " ({$failedCount} tệp không xóa được)" : ''),
        ];
    }

    /**
     * Kiểm tra đường dẫn (tính từ gốc disk) nằm trong thư mục Media (tránh Directory Traversal)
     */
    public function isPathAllowed(string $path): bool
    {
        return str_starts_with($path, self::DISK_ROOT.'/')
            && ! str_contains($path, "\0")
            && ! in_array('..', explode('/', $path), true)
            && ! in_array('.', explode('/', $path), true);
    }

    /** Đổi id (base64 của đường dẫn trên disk) thành đường dẫn, chỉ khi là file trong thư mục Media. */
    public function resolveManagedFile(string $encodedId): ?string
    {
        $decoded = base64_decode($encodedId, true);
        if ($decoded === false || ! $this->isPathAllowed($decoded) || ! $this->disk()->fileExists($decoded)) {
            return null;
        }

        return $decoded;
    }

    /** Chuẩn hóa đường dẫn người dùng nhập, tính từ thư mục gốc Media Manager (public/uploads/media). */
    protected function normalizeUploadDirectory(string $relativeDir): ?string
    {
        $relativeDir = str_replace('\\', '/', trim($relativeDir));
        $relativeDir = trim($relativeDir, '/');

        if ($relativeDir === '') {
            return '';
        }

        $segments = explode('/', $relativeDir);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || ! preg_match('/\A[a-zA-Z0-9 _-]+\z/', $segment)) {
                return null;
            }
        }

        return implode('/', $segments);
    }

    /**
     * Dọn dẹp thư mục rỗng (không xóa thư mục gốc Media)
     */
    protected function cleanEmptyDirectory(string $dirPath): void
    {
        if (! $this->isPathAllowed($dirPath)) {
            return;
        }

        if ($this->disk()->allFiles($dirPath) === [] && $this->disk()->directories($dirPath) === []) {
            $this->disk()->deleteDirectory($dirPath);
        }
    }

    /**
     * Tải lên danh sách tệp tin vào thư mục chỉ định (phân chia ngày tháng năm)
     */
    public function uploadFiles(array $uploadedFiles, ?string $targetFolder = null): array
    {
        $savedFiles = [];
        $errors = [];

        // Xác định thư mục lưu trữ: uploads/media/YYYY/MM hoặc uploads/media/custom_folder
        $now = Carbon::now();
        if (! $targetFolder || $targetFolder === 'auto_date' || $targetFolder === 'all') {
            $cleanFolder = $now->format('Y').'/'.$now->format('m');
        } else {
            $cleanFolder = $this->normalizeUploadDirectory($targetFolder);
            if ($cleanFolder === null) {
                return ['success' => false, 'uploaded' => [], 'count' => 0, 'errors' => ['Thư mục đích không hợp lệ.'], 'message' => 'Thư mục đích không hợp lệ.'];
            }
        }
        $relDir = self::MEDIA_DIRECTORY.($cleanFolder !== '' ? '/'.$cleanFolder : '');
        $destDir = $this->mediaRootPath($cleanFolder);

        foreach ($uploadedFiles as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                $errors[] = 'Một trong các tệp tải lên không hợp lệ hoặc bị lỗi đường truyền.';

                continue;
            }

            // Đuôi file lấy từ nội dung (finfo), không tin đuôi client — chống polyglot .php
            $ext = static::safeExtension($file);
            if ($ext === null) {
                $errors[] = 'Tệp "'.$file->getClientOriginalName().'" có định dạng không được phép tải lên.';

                continue;
            }

            $originalName = $file->getClientOriginalName();
            $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
            $slugName = Str::slug($nameWithoutExt);
            if (empty($slugName)) {
                $slugName = 'file';
            }

            $fileName = $slugName.'-'.$now->format('YmdHis').'-'.Str::random(5).($ext ? '.'.$ext : '');
            $size = (int) $file->getSize();
            $path = $this->disk()->putFileAs($destDir, $file, $fileName);

            if ($path) {
                $savedFiles[] = $this->buildFileMetadata($path, $size, $now->getTimestamp());
            }
        }

        return [
            'success' => count($savedFiles) > 0,
            'uploaded' => $savedFiles,
            'count' => count($savedFiles),
            'errors' => $errors,
            'message' => count($savedFiles) > 0
                ? 'Đã tải lên thành công '.count($savedFiles)." tệp tin vào thư mục /{$relDir}!"
                : 'Không có tệp tin nào được tải lên.',
        ];
    }

    /**
     * Lấy danh sách các thư mục con trong thư mục hiện tại (Explorer / Google Drive view)
     */
    public function getSubFolders(string $relativeDir = ''): Collection
    {
        $cleanRel = $this->normalizeUploadDirectory($relativeDir);
        if ($cleanRel === null) {
            return collect();
        }
        $baseUploads = $this->mediaRootPath($cleanRel);
        $folders = collect();

        foreach ($this->disk()->directories($baseUploads) as $dir) {
            $dirName = basename($dir);
            $dirRel = ($cleanRel ? $cleanRel.'/' : '').$dirName;
            $allFiles = $this->listFiles($dir);

            $folders->push([
                'name' => $dirName,
                'path' => $dirRel,
                'files_count' => $allFiles->count(),
                'total_size' => $totalSize = $allFiles->sum(fn (FileAttributes $f) => $f->fileSize() ?? 0),
                'total_size_human' => $this->formatBytes($totalSize),
            ]);
        }

        return $folders;
    }

    /**
     * Tạo thư mục mới trên đĩa (chuẩn hóa an toàn tên thư mục)
     */
    public function createFolder(string $folderName, string $parentDir = ''): array
    {
        $cleanName = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $folderName);
        $cleanName = trim(preg_replace('/\s+/', '_', $cleanName), '_');

        if (empty($cleanName)) {
            return ['success' => false, 'message' => 'Tên thư mục không hợp lệ! Vui lòng chỉ dùng chữ cái, số và dấu gạch dưới.'];
        }

        $cleanParent = $this->normalizeUploadDirectory($parentDir);
        if ($cleanParent === null) {
            return ['success' => false, 'message' => 'Thư mục cha không hợp lệ!'];
        }
        $relPath = self::MEDIA_DIRECTORY.($cleanParent ? '/'.$cleanParent : '').'/'.$cleanName;
        $targetDir = $this->mediaRootPath(($cleanParent ? $cleanParent.'/' : '').$cleanName);

        if ($this->disk()->directoryExists($targetDir)) {
            return ['success' => false, 'message' => "Thư mục '{$cleanName}' đã tồn tại!"];
        }

        $this->disk()->makeDirectory($targetDir);

        return [
            'success' => true,
            'folder_name' => $cleanName,
            'folder_path' => ($cleanParent ? $cleanParent.'/' : '').$cleanName,
            'message' => "Đã tạo thư mục '{$cleanName}' thành công tại /{$relPath}!",
        ];
    }

    /**
     * Xóa thư mục vật lý trên đĩa
     */
    public function deleteFolder(string $relativeFolder): array
    {
        $cleanRel = $this->normalizeUploadDirectory($relativeFolder);
        if ($cleanRel === null) {
            return ['success' => false, 'message' => 'Đường dẫn thư mục không hợp lệ!'];
        }
        if (empty($cleanRel)) {
            return ['success' => false, 'message' => 'Không được phép xóa thư mục gốc của hệ thống!'];
        }

        $targetDir = $this->mediaRootPath($cleanRel);
        if (! $this->disk()->directoryExists($targetDir)) {
            return ['success' => false, 'message' => 'Thư mục không tồn tại hoặc đã bị xóa trước đó.'];
        }

        $filesCount = count($this->disk()->allFiles($targetDir));
        $this->disk()->deleteDirectory($targetDir);

        return [
            'success' => true,
            'message' => "Đã xóa vĩnh viễn thư mục '{$cleanRel}'".($filesCount > 0 ? " và {$filesCount} tệp bên trong" : '').'!',
        ];
    }

    /**
     * Di chuyển các file đã chọn vào thư mục mới
     */
    public function moveFiles(array $encodedIds, string $targetFolder): array
    {
        $cleanTarget = $this->normalizeUploadDirectory($targetFolder);
        if ($cleanTarget === null) {
            return ['success' => false, 'moved_count' => 0, 'message' => 'Thư mục đích không hợp lệ!'];
        }
        $destDir = $this->mediaRootPath($cleanTarget);

        $movedCount = 0;
        foreach ($encodedIds as $id) {
            $path = $this->resolveManagedFile($id);
            if ($path) {
                $newPath = $destDir.'/'.basename($path);
                if ($path !== $newPath) {
                    $this->disk()->move($path, $newPath);
                    $movedCount++;
                }
            }
        }

        return [
            'success' => $movedCount > 0,
            'moved_count' => $movedCount,
            'message' => "Đã di chuyển thành công {$movedCount} tệp tin vào thư mục /".self::MEDIA_DIRECTORY."/{$cleanTarget}!",
        ];
    }

    /**
     * Định dạng dung lượng tệp đọc được
     */
    public function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision).' '.$units[$pow];
    }
}
