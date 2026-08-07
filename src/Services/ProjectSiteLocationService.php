<?php

declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;
use Monolog\Logger;

/**
 * Resolve project site lat/lng (DB cache, geocode address if missing).
 */
class ProjectSiteLocationService
{
    public function __construct(
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function resolve(Connection $conn, int $projectId): ?array
    {
        if ($projectId <= 0) {
            return null;
        }
        try {
            $row = $conn->executeQuery(
                'SELECT address, latitude, longitude FROM fw_projects WHERE id = ?',
                [$projectId]
            )->fetchAssociative();
        } catch (\Throwable $e) {
            $this->logger->warning('Project site lookup failed', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
        if (!$row) {
            return null;
        }

        $lat = $this->nullableFloat($row['latitude'] ?? null);
        $lng = $this->nullableFloat($row['longitude'] ?? null);
        if ($lat !== null && $lng !== null) {
            return ['lat' => $lat, 'lng' => $lng];
        }

        $address = isset($row['address']) ? trim((string) $row['address']) : '';
        if ($address === '') {
            return null;
        }

        $geo = (new GeocodeService($this->logger))->geocodeAddress($address);
        if ($geo === null) {
            return null;
        }

        try {
            $conn->executeStatement(
                'UPDATE fw_projects SET latitude = ?, longitude = ?, updated_at = NOW() WHERE id = ?',
                [$geo['lat'], $geo['lng'], $projectId]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to cache project geocode', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);
        }

        return $geo;
    }

    private function nullableFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }
        $f = (float) $v;

        return is_finite($f) ? $f : null;
    }
}
