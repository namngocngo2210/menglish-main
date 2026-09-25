<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

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
     * Danh sách các thư mục gốc được phép quản lý file
     */
    protected array $managedRoots = [];

    public function __construct()
    {
        $this->managedRoots = [
            'uploads' => public_path('uploads'),
            'storage_public' => storage_path('app/public'),
        ];
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
     * Quét và lấy toàn bộ danh sách file từ đĩa vật lý
     */
    public function getAllFiles(): Collection
    {
        $files = collect();

        foreach ($this->managedRoots as $rootKey => $rootPath) {
            if (! File::isDirectory($rootPath)) {
                continue;
            }

            $allFiles = File::allFiles($rootPath);
            foreach ($allFiles as $file) {
                if ($file->getFilename() === '.gitignore' || $file->getFilename() === '.DS_Store') {
                    continue;
                }

                $meta = $this->buildFileMetadata($file, $rootKey, $rootPath);
                if ($meta) {
                    $files->push($meta);
                }
            }
        }

        return $files;
    }

    /**
     * Xây dựng metadata cho từng file
     */
    protected function buildFileMetadata(SplFileInfo $file, string $rootKey, string $rootPath): ?array
    {
        $absPath = $file->getRealPath();
        if (! $absPath || ! File::exists($absPath)) {
            return null;
        }

        $extension = strtolower($file->getExtension());
        $size = $file->getSize();
        $mtime = $file->getMTime();
        $createdAt = Carbon::createFromTimestamp($mtime);

        // Tính toán relative path và URL
        if ($rootKey === 'uploads') {
            $relFromPublic = str_replace(public_path().DIRECTORY_SEPARATOR, '', $absPath);
            $relFromPublic = str_replace('\\', '/', $relFromPublic);
            $url = asset($relFromPublic);

            $relDir = dirname($relFromPublic);
            if (str_starts_with($relDir, 'uploads/')) {
                $folder = substr($relDir, 8);
            } elseif ($relDir === 'uploads' || $relDir === '.') {
                $folder = '';
            } else {
                $folder = $relDir;
            }
            $folder = trim($folder, '/');
        } else {
            $relFromStorage = str_replace($rootPath.DIRECTORY_SEPARATOR, '', $absPath);
            $relFromStorage = str_replace('\\', '/', $relFromStorage);
            $url = asset('storage/'.$relFromStorage);
            $folder = trim(dirname($relFromStorage), '/');
            if ($folder === '.') {
                $folder = '';
            }
        }

        $type = $this->classifyFileType($extension);

        return [
            'id' => base64_encode($absPath),
            'filename' => $file->getFilename(),
            'extension' => $extension ?: 'file',
            'type' => $type,
            'size' => $size,
            'size_human' => $this->formatBytes($size),
            'directory' => $folder,
            'absolute_path' => $absPath,
            'relative_path' => str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $absPath)),
            'url' => $url,
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
     * Xóa vật lý 1 file trên đĩa
     */
    public function deletePhysicalFile(string $encodedId): array
    {
        $realPath = base64_decode($encodedId);

        if (! $realPath || ! File::exists($realPath)) {
            return [
                'success' => false,
                'message' => 'Tệp tin không tồn tại hoặc đã bị xóa trước đó!',
            ];
        }

        // Bảo mật: Kiểm tra path có nằm trong các thư mục được phép quản lý không
        if (! $this->isPathAllowed($realPath)) {
            return [
                'success' => false,
                'message' => 'Từ chối truy cập: Đường dẫn tệp tin không nằm trong thư mục cho phép quản lý!',
            ];
        }

        $filename = basename($realPath);
        $size = File::size($realPath);

        // XÓA VẬT LÝ TRÊN ĐĨA CỨNG
        $deleted = File::delete($realPath);

        if ($deleted) {
            // Tự động dọn dẹp thư mục cha nếu rỗng
            $this->cleanEmptyDirectory(dirname($realPath));

            return [
                'success' => true,
                'message' => "Đã xóa vĩnh viễn tệp '{$filename}' ({$this->formatBytes($size)}) khỏi đĩa cứng!",
                'freed_bytes' => $size,
            ];
        }

        return [
            'success' => false,
            'message' => "Không thể xóa tệp tin '{$filename}' do quyền truy cập file hệ thống!",
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
     * Kiểm tra tính an toàn của đường dẫn file (Tránh Directory Traversal)
     */
    public function isPathAllowed(string $realPath): bool
    {
        $realPath = realpath($realPath) ?: $realPath;

        foreach ($this->managedRoots as $rootPath) {
            $realRoot = realpath($rootPath) ?: $rootPath;
            if ($realPath === $realRoot || str_starts_with($realPath, $realRoot.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /** Resolve an encoded media id only when it points to a managed file. */
    public function resolveManagedFile(string $encodedId): ?string
    {
        $decoded = base64_decode($encodedId, true);
        if ($decoded === false || str_contains($decoded, "\0")) {
            return null;
        }

        $realPath = realpath($decoded);
        if ($realPath === false || ! File::isFile($realPath) || ! $this->isPathAllowed($realPath)) {
            return null;
        }

        return $realPath;
    }

    /** Normalize a user supplied path relative to public/uploads. */
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
     * Dọn dẹp thư mục rỗng
     */
    protected function cleanEmptyDirectory(string $dirPath): void
    {
        if (! File::isDirectory($dirPath)) {
            return;
        }

        // Không xóa thư mục gốc
        foreach ($this->managedRoots as $rootPath) {
            if ($dirPath === $rootPath) {
                return;
            }
        }

        if (count(File::allFiles($dirPath)) === 0 && count(File::directories($dirPath)) === 0) {
            @rmdir($dirPath);
        }
    }

    /**
     * Tải lên danh sách tệp tin vào thư mục chỉ định (phân chia ngày tháng năm)
     */
    public function uploadFiles(array $uploadedFiles, ?string $targetFolder = null): array
    {
        $savedFiles = [];
        $errors = [];

        // Xác định thư mục lưu trữ: uploads/YYYY/MM hoặc uploads/custom_folder
        $now = Carbon::now();
        if (! $targetFolder || $targetFolder === 'auto_date' || $targetFolder === 'all') {
            $relDir = 'uploads/'.$now->format('Y').'/'.$now->format('m');
        } else {
            $cleanFolder = $this->normalizeUploadDirectory($targetFolder);
            if ($cleanFolder === null) {
                return ['success' => false, 'uploaded' => [], 'count' => 0, 'errors' => ['Thư mục đích không hợp lệ.'], 'message' => 'Thư mục đích không hợp lệ.'];
            }
            if (str_starts_with($cleanFolder, 'uploads/')) {
                $relDir = $cleanFolder;
            } else {
                $relDir = 'uploads/'.$cleanFolder;
            }
        }

        $destDir = public_path($relDir);
        File::ensureDirectoryExists($destDir);

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
            $filePath = $file->move($destDir, $fileName);

            $absPath = $filePath->getRealPath();
            $fileInfo = new SplFileInfo($absPath, $relDir, $relDir.'/'.$fileName);
            $meta = $this->buildFileMetadata($fileInfo, 'uploads', public_path('uploads'));

            if ($meta) {
                $savedFiles[] = $meta;
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
        $baseUploads = public_path('uploads'.($cleanRel ? '/'.$cleanRel : ''));

        if (! File::isDirectory($baseUploads)) {
            return collect();
        }

        $directories = File::directories($baseUploads);
        $folders = collect();

        foreach ($directories as $dir) {
            $dirName = basename($dir);
            $dirRel = ($cleanRel ? $cleanRel.'/' : '').$dirName;
            $allFiles = File::allFiles($dir);
            $totalSize = 0;
            foreach ($allFiles as $f) {
                $totalSize += $f->getSize();
            }

            $folders->push([
                'name' => $dirName,
                'path' => $dirRel,
                'full_path' => $dir,
                'files_count' => count($allFiles),
                'total_size' => $totalSize,
                'total_size_human' => $this->formatBytes($totalSize),
                'mtime' => Carbon::createFromTimestamp(filemtime($dir))->format('d/m/Y H:i'),
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
        $relPath = 'uploads'.($cleanParent ? '/'.$cleanParent : '').'/'.$cleanName;
        $targetDir = public_path($relPath);

        if (File::isDirectory($targetDir)) {
            return ['success' => false, 'message' => "Thư mục '{$cleanName}' đã tồn tại!"];
        }

        File::makeDirectory($targetDir, 0755, true, true);

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
        if (empty($cleanRel) || $cleanRel === 'uploads') {
            return ['success' => false, 'message' => 'Không được phép xóa thư mục gốc của hệ thống!'];
        }

        $targetDir = public_path('uploads/'.$cleanRel);
        if (! File::isDirectory($targetDir)) {
            return ['success' => false, 'message' => 'Thư mục không tồn tại hoặc đã bị xóa trước đó.'];
        }

        $filesCount = count(File::allFiles($targetDir));
        File::deleteDirectory($targetDir);

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
        $destDir = public_path('uploads'.($cleanTarget ? '/'.$cleanTarget : ''));
        File::ensureDirectoryExists($destDir);

        $movedCount = 0;
        foreach ($encodedIds as $id) {
            $realPath = $this->resolveManagedFile($id);
            if ($realPath) {
                $filename = basename($realPath);
                $newPath = $destDir.'/'.$filename;
                if ($realPath !== $newPath) {
                    File::move($realPath, $newPath);
                    $movedCount++;
                }
            }
        }

        return [
            'success' => $movedCount > 0,
            'moved_count' => $movedCount,
            'message' => "Đã di chuyển thành công {$movedCount} tệp tin vào thư mục /uploads/{$cleanTarget}!",
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
