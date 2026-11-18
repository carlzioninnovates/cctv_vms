<?php

namespace App\Enums;

enum DeviceBrand: string
{
    case Hikvision = 'hikvision';
    case Dahua = 'dahua';
    case Uniview = 'uniview';
    case Onvif = 'onvif'; // generic fallback: EZVIZ, Tiandy, unbranded
}
