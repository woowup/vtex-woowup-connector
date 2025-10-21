<?php

namespace WoowUpConnectors\Stages;

use League\Pipeline\StageInterface;

class DebugUploadStage implements StageInterface
{
	protected $woowupStats;

	public function __construct()
	{
		$this->resetWoowupStats();

		return $this;
	}

	public function __invoke($payload)
	{
		echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;

		return [];
	}

	public function getWoowupStats()
	{
		return $this->woowupStats;
	}

	public function resetWoowupStats()
	{
		$this->woowupStats = [
			'created' => 0,
			'updated' => 0,
			'failed'  => [],
			'orders'  => [
				'created'    => 0,
				'updated'    => 0,
				'duplicated' => 0,
				'failed'     => [],
			],
			'customers' => [
				'created' => 0,
				'updated' => 0,
				'failed'  => [],
			],
		];
	}
}