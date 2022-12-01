<?php

namespace WoowUpConnectors\Stages\Orders;

use League\Pipeline\StageInterface;

class WoowUpCCInfoStage implements StageInterface
{
    const HTTP_NOT_FOUND = 404;
    const HTTP_SERVICE_UNAVAILABLE = 503;

    protected static $httpCodes = [self::HTTP_NOT_FOUND, self::HTTP_SERVICE_UNAVAILABLE];

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
                if ($e->hasResponse() && (in_array($e->getResponse()->getStatusCode(), self::$httpCodes))) {
                    $this->logger->info("Bank info not found");
                    $this->logger->info("Error: Code ". $e->getResponse()->getStatusCode(). ", Message: ".$e->getMessage());
                } else {
                    $this->errorHandler->captureException($e);
                    $this->logger->info(" Error: Code '" . $e->getCode() . "', Message '" . $e->getMessage() . "'");
                }
            }

            if (!isset($result)) {
                continue;
            }

            $result->type && $payment['type'] = $result->type;
            $result->scheme && $payment['brand'] = $result->scheme;
            $result->bank && $result->bank->name && $payment['bank'] = $result->bank->name;
            $info[] = $payment;
        }

        return $info;
    }}