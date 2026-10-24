<?php

namespace App\Services\GoogleDrive;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Client Google Drive API v3 tối giản cho quyền xem file syllabus (docs/kho-syllabus.md §7.4): đăng nhập bằng service account
 * (JWT RS256 ký bằng openssl, không thêm thư viện), đọc / tạo / xoá quyền, chặn tải / in / sao chép. Khóa JSON nằm trên
 * server (config services.google_drive.key_path), không commit.
 */
class DriveClient
{
    private const API = 'https://www.googleapis.com/drive/v3';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/drive';

    private const TOKEN_CACHE = 'google_drive:v1:token';

    /** Đã cấu hình khóa service account hợp lệ (đọc được, có client_email + private_key). */
    public function configured(): bool
    {
        return $this->key() !== null;
    }

    /** Lấy access token (lỗi khóa / mạng / Google từ chối → DriveException) — gọi đầu mỗi lượt đồng bộ. */
    public function ensureToken(): void
    {
        $this->token();
    }

    /** @return list<array{id: string, emailAddress: ?string, role: string}> */
    public function permissions(string $fileId): array
    {
        $response = $this->send(fn () => $this->api()->get(self::API."/files/{$fileId}/permissions", [
            'supportsAllDrives' => 'true', 'fields' => 'permissions(id,emailAddress,role,type)',
        ]));

        return array_map(fn (array $p) => ['id' => (string) $p['id'], 'emailAddress' => $p['emailAddress'] ?? null, 'role' => (string) ($p['role'] ?? '')],
            $this->json($response)['permissions'] ?? []);
    }

    /** Cấp quyền xem (reader) cho email, không gửi email thông báo. Trả về permission id. */
    public function grantReader(string $fileId, string $email): string
    {
        $response = $this->send(fn () => $this->api()->post(self::API."/files/{$fileId}/permissions?sendNotificationEmail=false&supportsAllDrives=true", [
            'type' => 'user', 'role' => 'reader', 'emailAddress' => $email,
        ]));

        return (string) ($this->json($response)['id'] ?? '');
    }

    /** Người xem / bình luận không tải, in, sao chép được file. */
    public function restrictCopy(string $fileId): void
    {
        $this->json($this->send(fn () => $this->api()->patch(self::API."/files/{$fileId}?supportsAllDrives=true", ['copyRequiresWriterPermission' => true])));
    }

    /** Thu hồi quyền; quyền / file không còn (404) coi như đã thu hồi. */
    public function revoke(string $fileId, string $permissionId): void
    {
        $response = $this->send(fn () => $this->api()->delete(self::API."/files/{$fileId}/permissions/{$permissionId}?supportsAllDrives=true"));
        if ($response->status() !== 404) {
            $this->json($response);
        }
    }

    private function api(): PendingRequest
    {
        return Http::withToken($this->token())->acceptJson()->asJson()->timeout(15)->connectTimeout(5);
    }

    private function token(): string
    {
        return Cache::remember(self::TOKEN_CACHE, now()->addMinutes(50), function () {
            $key = $this->key() ?? throw new DriveException('Chưa cấu hình service account Google Drive.');
            $now = time();
            $segments = [
                $this->base64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                $this->base64(json_encode(['iss' => $key['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URL, 'iat' => $now, 'exp' => $now + 3600])),
            ];
            $privateKey = openssl_pkey_get_private($key['private_key']);
            if ($privateKey === false || ! openssl_sign(implode('.', $segments), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                throw new DriveException('Khóa service account Google Drive không hợp lệ.');
            }
            $response = $this->send(fn () => Http::asForm()->timeout(15)->connectTimeout(5)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', [...$segments, $this->base64($signature)]),
            ]));

            return (string) ($this->json($response)['access_token'] ?? throw new DriveException('Google không trả access token.'));
        });
    }

    /** @return array{client_email: string, private_key: string}|null */
    private function key(): ?array
    {
        $path = config('services.google_drive.key_path');
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return null;
        }
        $key = json_decode((string) file_get_contents($path), true);

        return is_array($key) && is_string($key['client_email'] ?? null) && is_string($key['private_key'] ?? null)
            ? ['client_email' => $key['client_email'], 'private_key' => $key['private_key']]
            : null;
    }

    /** Lỗi mạng (timeout, DNS…) → DriveException để lượt đồng bộ ghi lỗi rồi chạy tiếp. */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new DriveException('Không kết nối được Google Drive: '.$e->getMessage(), previous: $e);
        }
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        if ($response->failed()) {
            throw new DriveException((string) ($response->json('error.message') ?? $response->json('error_description') ?? 'Google Drive trả lỗi HTTP '.$response->status().'.'));
        }

        return $response->json() ?? [];
    }

    private function base64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
