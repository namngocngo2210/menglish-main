<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lưu file upload an toàn, dùng chung cho mọi nơi upload:
 * - Đuôi file suy ra từ NỘI DUNG (không tin đuôi client gửi), phải nằm trong danh sách cho phép.
 * - Đuôi chạy được code (php, html, svg...) luôn bị chặn.
 * - Tên file ngẫu nhiên, không dùng tên gốc.
 */
class SafeUploadService
{
    public const IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public const DOCUMENTS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];

    public const AUDIO = ['mp3', 'wav', 'm4a', 'ogg', 'oga', 'weba', 'webm', 'aac'];

    public const VIDEO = ['mp4', 'mov', 'webm', 'm4v', '3gp'];

    /**
     * Lưu file vào thư mục $directory trên disk $disk ('public', 'local' hoặc 'uploads'; trên R2 khi
     * UPLOAD_STORAGE=r2). Trả về đường dẫn tương đối trên disk.
     *
     * @param  string[]  $allowed  đuôi file cho phép (theo nội dung)
     *
     * @throws ValidationException
     */
    public static function store(UploadedFile $file, string $directory, array $allowed, string $field = 'file', string $disk = 'public'): string
    {
        $extension = self::extensionFor($file, $allowed, $field);

        return $file->storeAs($directory, self::randomName($extension), $disk);
    }

    /**
     * @throws ValidationException
     */
    public static function extensionFor(UploadedFile $file, array $allowed, string $field = 'file'): string
    {
        $extension = MediaManagerService::safeExtension($file);
        $allowed = array_map('strtolower', $allowed);

        // Một số định dạng có nhiều đuôi tương đương.
        $aliases = ['jpeg' => 'jpg', 'qt' => 'mov', 'mpga' => 'mp3'];
        if ($extension && ! in_array($extension, $allowed, true) && isset($aliases[$extension]) && in_array($aliases[$extension], $allowed, true)) {
            $extension = $aliases[$extension];
        }

        if (! $extension || ! in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages([
                $field => 'Định dạng file không được phép. Chấp nhận: '.implode(', ', $allowed).'.',
            ]);
        }

        return $extension;
    }

    public static function randomName(string $extension): string
    {
        return now()->format('YmdHis').'_'.Str::random(20).'.'.$extension;
    }
}
