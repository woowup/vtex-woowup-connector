<?php

namespace WoowUpConnectors\Stages;

use League\Pipeline\StageInterface;

abstract class AbstractStage implements StageInterface
{
	protected $connector;

	public function setConnector($connector)
	{
		$this->connector = $connector;
	}

    public function cleanLocation($locationCleaner, $customer)
    {
        $state = (isset($customer['state']) && !empty($customer['state'])) ? $customer['state'] : '';
        $department = (isset($customer['department']) && !empty($customer['department'])) ? $customer['department'] : '';
        $city = (isset($customer['city']) && !empty($customer['city'])) ? $customer['city'] : '';
        $locationClean = $locationCleaner->getCleanLocation($state, $department, $city);
        foreach ($locationClean as $key => $value) {
            if (empty($value)) {
                if (isset($customer[$key])) {
                    unset($customer[$key]);
                }
            } else {
                $customer[$key] = $value;
            }
        }

        return $customer;
    }
}