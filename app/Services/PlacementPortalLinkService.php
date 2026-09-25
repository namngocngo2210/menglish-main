<?php

namespace App\Services;

use App\Models\CrmCustomer;
use App\Models\PlacementTest;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;

/**
 * Link làm bài test gắn với một lead cụ thể.
 *
 * - Link gửi cho lead là URL có chữ ký + hạn dùng (bind lead id + mã đề), nên
 *   không thể đổi `lead` để xem PII của lead khác.
 * - Khi mở link hợp lệ, form nhận một token mã hóa (lead + đề + hạn) để lúc nộp
 *   bài server xác minh lại, thay vì tin `customer_id` từ client.
 */
class PlacementPortalLinkService
{
    public const LINK_TTL_DAYS = 7;

    /** Thời gian cộng thêm sau hạn link để thí sinh kịp làm xong bài đang mở. */
    private const SUBMIT_GRACE_MINUTES = 60;

    public function signedLinkForLead(PlacementTest $test, CrmCustomer $lead): string
    {
        return URL::temporarySignedRoute(
            'portal.test.take',
            now()->addDays(self::LINK_TTL_DAYS),
            ['code' => $test->code, 'lead' => $lead->id],
        );
    }

    public function leadFromSignedRequest(Request $request): ?CrmCustomer
    {
        if (! $request->query('lead') || ! $request->hasValidSignature()) {
            return null;
        }

        return CrmCustomer::find($request->query('lead'));
    }

    public function issueLeadToken(PlacementTest $test, CrmCustomer $lead, Request $request): string
    {
        $linkExpiresAt = (int) $request->query('expires', now()->addDays(self::LINK_TTL_DAYS)->getTimestamp());
        $expiresAt = $linkExpiresAt + ((int) $test->duration_minutes + self::SUBMIT_GRACE_MINUTES) * 60;

        return Crypt::encryptString(json_encode([
            'lead' => $lead->id,
            'test' => $test->id,
            'exp' => $expiresAt,
        ]));
    }

    public function leadFromToken(?string $token, PlacementTest $test): ?CrmCustomer
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($payload)
            || (int) ($payload['test'] ?? 0) !== $test->id
            || (int) ($payload['exp'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return CrmCustomer::find((int) ($payload['lead'] ?? 0));
    }
}
