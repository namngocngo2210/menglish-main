<?php

namespace App\Services\GoogleDrive;

/** Nhận dạng ID file / thư mục Google Drive từ link trong syllabus (docs/kho-syllabus.md §7.4); link khác → null. */
final class DriveLinks
{
    private const PATTERNS = [
        '#^https://docs\.google\.com/(?:document|presentation|spreadsheets|forms)/d/([A-Za-z0-9_-]{10,})#' => 'file',
        '#^https://drive\.google\.com/file/d/([A-Za-z0-9_-]{10,})#' => 'file',
        '#^https://drive\.google\.com/drive/(?:u/\d+/)?folders/([A-Za-z0-9_-]{10,})#' => 'folder',
        '#^https://drive\.google\.com/open\?(?:.*&)?id=([A-Za-z0-9_-]{10,})#' => 'file',
    ];

    /** @return array{id: string, kind: 'file'|'folder'}|null */
    public static function parse(?string $url): ?array
    {
        $url = trim((string) $url);
        foreach (self::PATTERNS as $pattern => $kind) {
            if (preg_match($pattern, $url, $m)) {
                return ['id' => $m[1], 'kind' => $kind];
            }
        }

        return null;
    }
}
