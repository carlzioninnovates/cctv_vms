<?php

namespace App\Enums;

/** Mirrors data-plane/app/tunnels/offline.py */
enum OfflineCause: string
{
    case NvrUnreachable = 'nvr_unreachable';        // agent online, NVR not answering
    case SiteInternetDown = 'site_internet_down';   // agent reported uplink loss
    case PowerLoss = 'power_loss';                  // UPS signal, or agent rebooted during outage
    case SiteUnreachable = 'site_unreachable';      // power or internet, not yet known
}
