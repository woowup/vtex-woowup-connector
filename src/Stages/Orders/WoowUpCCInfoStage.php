<?php

namespace WoowUpConnectors\Stages\Orders;

use League\Pipeline\StageInterface;

class WoowUpCCInfoStage implements StageInterface
{
    protected $woowupClient;
    protected $logger;


    public function __construct($woowupClient, $logger)
    {
        $this->woowupClient     = $woowupClient;
        $this->logger           = $logger;
    }

    public function __invoke($payload)
    {
        if (is_null($payload)) {
            return false;
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
                $response = json_decode($this->woowupClient->banks->getDataFromFirstSixDigits($payment['first_digits']));
            } catch (\Error $e) {
                continue;
            }

            if (!@$response->payload) continue;

            if (!@$response->payload->type) continue;

            $payment['type'] = $response->payload->type;
            $response->payload->scheme && $payment['brand'] = $response->payload->scheme;
            $response->payload->bank->name && $payment['bank'] = $response->payload->bank->name;
            $info[] = $payment;
        }

        return $info;
    }
}