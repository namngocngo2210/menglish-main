<?php

namespace App\Support;

/**
 * Tham số theo dõi mà ứng dụng nhắn tin / mạng xã hội tự gắn vào link khi mở trên điện thoại
 * (Zalo: zarsrc + utm_*, Facebook/Messenger: fbclid, mibextid…).
 *
 * Link có chữ ký (bảng điểm, link làm test) phải bỏ qua các tham số này, nếu không chữ ký
 * không khớp và trả 403. Bỏ qua chúng không làm yếu chữ ký: đường dẫn và các tham số còn lại
 * vẫn phải khớp, và không controller nào đọc các tham số này.
 */
final class TrackingQueryParams
{
    public const NAMES = [
        'zarsrc',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'utm_id',
        'fbclid',
        'mibextid',
        'igshid',
        'gclid',
        'ttclid',
        'msclkid',
        '_gl',
    ];
}
