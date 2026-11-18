<?php

namespace App\Enums;

/** How the cloud reaches the NVR. All modes are outbound-from-site (CGNAT safe). */
enum ConnectionMode: string
{
    case EdgeAgent = 'edge_agent';          // default: on-site agent dials out
    case HikvisionIsup = 'hikvision_isup';  // NVR calls home (ISUP/EHome firmware)
    case DahuaAutoRegister = 'dahua_auto_register';
}
