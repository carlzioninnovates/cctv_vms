<?php

namespace App\Enums;

enum NotificationChannelType: string
{
    case Push = 'push';           // FCM
    case Viber = 'viber';
    case Telegram = 'telegram';
    case Messenger = 'messenger';
    case Sms = 'sms';             // e.g. Semaphore
    case Email = 'email';
}
