<?php

namespace WoowUpConnectors\Stages\Products;

use WoowUpConnectors\Stages\DebugUploadStage;

class WoowUpProductDebugger extends DebugUploadStage
{
	public function __invoke($payload)
	{
		foreach ($payload as $p) {
			echo json_encode($p, JSON_PRETTY_PRINT) . PHP_EOL;
		}

		return [];
	}

	public function updateUnavailable($updatedSkus)
	{
		return true;
	}

}