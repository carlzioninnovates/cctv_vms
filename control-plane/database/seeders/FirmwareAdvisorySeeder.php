<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Starter list. Check each vendor bulletin for exact affected models and
 * fixed builds before showing these to customers.
 */
class FirmwareAdvisorySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        DB::table('firmware_advisories')->upsert([
            [
                'brand' => 'hikvision',
                'cve' => 'CVE-2021-36260',
                'severity' => 'critical',
                'model_pattern' => null,
                'fixed_in_build' => null,
                'summary' => 'Unauthenticated command injection in the web server. Actively exploited.',
                'reference_url' => 'https://www.hikvision.com/en/support/cybersecurity/security-advisory/',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'brand' => 'dahua',
                'cve' => 'CVE-2021-33044',
                'severity' => 'critical',
                'model_pattern' => null,
                'fixed_in_build' => null,
                'summary' => 'Authentication bypass during login.',
                'reference_url' => 'https://www.dahuasecurity.com/support/cybersecurity',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'brand' => 'dahua',
                'cve' => 'CVE-2021-33045',
                'severity' => 'critical',
                'model_pattern' => null,
                'fixed_in_build' => null,
                'summary' => 'Authentication bypass during login (loopback variant).',
                'reference_url' => 'https://www.dahuasecurity.com/support/cybersecurity',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['cve'], ['summary', 'severity', 'reference_url', 'updated_at']);
    }
}
