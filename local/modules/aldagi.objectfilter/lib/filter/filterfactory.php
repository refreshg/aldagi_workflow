<?php

namespace Aldagi\ObjectFilter\Filter;

use Bitrix\Crm;
use Bitrix\Main;

Main\Loader::includeModule('crm');

class FilterFactory extends Crm\Filter\Factory
{
	public function getDataProvider(Main\Filter\EntitySettings $settings): Main\Filter\DataProvider
	{
		if (
			$settings instanceof Crm\Filter\ContactSettings
			&& ObjectFilterFields::isSupportedFilterId((string)$settings->getID())
		)
		{
			return new ContactDataProvider($settings);
		}
		if (
			$settings instanceof Crm\Filter\DealSettings
			&& DealDataProvider::isSupportedFilterId((string)$settings->getID())
		)
		{
			return new DealDataProvider($settings);
		}

		return parent::getDataProvider($settings);
	}
}
