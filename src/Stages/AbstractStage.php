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
}