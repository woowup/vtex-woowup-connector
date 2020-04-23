<?php

namespace WoowUpConnectors\Stages\Customers;

use League\Pipeline\StageInterface;

class VTEXCustomerDownloader implements StageInterface
{
	protected $vtexConnector;
	protected $dataEntity;

	public function __construct($vtexConnector, $dataEntity)
	{
		$this->vtexConnector = $vtexConnector;
		$this->dataEntity    = $dataEntity;

		return $this;
	}

	public function __invoke($payload)
	{
		try {
			return $this->vtexConnector->downloadCustomer($payload, $this->dataEntity);
		} catch (\Exception $e) {
			return null;
		}
	}
}