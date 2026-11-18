from __future__ import annotations

import httpx

from .base import NvrDriver
from .dahua import DahuaDriver
from .hikvision import HikvisionDriver
from .models import Brand, DeviceConnection
from .onvif_generic import OnvifDriver
from .uniview import UniviewDriver

DRIVERS: dict[Brand, type[NvrDriver]] = {
    Brand.HIKVISION: HikvisionDriver,
    Brand.DAHUA: DahuaDriver,
    Brand.UNIVIEW: UniviewDriver,
    Brand.ONVIF: OnvifDriver,
}


def driver_for(conn: DeviceConnection, client: httpx.AsyncClient) -> NvrDriver:
    return DRIVERS[conn.brand](conn, client)
