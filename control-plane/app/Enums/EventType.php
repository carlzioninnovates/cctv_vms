<?php

namespace App\Enums;

/** Mirrors contracts/schemas/event.v1.json "type". */
enum EventType: string
{
    case Motion = 'motion';
    case LineCrossing = 'line_crossing';
    case Intrusion = 'intrusion';
    case Human = 'human';
    case Vehicle = 'vehicle';
    case Face = 'face';
    case VideoLoss = 'video_loss';
    case Tamper = 'tamper';
    case HddError = 'hdd_error';
    case HddFull = 'hdd_full';
    case RecordingStopped = 'recording_stopped';
    case IoAlarm = 'io_alarm';
    case Unknown = 'unknown';
}
