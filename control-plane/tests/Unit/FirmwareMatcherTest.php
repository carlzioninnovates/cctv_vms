<?php

namespace Tests\Unit;

use App\Services\Onboarding\FirmwareMatcher;
use PHPUnit\Framework\TestCase;

class FirmwareMatcherTest extends TestCase
{
    public function test_build_dates_across_brands(): void
    {
        $this->assertSame('2020-09-29', FirmwareMatcher::buildDate('V4.30.005 build 200929')?->toDateString());
        $this->assertSame('2023-01-01', FirmwareMatcher::buildDate('4.001.0000000.3.R,build:2023-01-01')?->toDateString());
        $this->assertSame('2022-05-17', FirmwareMatcher::buildDate('V2.0 Build20220517')?->toDateString());
        $this->assertNull(FirmwareMatcher::buildDate('V4.30.005'));
        $this->assertNull(FirmwareMatcher::buildDate('build 991399'));   // not a real date
        $this->assertNull(FirmwareMatcher::buildDate(null));
    }
}
