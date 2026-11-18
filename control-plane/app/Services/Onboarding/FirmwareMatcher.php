<?php

namespace App\Services\Onboarding;

use App\Models\FirmwareAdvisory;
use App\Models\Nvr;
use Carbon\CarbonImmutable;

/**
 * Links an NVR to advisories that affect it. Vendors version firmware inconsistently, so we
 * compare BUILD DATES, which every brand includes:
 *   Hikvision "V4.30.005 build 200929"   Dahua "4.001.0000000.3.R,build:2023-01-01"
 * An advisory with no fixed_in_build (date) is never auto-matched; fill it from the bulletin.
 */
class FirmwareMatcher
{
    public static function buildDate(?string $firmware): ?CarbonImmutable
    {
        if (! $firmware) {
            return null;
        }
        if (preg_match('/build[:\s]*(\d{4})[-_.]?(\d{2})[-_.]?(\d{2})/i', $firmware, $m)) {
            return self::date($m[1], $m[2], $m[3]);
        }
        if (preg_match('/build[:\s]*(\d{2})(\d{2})(\d{2})\b/i', $firmware, $m)) {   // yymmdd
            return self::date('20'.$m[1], $m[2], $m[3]);
        }

        return null;
    }

    private static function date(string $y, string $m, string $d): ?CarbonImmutable
    {
        return checkdate((int) $m, (int) $d, (int) $y) ? CarbonImmutable::create((int) $y, (int) $m, (int) $d) : null;
    }

    /** @return int[] advisory ids that apply */
    public function match(Nvr $nvr): array
    {
        $built = self::buildDate($nvr->firmware);
        if ($built === null) {
            return [];
        }

        return FirmwareAdvisory::query()
            ->where('brand', $nvr->brand->value)
            ->whereNotNull('fixed_in_build')
            ->get()
            ->filter(function (FirmwareAdvisory $a) use ($nvr, $built) {
                if ($a->model_pattern && ! @preg_match('/'.$a->model_pattern.'/i', (string) $nvr->model)) {
                    return false;
                }
                $fixed = rescue(fn () => CarbonImmutable::parse($a->fixed_in_build), null, false);

                return $fixed !== null && $built->lt($fixed);
            })
            ->pluck('id')
            ->all();
    }
}
