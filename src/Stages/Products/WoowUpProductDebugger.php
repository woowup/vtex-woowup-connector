<?php

namespace WoowUpConnectors\Stages\Products;

use WoowUpConnectors\Stages\DebugUploadStage;

class WoowUpProductDebugger extends DebugUploadStage
{
	public function __invoke($payload)
	{
		foreach ($payload as $p) {
			var_dump($p);
		}

		return [];
	}

	public function updateUnavailable($updatedSkus)
	{
		return true;
	}

}