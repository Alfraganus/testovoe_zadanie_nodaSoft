<?php

namespace NW\WebService\References\Operations\Notification;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW       = 1;
    public const TYPE_CHANGE    = 2;

    public function doOperation(): array
    {
        $data = (array)$this->getRequest('data');
        if (empty($data)) {
            self::ExceptionThrower('Request not found!', 400);
        }

        $resellerId = (int)$data['resellerId'];
        if (empty($resellerId)) {
            $result['notificationClientBySms']['message'] = 'Empty resellerId';
            return $result;
        }

        $notificationType = (int)$data['notificationType'];
        if (empty($notificationType)) {
            self::ExceptionThrower('Empty notificationType!', 400);
        }

        if (!$data['clientId']) {
            self::ExceptionThrower('Client not found!', 400);
        }
        $client = Employee::getById($data['clientId']);
        $this->checkClient($client, $resellerId);

        $cFullName = empty($client->getFullName()) ?? $client->name;

        if (!$data['creatorId']) {
            self::ExceptionThrower('Creator not found!', 400);
        }

        $cr =Employee::getById($data['creatorId']);
        if ($cr === null) {
            self::ExceptionThrower('Creator not found!', 400);
        }
        if (!$data['expertId']) {
            self::ExceptionThrower('Expert not found!', 400);
        }

        $et = Employee::getById($data['expertId']);
        if ($et === null) {
            self::ExceptionThrower('Expert not found!', 400);
        }

        if (!Seller::getById($resellerId)) {
            self::ExceptionThrower('Seller not found!', 400);
        }

        $differences = $this->notificationTypeDifferences($notificationType, $resellerId, $data);

        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail' => false,
            'notificationClientBySms' => [
                'isSent' => false,
                'message' => '',
            ],
        ];

        $templateData = [
            'COMPLAINT_ID'          => (int)$data['complaintId'],
            'COMPLAINT_NUMBER'      => (string)$data['complaintNumber'],
            'CREATOR_ID'            => isset($data['creatorId']) ? (int)$data['creatorId'] : null,
            'CREATOR_NAME'          => $cr->getFullName(),
            'EXPERT_ID'             => isset($data['expertId']) ? (int)$data['expertId'] : null,
            'EXPERT_NAME'           => $et->getFullName(),
            'CLIENT_ID'             => isset($data['clientId']) ? (int)$data['clientId'] : null,
            'CLIENT_NAME'           => $cFullName,
            'CONSUMPTION_ID'        => isset($data['consumptionId']) ? (int)$data['consumptionId'] : null,
            'CONSUMPTION_NUMBER'    => (string)$data['consumptionNumber'],
            'AGREEMENT_NUMBER'      => (string)$data['agreementNumber'],
            'DATE'                  => (string)$data['date'],
            'DIFFERENCES'           => $differences,
        ];

        // Если хоть одна переменная для шаблона не задана, то не отправляем уведомления
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                self::ExceptionThrower("Template Data ({$key}) is empty!", 500);
            }
        }

        $emailFrom = getResellerEmailFrom();
        // Получаем email сотрудников из настроек
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');
        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                $this->sendMessageEmployees($emailFrom, $email, $templateData, $resellerId);
                $result['notificationEmployeeByEmail'] = true;
            }
        }

        // Шлём клиентское уведомление, только если произошла смена статуса
        if ($notificationType === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            if (!empty($emailFrom) && !empty($client->email)) {
                $this->sendMessageClients($emailFrom, $client, $templateData, $resellerId, $data['differences']['to']);
                $result['notificationClientByEmail'] = true;
            }

            if (!empty($client->mobile)) {
                $res = NotificationManager::send(
                    $resellerId,
                    $client->id,
                    NotificationEvents::CHANGE_RETURN_STATUS,
                    (int)$data['differences']['to'],
                    $templateData,
                    $error ?? null
                );
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }
                if (!empty($error)) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }

    private function checkClient($client, $resellerId)
    {
        /** @var TYPE_NAME $client */
        if (!$client) {
            self::ExceptionThrower('сlient not found!', 400);
        } elseif ($client->type !== Contractor::TYPE_CUSTOMER) {
            self::ExceptionThrower('сlient is not customer!', 400);
        } elseif ($client->Seller->id !== $resellerId) {
            self::ExceptionThrower('сlient is not reseller!', 400);
        }
    }

    /**
     * @param $message
     * @param $code
     * @return mixed
     * @throws \Exception
     */
    private static function ExceptionThrower($message, $code)
    {
        throw new \Exception($message, $code);
    }

    /**
     * @param string $emailFrom
     * @param string $email
     * @param array $templateData
     * @param int $resellerId
     * @return void
     */
    private function sendMessageEmployees(string $emailFrom, string $email, array $templateData, int $resellerId): void
    {
        MessagesClient::sendMessage([
            0 => [ // MessageTypes::EMAIL
                'emailFrom' => $emailFrom,
                'emailTo' => $email,
                'subject' => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                'message' => __('complaintEmployeeEmailBody', $templateData, $resellerId),
            ],
        ], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
    }

    /**
     * @param string $emailFrom
     * @param Contractor $client
     * @param array $templateData
     * @param int $resellerId
     * @param $to
     * @return void
     */
    private function sendMessageClients(string $emailFrom, Contractor $client, array $templateData, int $resellerId, $to): void
    {
        MessagesClient::sendMessage([
            0 => [ // MessageTypes::EMAIL
                'emailFrom' => $emailFrom,
                'emailTo' => $client->email,
                'subject' => __('complaintClientEmailSubject', $templateData, $resellerId),
                'message' => __('complaintClientEmailBody', $templateData, $resellerId),
            ],
        ], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$to);
    }

    /**
     * @param int $notificationType
     * @param int $resellerId
     * @param array $data
     * @return array
     */
    private function notificationTypeDifferences(int $notificationType, int $resellerId, array $data)
    {
        $differences = '';

        switch ($notificationType) {
            case self::TYPE_NEW:
                $differences = __('NewPositionAdded', null, $resellerId);
                break;
            case self::TYPE_CHANGE:
                if (!empty($data['differences'])) {
                    $differences = __('PositionStatusHasChanged', [
                        'FROM' => Status::getName((int)$data['differences']['from']),
                        'TO' => Status::getName((int)$data['differences']['to']),
                    ], $resellerId);
                }
                break;
        }
        return $differences;
    }
}