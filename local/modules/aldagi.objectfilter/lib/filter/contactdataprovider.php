<?php

namespace Aldagi\ObjectFilter\Filter;

use Bitrix\Crm;

/**
 * Contact filter data provider extended with the "ობიექტი", "დილი", "კალკულაცია" lookups
 * and fields of the linked objects.
 */
class ContactDataProvider extends Crm\Filter\ContactDataProvider
{
	private ?ContactLookupFilter $lookupFilter = null;

	public function prepareFields()
	{
		return $this->getLookupFilter()->extendFields(parent::prepareFields());
	}

	public function prepareFieldData($fieldID)
	{
		$lookupFilter = $this->getLookupFilter();
		if ($lookupFilter->isOwnFieldId((string)$fieldID))
		{
			return $lookupFilter->prepareFieldData((string)$fieldID);
		}

		return parent::prepareFieldData($fieldID);
	}

	/**
	 * Called by the contact list (and other consumers of the contact filter) right after
	 * the raw UI filter is read, so it is the place to turn lookup conditions into an ID condition.
	 */
	public function applyActivityFastSearchFilter(int $entityTypeId, array &$filterFields): void
	{
		parent::applyActivityFastSearchFilter($entityTypeId, $filterFields);

		if ($entityTypeId === \CCrmOwnerType::Contact)
		{
			$this->getLookupFilter()->applyToContactFilter($filterFields);
		}
	}

	private function getLookupFilter(): ContactLookupFilter
	{
		return $this->lookupFilter ??= new ContactLookupFilter($this);
	}
}
