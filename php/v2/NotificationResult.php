<?php

namespace NW\WebService\References\Operations\Notification;

class NotificationResult implements ResultInterface
{
    private $employeeNotifiedViaEmail = false;
    private $clientNotifiedViaEmail = false;
    private $clientNotifiedViaSms = false;
    private $errorMessage = '';

    public function setEmployeeNotifiedViaEmail(bool $notified): self
    {
        $this->employeeNotifiedViaEmail = $notified;

        return $this;
    }

    public function setClientNotifiedViaEmail(bool $notified): self
    {
        $this->clientNotifiedViaEmail = $notified;

        return $this;
    }

    public function setClientNotifiedViaSms(bool $notified): self
    {
        $this->clientNotifiedViaSms = $notified;

        return $this;
    }

    public function setErrorMessage(string $message): self
    {
        $this->errorMessage = $message;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'notificationEmployeeByEmail' => $this->employeeNotifiedViaEmail,
            'notificationClientByEmail' => $this->clientNotifiedViaEmail,
            'notificationClientBySms' => [
                'isSent' => $this->clientNotifiedViaSms,
                // Плохое имя его назначение понятно только из кода. Исхожу из того что это соблюдение контракта ответа.
                'message' => $this->errorMessage,
            ],
        ];
    }
}