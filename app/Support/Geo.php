<?php

namespace App\Support;

/** Tính toán toạ độ GPS (chấm công theo bán kính cơ sở). */
final class Geo
{
    private const EARTH_RADIUS_METERS = 6371000;

    /** Khoảng cách đường chim bay giữa 2 toạ độ (công thức haversine), đơn vị mét. */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
