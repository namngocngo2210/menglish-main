<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tìm toạ độ theo địa chỉ cho bản đồ cài đặt chấm công cơ sở (gọi từ máy chủ, trình duyệt không gọi thẳng dịch vụ ngoài).
 *   AddressSearch::search('23 ngõ 209 Đội Cấn, Ba Đình') → ['results' => [[id, label, lat, lng], …], 'matched' => 'Đội Cấn, Ba Đình']
 * - Nominatim (OpenStreetMap) trước, không thấy / lỗi → Photon (komoot, cũng dữ liệu OSM, tìm "gần đúng" tốt hơn).
 * - Địa chỉ Việt Nam đủ số nhà / ngõ / ngách thường không có trên OSM → thử lại bỏ dần phần đó (`matched` = câu đã khớp).
 * - Gửi User-Agent riêng (chính sách Nominatim chặn request không định danh), kết quả lưu cache 30 ngày.
 * Mọi dịch vụ đều lỗi → null (khác với "không thấy" = mảng rỗng).
 */
class AddressSearch
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    private const PHOTON_URL = 'https://photon.komoot.io/api/';

    /** Khung Việt Nam cho Photon: minLon, minLat, maxLon, maxLat. */
    private const VIETNAM_BBOX = '102.1,8.2,109.6,23.4';

    private const LIMIT = 5;

    /** @return array{results: list<array{id: string, label: string, lat: float, lng: float}>, matched: ?string}|null */
    public static function search(string $query): ?array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query));
        if ($query === '') {
            return ['results' => [], 'matched' => null];
        }

        $key = 'address-search:'.md5(Str::lower($query));
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $reached = false;
        $found = ['results' => [], 'matched' => null];
        foreach (self::variants($query) as $index => $variant) {
            $results = self::nominatim($variant, $reached);
            if (! $results) {
                $results = self::photon($variant, $reached);
            }
            if ($results) {
                $found = ['results' => $results, 'matched' => $index === 0 ? null : $variant];
                break;
            }
        }

        if (! $reached) {
            return null;
        }
        Cache::put($key, $found, now()->addDays(30));

        return $found;
    }

    /**
     * Câu tìm gốc rồi các bản rút gọn: bỏ số nhà / ngõ / ngách / hẻm / kiệt ở đầu, bỏ dần phần trước dấu phẩy.
     *   "Số 23 ngõ 209 Đội Cấn, Ba Đình, Hà Nội" → "Đội Cấn, Ba Đình, Hà Nội" → "Ba Đình, Hà Nội"
     *
     * @return list<string>
     */
    public static function variants(string $query): array
    {
        $variants = [$query];

        $parts = array_values(array_filter(array_map('trim', explode(',', $query)), fn ($part) => $part !== ''));
        $alley = '/^(?:(?:số|so|sn|nhà|no\.?)\s*)?\d+[a-z]?(?:\/\d+[a-z]?)*\s*|^(?:ngõ|ngo|ngách|ngach|hẻm|hem|kiệt|kiet|tổ|to)\s*\d+[a-z]?(?:\/\d+[a-z]?)*\s*/iu';
        // Bỏ số nhà / ngõ ở đầu; phần đầu chỉ có số nhà ("Số 12, ngõ 34 …") thì bỏ hẳn và xét tiếp phần sau.
        $street = $parts;
        while ($street) {
            $first = $street[0];
            do {
                $previous = $first;
                $first = trim((string) preg_replace($alley, '', $first, 1));
            } while ($first !== $previous && $first !== '');
            if ($first !== '') {
                $street[0] = $first;
                break;
            }
            array_shift($street);
        }
        if ($street) {
            $variants[] = implode(', ', $street);
        }
        // Còn ≥ 3 phần (đường, phường, quận…) thì bỏ phần đầu thêm 1 lần.
        if (count($street) >= 3) {
            $variants[] = implode(', ', array_slice($street, 1));
        }

        return array_values(array_unique($variants));
    }

    /** @return list<array{id: string, label: string, lat: float, lng: float}> */
    private static function nominatim(string $query, bool &$reached): array
    {
        $response = self::get(self::NOMINATIM_URL, [
            'q' => $query, 'format' => 'jsonv2', 'limit' => self::LIMIT, 'countrycodes' => 'vn', 'accept-language' => 'vi',
        ]);
        if (! $response || ! is_array($rows = $response->json())) {
            return [];
        }
        $reached = true;

        return collect($rows)
            ->filter(fn ($row) => is_array($row) && isset($row['lat'], $row['lon'], $row['display_name']))
            ->map(fn ($row) => [
                'id' => 'n'.($row['place_id'] ?? md5($row['display_name'])),
                'label' => (string) $row['display_name'],
                'lat' => (float) $row['lat'],
                'lng' => (float) $row['lon'],
            ])->values()->all();
    }

    /** @return list<array{id: string, label: string, lat: float, lng: float}> */
    private static function photon(string $query, bool &$reached): array
    {
        $response = self::get(self::PHOTON_URL, [
            'q' => $query, 'limit' => self::LIMIT, 'lang' => 'default', 'bbox' => self::VIETNAM_BBOX,
        ]);
        if (! $response || ! is_array($features = $response->json('features'))) {
            return [];
        }
        $reached = true;

        return collect($features)
            ->filter(fn ($f) => is_array($f) && strtoupper($f['properties']['countrycode'] ?? 'VN') === 'VN' && isset($f['geometry']['coordinates'][1]))
            ->map(function ($f) {
                $p = $f['properties'];
                $street = trim(($p['housenumber'] ?? '').' '.($p['street'] ?? ''));
                $label = collect([$p['name'] ?? null, $street, $p['locality'] ?? null, $p['district'] ?? null, $p['city'] ?? null, $p['county'] ?? null, $p['state'] ?? null, $p['country'] ?? null])
                    ->filter()->unique()->implode(', ');

                return [
                    'id' => 'p'.($p['osm_type'] ?? '').($p['osm_id'] ?? md5($label)),
                    'label' => $label,
                    'lat' => (float) $f['geometry']['coordinates'][1],
                    'lng' => (float) $f['geometry']['coordinates'][0],
                ];
            })->filter(fn ($row) => $row['label'] !== '')->values()->all();
    }

    private static function get(string $url, array $params): ?Response
    {
        try {
            $response = Http::timeout(8)->connectTimeout(5)->acceptJson()
                ->withUserAgent(Str::slug((string) config('app.name', 'MEnglish')).'/1.0 (+'.config('app.url').')')
                ->withHeaders(['Referer' => (string) config('app.url')])
                ->get($url, $params);
        } catch (Throwable $e) {
            logger()->warning('Tìm địa chỉ: không kết nối được dịch vụ', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
        if (! $response->successful()) {
            logger()->warning('Tìm địa chỉ: dịch vụ trả lỗi', ['url' => $url, 'status' => $response->status()]);

            return null;
        }

        return $response;
    }
}
