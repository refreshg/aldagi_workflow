<?php

namespace Aldagi\ObjectFilter\Filter;

use Aldagi\ObjectFilter\ObjectSearch;
use Bitrix\Crm;

/**
 * Contact filter data provider extended with the "ობიექტი" lookup and fields of the linked objects.
 */
class ContactDataProvider extends Crm\Filter\ContactDataProvider
{
	private ?ObjectFilterFields $objectFields = null;

	public function prepareFields()
	{
		$fields = parent::prepareFields();
		$objectFields = $this->getObjectFields();
		if ($objectFields)
		{
			$lookupFieldId = ObjectFilterFields::getLookupFieldId();
			$fields[$lookupFieldId] = $objectFields->createLookupField($fields[$lookupFieldId] ?? null);
			$fields += $objectFields->prepareFields();
		}

		return $fields;
	}

	public function prepareFieldData($fieldID)
	{
		$objectFields = $this->getObjectFields();
		if ($objectFields && $fieldID === ObjectFilterFields::getLookupFieldId())
		{
			return $objectFields->getLookupFieldData();
		}
		if ($objectFields && ObjectFilterFields::isOwnFieldId((string)$fieldID))
		{
			return $objectFields->prepareFieldData((string)$fieldID);
		}

		return parent::prepareFieldData($fieldID);
	}

	/**
	 * Called by the contact list (and other consumers of the contact filter) right after
	 * the raw UI filter is read, so it is the place to turn object conditions into an ID condition.
	 */
	public function applyActivityFastSearchFilter(int $entityTypeId, array &$filterFields): void
	{
		parent::applyActivityFastSearchFilter($entityTypeId, $filterFields);

		if ($entityTypeId === \CCrmOwnerType::Contact)
		{
			$this->getObjectFields()?->applyToContactFilter($filterFields);
		}
	}

	private function getObjectFields(): ?ObjectFilterFields
	{
		if ($this->objectFields === null && ObjectSearch::isAvailable())
		{
			$this->objectFields = new ObjectFilterFields($this, new ObjectSearch());
		}

		return $this->objectFields;
	}
}
