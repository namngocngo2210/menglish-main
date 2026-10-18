<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

/**
 * Chép file người dùng đã tải lên máy chủ sang Cloudflare R2 (disk r2_private / r2_public / r2_uploads).
 * Chạy trước khi bật UPLOAD_STORAGE=r2, rồi chạy lại ngay sau khi bật để chép nốt file tải lên trong lúc chuyển.
 * File đã có trên R2 cùng dung lượng thì bỏ qua, nên chạy lại bao nhiêu lần cũng được.
 * --delete-local: xóa bản trên máy chủ sau khi đã có trên R2 (chỉ dùng khi web đã chạy ổn trên R2).
 */
class CopyUploadsToR2Command extends Command
{
    protected $signature = 'uploads:copy-to-r2
        {--only= : Chỉ chép một nhóm: private, public hoặc uploads}
        {--dry-run : Chỉ đếm file cần chép, không ghi gì}
        {--delete-local : Xóa file trên máy chủ khi bản trên R2 đã khớp dung lượng}';

    protected $description = 'Chép file tải lên (storage/app/private, storage/app/public, public/uploads) lên Cloudflare R2';

    public function handle(): int
    {
        $groups = $this->groups();
        if ($only = $this->option('only')) {
            if (! isset($groups[$only])) {
                $this->error('--only phải là một trong: '.implode(', ', array_keys($groups)));

                return self::INVALID;
            }
            $groups = [$only => $groups[$only]];
        }

        $failed = 0;
        foreach ($groups as $name => [$sourceRoot, $targetDisk]) {
            if (! is_dir($sourceRoot)) {
                $this->line("{$name}: không có thư mục {$sourceRoot}, bỏ qua.");

                continue;
            }
            $failed += $this->copyGroup($name, Storage::build(['driver' => 'local', 'root' => $sourceRoot, 'throw' => true]), Storage::disk($targetDisk));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, array{0: string, 1: string}> nhóm => [thư mục trên máy chủ, disk R2] */
    protected function groups(): array
    {
        return [
            'private' => [storage_path('app/private'), 'r2_private'],
            'public' => [storage_path('app/public'), 'r2_public'],
            'uploads' => [public_path('uploads'), 'r2_uploads'],
        ];
    }

    private function copyGroup(string $name, Filesystem $source, Filesystem $target): int
    {
        // Một lần liệt kê mỗi bên (kèm dung lượng), không hỏi R2 từng file.
        $existing = collect($target->listContents('', true))
            ->filter(fn ($item) => $item instanceof FileAttributes)
            ->mapWithKeys(fn (FileAttributes $file) => [$file->path() => $file->fileSize()]);

        $copied = $skipped = $deleted = $failed = 0;
        $bytes = 0;
        foreach ($source->listContents('', true) as $item) {
            if (! $item instanceof FileAttributes || in_array(basename($item->path()), ['.gitignore', '.htaccess', '.DS_Store'], true)) {
                continue;
            }
            $path = $item->path();
            $size = $item->fileSize();

            if ($existing->get($path) !== $size) {
                if ($this->option('dry-run')) {
                    $copied++;
                    $bytes += $size;

                    continue;
                }
                try {
                    $stream = $source->readStream($path);
                    $target->writeStream($path, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    $copied++;
                    $bytes += $size;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("{$name}/{$path}: {$e->getMessage()}");

                    continue;
                }
            } else {
                $skipped++;
            }

            if ($this->option('delete-local') && ! $this->option('dry-run') && $target->size($path) === $size) {
                $source->delete($path);
                $deleted++;
            }
        }

        $verb = $this->option('dry-run') ? 'cần chép' : 'đã chép';
        $this->info(sprintf('%s: %s %d file (%s MB), %d file đã có trên R2%s%s.', $name, $verb, $copied, number_format($bytes / 1048576, 1), $skipped,
            $deleted ? ", đã xóa {$deleted} file trên máy chủ" : '', $failed ? ", {$failed} file lỗi" : ''));

        return $failed;
    }
}
