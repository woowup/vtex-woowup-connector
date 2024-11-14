<?php

namespace WoowUpConnectors\Stages\Orders;

use League\Pipeline\StageInterface;

class WoowUpCCInfoStage implements StageInterface
{
    const HTTP_NOT_FOUND = 404;
    const HTTP_SERVICE_UNAVAILABLE = 503;

    const HTTP_BAD_GATEWAY = 502;

    protected static $httpCodes = [
        self::HTTP_NOT_FOUND,
        self::HTTP_SERVICE_UNAVAILABLE,
        self::HTTP_BAD_GATEWAY
    ];

    protected $woowupClient;
    protected $logger;
    protected $errorHandler;

    public function __construct($woowupClient, $logger, $errorHandler)
    {
        $this->woowupClient     = $woowupClient;
        $this->logger           = $logger;
        $this->errorHandler     = $errorHandler;
    }

    public function __invoke($payload)
    {
        if (is_null($payload)) {
            return null;
        }

        $order = $payload;

        if ($_payment = $this->addBankInfo($order['payment'])) {
            $order['payment'] = $_payment;
        }

        return $order;
    }

    protected function addBankInfo($payments)
    {
        if (!$payments) return;

        $info = [];
        foreach ($payments as $payment) {
            if (!isset($payment['first_digits'])) continue;

            try {
                $result = $this->woowupClient->banks->getDataFromFirstSixDigits($payment['first_digits']);
            } catch (\Exception $e) {
                if (method_exists($e, 'hasResponse') && $e->hasResponse() && (in_array($e->getResponse()->getStatusCode(), self::$httpCodes))) {
                    $this->logger->info("Bank info not found");
                    $this->logger->info("Error: Code ". $e->getResponse()->getStatusCode(). ", Message: ".$e->getMessage());
                } else {
                    $this->logger->info(" Error: Code '" . $e->getCode() . "', Message '" . $e->getMessage() . "'");
                }
                continue;
            }

            if (!isset($result)) {
                continue;
            }

            if (empty($payment['type']) && !empty($result->type)) {
                $payment['type'] = $result->type;
            }

            if (empty($payment['brand']) && !empty($result->scheme)) {
                $payment['brand'] = $result->scheme;
            }

            if (empty($payment['bank']) && isset($result->bank) && !empty($result->bank->name)) {
                $payment['bank'] = $result->bank->name;
            }

            $info[] = $payment;
        }

        return $info;
    }}