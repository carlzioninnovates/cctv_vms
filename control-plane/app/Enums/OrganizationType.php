<?php

namespace App\Enums;

/** Three-level tenancy: platform -> reseller (installer) -> client. */
enum OrganizationType: string
{
    case Platform = 'platform';
    case Reseller = 'reseller';
    case Client = 'client';

    public function allowedParent(): ?self
    {
        return match ($this) {
            self::Platform => null,
            self::Reseller => self::Platform,
            self::Client => self::Reseller,
        };
    }
}
