<?php

namespace NW\WebService\References\Operations\Notification;

use RuntimeException;

class NotificationManager
{
    /** @throws RuntimeException */
    public static function sendMessageToEmployee(array $messages, int $sellerId, string $event): void
    {
    }

    /** @throws RuntimeException */
    public static function sendEmailMessageToClient(
        array $messages,
        $sellerId,
        $clientId,
        string $event,
        int $newStatus
    ): void {
    }

    /** @throws RuntimeException */
    public static function sendSmsMessageToClient(
        $sellerId,
        $clientId,
        string $event,
        int $newStatus,
        array $templateData
    ): void {
    }
}