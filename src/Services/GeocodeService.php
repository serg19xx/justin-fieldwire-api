<?php

declare(strict_types=1);

namespace App\Services;

use Monolog\Logger;

/**
 * Resolve lat/lng from a free-form address via OpenStreetMap Nominatim.
 * Failures return null so project save never blocks on geocoding.
 */
class GeocodeService
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    public function __construct(
        private readonly Logger $logger
    ) {
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function geocodeAddress(string $address): ?array
    {
        $trimmed = trim($address);
        if ($trimmed === '') {
            return null;
        }

        $query = http_build_query([
            'q' => $trimmed,
            'format' => 'json',
            'limit' => 1,
        ]);
        $url = self::NOMINATIM_URL . '?' . $query;

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'header' => "User-Agent: FieldWire-PM/1.0 (schedule-geocode)\r\nAccept: application/json\r\n",
            ],
        ]);

        try {
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false || $raw === '') {
                $this->logger->warning('Geocode request failed', ['address' => $trimmed]);
                return null;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || $decoded === []) {
                return null;
            }
            $first = $decoded[0] ?? null;
            if (!is_array($first)) {
                return null;
            }
            $lat = isset($first['lat']) ? (float) $first['lat'] : null;
            $lng = isset($first['lon']) ? (float) $first['lon'] : null;
            if ($lat === null || $lng === null || !is_finite($lat) || !is_finite($lng)) {
                return null;
            }
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                return null;
            }

            return ['lat' => $lat, 'lng' => $lng];
        } catch (\Throwable $e) {
            $this->logger->warning('Geocode exception', [
                'address' => $trimmed,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
