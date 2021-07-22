<?php
namespace WoowUpConnectors\Stages\Subscriptions;

use League\Pipeline\StageInterface;

class VTEXSubscriptionDownloader implements StageInterface
{
    public function __invoke($payload)
    {
        if (is_null($payload)) {
            return null;
        }
        if (isset($payload->customerId) && !empty($payload->customerId)) {
            return $payload;
        }
        return null;
    }
}