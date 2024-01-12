<?php

namespace NW\WebService\References\Operations\Notification;

/**
 * @property string $email
 * @property string $mobile
 * @property Seller $seller
 */
class Contractor
{
    const TYPE_CUSTOMER = 0;
    public $id;
    public $type;
    public $name;

    public static function getById(int $resellerId): self
    {
        return new self($resellerId); // fakes the getById method
    }

    public function getFullName(): string
    {
        return $this->name . ' ' . $this->id;
    }

    public function needNotificationByEmail(): bool
    {
        return trim($this->email) !== '';
    }

    public function needNotificationBySms(): bool
    {
        return trim($this->mobile) !== '';
    }
}

class Seller extends Contractor
{
}

class Employee extends Contractor
{
}

class Status
{
    private static $enum = [
        0 => 'Completed',
        1 => 'Pending',
        2 => 'Rejected',
    ];

    public static function getName(int $id): string
    {
        return static::$enum[$id] ?? '';
    }
}

abstract class ReferencesOperation
{
    abstract public function doOperation(): array;

    public function getRequest($pName): array
    {
        return $_REQUEST[$pName] ?? [];
    }
}

function getResellerEmailFrom()
{
    return 'contractor@example.com';
}

function getEmailsByPermit($resellerId, $event)
{
    // fakes the method
    return ['someemeil@example.com', 'someemeil2@example.com'];
}

class NotificationEvents
{
    const CHANGE_RETURN_STATUS = 'changeReturnStatus';
    const NEW_RETURN_STATUS    = 'newReturnStatus';
}

function __(string $templateName, array $templateData, int $sellerId): string
{
    return 'Fake message';
}