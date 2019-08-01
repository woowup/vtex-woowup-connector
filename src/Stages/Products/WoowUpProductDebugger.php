<?php

namespace WoowUpConnectors\Stages\Products;

use WoowUpConnectors\Stages\DebugUploadStage;

class WoowUpProductDebugger extends DebugUploadStage
{
	public function __invoke($payload)
	{
		foreach ($payload as $p) {
			if (strpos($p['sku'], "1193260")) {
				var_dump([
					'sku' => $p['sku'],
					'name' => $p['name'],
					'base_name' => $p['base_name'],
					'stock' => $p['stock'],
					'available' => $p['available'],
				]);
			}
		}

		return [];
	}

	public function updateUnavailable($updatedSkus)
	{
		return true;
	}

}