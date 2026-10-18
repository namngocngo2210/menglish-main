<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Mở file công khai đã tải lên (/storage/... của disk 'public', /uploads/... của disk 'uploads') khi file nằm
 * trên R2 (UPLOAD_STORAGE=r2). Web server phục vụ thẳng file còn trên máy chủ; chỉ đường dẫn không có file
 * mới vào đây. Bucket R2 riêng tư nên chuyển hướng sang URL ký có hạn; đường dẫn cũ đã lưu trong DB
 * (/storage/..., /uploads/..., https://<tên miền>/storage/...) vẫn mở được.
 */
class UploadedFileController extends Controller
{
    /** URL ký giữ nguyên trong cùng một giờ để trình duyệt cache được ảnh/audio. */
    private const SIGNED_URL_WINDOW_MINUTES = 60;

    public function storage(string $path): RedirectResponse
    {
        return $this->redirectTo('public', $path);
    }

    public function uploads(string $path): RedirectResponse
    {
        return $this->redirectTo('uploads', $path);
    }

    private function redirectTo(string $disk, string $path): RedirectResponse
    {
        abort_if(config("filesystems.disks.{$disk}.driver") !== 's3', 404);
        abort_if($path === '' || str_contains($path, '..') || str_contains($path, "\0"), 404);

        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);

        // Hết hạn ở cuối giờ kế tiếp: URL không đổi trong giờ hiện tại, còn hạn ít nhất 60 phút.
        $expiresAt = Carbon::now()->startOfHour()->addMinutes(2 * self::SIGNED_URL_WINDOW_MINUTES);

        return redirect()->away($storage->temporaryUrl($path, $expiresAt))
            ->setPublic()
            ->setMaxAge(self::SIGNED_URL_WINDOW_MINUTES * 60 - 600);
    }
}
