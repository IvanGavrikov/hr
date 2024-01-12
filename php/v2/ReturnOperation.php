<?php

namespace NW\WebService\References\Operations\Notification;

use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 *
 * Краткое резюме по коду:
 *   - назначение кода: уведомить сотрудников компании информацией о возврате клиентом позиций.
 * Уведомления клиента по email и смс об положительном решении о возврате.
 *
 *   - качество кода: основная проблема в коде это очень много зон ответственности для одного классе.
 * Валидацией необходимых данных, можно вынести в отдельный класс и передавать в dto через метод. Появится возможность тестировать класс.
 * Код генерации сообщений можно тоже делегировать отдельному сервису или двум (для email писем и смс).
 * Хорошо было бы разбить процесс весь процесс на три отдельные операции, так мы сможем гарантировать что все уведомления дойдут до получателей.
 * Но тогда нужен рефакторинг и клиентского кода.
 *
 */
class ReturnOperation extends ReferencesOperation
{
    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function doOperation(): array
    {
        $data = $this->getRequest('data');
        $notificationType = $this->getIntegerValueOrFailByFieldName('notificationType', $data);

        $result = new NotificationResult();

        try {
            $resellerId = $this->getIntegerValueOrFailByFieldName('resellerId', $data);
        } catch (InvalidArgumentException $exception) {
            return $result->setErrorMessage('Empty resellerId')
                ->toArray();
        }

        $clientId = $this->getIntegerValueOrFailByFieldName('clientId', $data);
        $creatorId = $this->getIntegerValueOrFailByFieldName('creatorId', $data);
        $expertId = $this->getIntegerValueOrFailByFieldName('expertId', $data);
        $differences = $data['differences'] ?? [];

        // Отдельным блоком, чтобы не выполнялись запросы в БД или сервисы если нет всех валидных данных
        $reseller = $this->getResellerOrFailById($resellerId);
        $client = $this->getClientOrFailById($clientId);

        try {
            $templateData = array_merge(
                $this->getCreatorTemplateData($this->getEmployeeOrFailById($creatorId, 'Creator')),
                $this->getExpertTemplateData($this->getEmployeeOrFailById($expertId, 'Expert')),
                $this->getClientTemplateData($client),
                $this->getOtherTemplateData($data),
                $this->getDifferencesTemplateData($notificationType, $reseller, $differences),
            );
        } catch (Throwable $exception) {
            // Если хоть одна переменная для шаблона не задана, то не отправляем уведомления
            throw new DomainException("Template Data is wrong!", 500, $exception);
        }

        $emailFrom = getResellerEmailFrom();
        if (empty($emailFrom)) {
            return $result->toArray();
        }

        try {
            $emailSent = $this->sendComplaintEmployeeEmail($reseller, $emailFrom, $data);
            $result->setEmployeeNotifiedViaEmail($emailSent);
        } catch (Throwable $exception) {
        }

        // Шлём клиентское уведомление, только если произошла смена статуса
        $needSendClientNotification = $notificationType === ReturnOperationTypes::CHANGE;

        try {
            $emailSent = $needSendClientNotification
                && $client->needNotificationByEmail()
                && $this->sendComplaintClientEmail($client, $reseller, $emailFrom, $templateData, $differences);

            $result->setClientNotifiedViaEmail($emailSent);
        } catch (Throwable $exception) {
        }

        try {
            $smsSent = $needSendClientNotification
                && $client->needNotificationBySms()
                && $this->sendComplaintClientSms($client, $reseller, $templateData, $differences);

            $result->setClientNotifiedViaSms($smsSent);
        } catch (Throwable $exception) {
            $result->setErrorMessage($exception->getMessage());
        }

        return $result->toArray();
    }

    private function getValueOrFailByFieldName(string $fieldName, array $requestData): string
    {
        $value = $requestData[$fieldName] ?? null;
        if ($value === null || trim($value) === '') {
            throw new InvalidArgumentException("Empty {$fieldName}", 400);
        }

        return $value;
    }

    private function getIntegerValueOrFailByFieldName(string $fieldName, array $requestData): int
    {
        return (int)$this->getValueOrFailByFieldName($fieldName, $requestData);
    }

    private function getResellerOrFailById(int $resellerId): Seller
    {
        $reseller = Seller::getById($resellerId);
        if (!$reseller instanceof Seller) {
            throw new RuntimeException('Seller not found!', 400);
        }

        return $reseller;
    }

    private function getClientOrFailById(int $clientId): Contractor
    {
        $client = Contractor::getById($clientId);
        if (
            !$client instanceof Contractor
            || $client->type !== Contractor::TYPE_CUSTOMER
            || $client->seller->id !== $clientId // возможно тут ошибка и проверка должна быть на равенство
        ) {
            throw new RuntimeException('Client not found!', 400);
        }

        return $client;
    }

    private function getEmployeeOrFailById(int $employeeId, string $employeeType): Employee
    {
        $employee = Employee::getById($employeeId);
        if (!$employee instanceof Employee) {
            throw new RuntimeException("{$employeeType} not found!", 400);
        }

        return $employee;
    }

    private function getCreatorTemplateData(Employee $creator): array
    {
        return [
            'CREATOR_ID' => $creator->id,
            'CREATOR_NAME' => $creator->getFullName(),
        ];
    }

    private function getExpertTemplateData(Employee $expert): array
    {
        return [
            'EXPERT_ID' => $expert->id,
            'EXPERT_NAME' => $expert->getFullName(),
        ];
    }

    private function getClientTemplateData(Contractor $client): array
    {
        return [
            'CLIENT_ID' => $client->id,
            'CLIENT_NAME' => $client->getFullName() ?: $client->name,
        ];
    }

    private function getOtherTemplateData(array $data): array
    {
        return [
            'COMPLAINT_ID' => $this->getIntegerValueOrFailByFieldName('complaintId', $data),
            'COMPLAINT_NUMBER' => $this->getValueOrFailByFieldName('complaintNumber', $data),
            'CONSUMPTION_ID' => $this->getIntegerValueOrFailByFieldName('consumptionId', $data),
            'CONSUMPTION_NUMBER' => $this->getValueOrFailByFieldName('consumptionNumber', $data),
            'AGREEMENT_NUMBER' => $this->getValueOrFailByFieldName('agreementNumber', $data),
            'DATE' => $this->getValueOrFailByFieldName('date', $data),
        ];
    }

    private function getDifferencesTemplateData(int $notificationType, Seller $reseller, array $differences): array
    {
        if ($notificationType === ReturnOperationTypes::NEW) {
            return [
                'DIFFERENCES' => __('NewPositionAdded', [], $reseller->id),
            ];
        }

        if ($notificationType === ReturnOperationTypes::CHANGE && count($differences)) {
            $from = Status::getName($this->getIntegerValueOrFailByFieldName('from', $differences));
            if ($from === '') {
                throw new InvalidArgumentException('Invalid value differences.from', 400);
            }

            $to = Status::getName($this->getIntegerValueOrFailByFieldName('to', $differences));
            if ($to === '') {
                throw new InvalidArgumentException('Invalid value differences.to', 400);
            }

            return [
                'DIFFERENCES' => __('PositionStatusHasChanged', [
                    'FROM' => $from,
                    'TO' => $to,
                ], $reseller->id),
            ];
        }

        return [];
    }

    /**
     * @throws RuntimeException
     */
    private function sendComplaintEmployeeEmail(Seller $reseller, string $emailFrom, array $templateData): bool
    {
        // Получаем email сотрудников из настроек
        $emails = getEmailsByPermit($reseller->id, 'tsGoodsReturn');
        if (!is_array($emails) || empty($emails)) {
            return false;
        }

        $subject = __('complaintEmployeeEmailSubject', $templateData, $reseller->id);
        $message = __('complaintEmployeeEmailBody', $templateData, $reseller->id);

        $messages = array_map(
        // если версия php >= 7.4 можно использовать стрелочную функцию
            static function (string $emailTo) use ($emailFrom, $subject, $message) {
                return compact('emailFrom', 'emailTo', 'subject', 'message');
            },
            $emails
        );

        NotificationManager::sendMessageToEmployee($messages, $reseller->id, NotificationEvents::CHANGE_RETURN_STATUS);

        return true;
    }

    /**
     * @throws RuntimeException
     */
    private function sendComplaintClientEmail(
        Contractor $client,
        Seller $reseller,
        string $emailFrom,
        array $templateData,
        array $differences
    ): bool {
        $emailTo = $client->email;
        $subject = __('complaintClientEmailSubject', $templateData, $reseller->id);
        $message = __('complaintClientEmailBody', $templateData, $reseller->id);

        $messages = [compact('emailFrom', 'emailTo', 'subject', 'message')];

        $newStatus = $this->getIntegerValueOrFailByFieldName('to', $differences);

        NotificationManager::sendEmailMessageToClient(
            $messages,
            $reseller->id,
            $client->id,
            NotificationEvents::CHANGE_RETURN_STATUS,
            $newStatus
        );

        return true;
    }

    private function sendComplaintClientSms(
        Contractor $client,
        Seller $reseller,
        array $templateData,
        array $differences
    ): bool {
        $newStatus = $this->getIntegerValueOrFailByFieldName('to', $differences);

        NotificationManager::sendSmsMessageToClient(
            $reseller->id,
            $client->id,
            NotificationEvents::CHANGE_RETURN_STATUS,
            $newStatus,
            $templateData
        );

        return true;
    }
}