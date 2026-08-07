<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Distance checks between phone GPS and project site coordinates.
 */
class SiteGeoFenceService
{
    /** Default max distance from project site for Start/End (meters). */
    public const DEFAULT_MAX_METERS = 200;

    public function maxAllowedMeters(): int
    {
        $raw = $_ENV['SITE_CHECKIN_MAX_METERS'] ?? getenv('SITE_CHECKIN_MAX_METERS') ?: null;
        if ($raw === null || $raw === false || $raw === '') {
            return self::DEFAULT_MAX_METERS;
        }
        $n = (int) $raw;
        if ($n < 50) {
            return 50;
        }
        if ($n > 5000) {
            return 5000;
        }

        return $n;
    }

    /**
     * @return array{ok: true, distance_m: float|null}|array{ok: false, distance_m: float|null, max_m: int, message: string}
     */
    public function assertWithinSite(
        ?float $siteLat,
        ?float $siteLng,
        float $phoneLat,
        float $phoneLng,
        ?int $maxMeters = null,
    ): array {
        $max = $maxMeters ?? $this->maxAllowedMeters();
        if ($siteLat === null || $siteLng === null) {
            return [
                'ok' => false,
                'distance_m' => null,
                'max_m' => $max,
                'message' => 'Job site coordinates are not set for this project. Ask a PM to save the project address, then try again.',
            ];
        }

        $km = $this->haversineKm($phoneLat, $phoneLng, $siteLat, $siteLng);
        if ($km === null) {
            return [
                'ok' => false,
                'distance_m' => null,
                'max_m' => $max,
                'message' => 'Could not verify distance to the job site. Try again.',
            ];
        }
        $meters = round($km * 1000, 1);
        if ($meters > $max) {
            return [
                'ok' => false,
                'distance_m' => $meters,
                'max_m' => $max,
                'message' => sprintf(
                    'You are too far from the job site to start or end work (%.0f m away; allowed within %d m).',
                    $meters,
                    $max
                ),
            ];
        }

        return ['ok' => true, 'distance_m' => $meters];
    }

    public function haversineKm(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): ?float
    {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
            return null;
        }
        $earthKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthKm * $c, 5);
    }
}
