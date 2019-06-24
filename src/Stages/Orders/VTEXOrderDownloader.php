<?php

namespace WoowUpConnectors\Stages\Orders;

use League\Pipeline\StageInterface;

class VTEXOrderDownloader implements StageInterface
{
	protected $vtexConnector;

	public function __construct($vtexConnector)
	{
		$this->vtexConnector = $vtexConnector;
	}

	public function __invoke($payload)
	{
		return $this->vtexConnector->downloadOrder($payload);
	}
}