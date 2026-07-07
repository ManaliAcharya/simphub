<?php

namespace App\Support\Integrations\GeoIp;

use GeoIp2\Database\Reader;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeoIpService
{
    public function resolve(?string $ipAddress = null): string
    {
        // Get real user IP automatically if not provided
        $ipAddress = $ipAddress ?: getClientIp();

        if (! $ipAddress || $this->isLocalIp($ipAddress)) {
            return 'Local development';
        }

        $databasePath = config('geoip.database_path');

        if (! file_exists($databasePath)) {
            Log::warning('GeoIP database file not found.', [
                'path' => $databasePath,
            ]);

            return 'Unknown location';
        }

        try {
            $reader = new Reader($databasePath);

            $record = $reader->city($ipAddress);

            return implode(', ', array_filter([
                $record->city->name,
                $record->mostSpecificSubdivision->name,
                $record->country->name,
            ])) ?: 'Unknown location';
        } catch (Throwable $e) {

            Log::warning('GeoIP lookup failed.', [
                'ip_address' => $ipAddress,
                'error' => $e->getMessage(),
            ]);

            return 'Unknown location';
        }
    }


    private function isLocalIp(string $ipAddress): bool
    {
        return in_array($ipAddress, [
            '127.0.0.1',
            '::1',
        ], true);
    }
}
