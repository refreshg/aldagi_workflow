<?php

namespace Aldagi\ObjectFilter\Filter;

use Aldagi\ObjectFilter\Search\CalculationSearch;
use Aldagi\ObjectFilter\Search\DealSearch;
use Aldagi\ObjectFilter\Search\ObjectSearch;
use Bitrix\Main\Filter\DataProvider;
use Bitrix\Main\Filter\Field;

/**
 * Contact filter lookups "ობიექტი", "დილი", "კალკულაცია" (+ "ობიექტი: ..." fields).
 *
 * Conditions are chained through the object: "Kia Optima" + calculation "5 წელზე" keeps owners
 * whose Kia Optima has that calculation; a deal condition requires the object to belong to the deal.
 * A deal condition alone keeps the deal's contacts.
 */
class ContactLookupFilter
{
	public const DEAL_FIELD_ID = 'ALDAGI_DEAL';
	public const CALCULATION_FIELD_ID = 'ALDAGI_CALCULATION';

	private const DEAL_TITLE = 'დილი';
	private const CALCULATION_TITLE = 'კალკულაცია';

	private DataProvider $ownerProvider;
	private ?ObjectFilterFields $objectFields = null;
	private ?DealSearch $dealSearch = null;
	private ?CalculationSearch $calculationSearch = null;

	public function __construct(DataProvider $ownerProvider)
	{
		$this->ownerProvider = $ownerProvider;
		if (ObjectSearch::isAvailable())
		{
			$this->objectFields = new ObjectFilterFields($ownerProvider, new ObjectSearch());
		}
		if (DealSearch::isAvailable())
		{
			$this->dealSearch = new DealSearch();
		}
		if (CalculationSearch::isAvailable())
		{
			$this->calculationSearch = new CalculationSearch();
		}
	}

	/**
	 * @param Field[] $fields contact filter fields; the standard "ობიექტი" field is replaced
	 * @return Field[]
	 */
	public function extendFields(array $fields): array
	{
		if ($this->objectFields)
		{
			$lookupFieldId = ObjectFilterFields::getLookupFieldId();
			$fields[$lookupFieldId] = $this->objectFields->createLookupField($fields[$lookupFieldId] ?? null);
		}
		if ($this->dealSearch)
		{
			$fields[self::DEAL_FIELD_ID] = LookupHelper::createField($this->ownerProvider, self::DEAL_FIELD_ID, self::DEAL_TITLE);
		}
		if ($this->calculationSearch)
		{
			$fields[self::CALCULATION_FIELD_ID] = LookupHelper::createField(
				$this->ownerProvider,
				self::CALCULATION_FIELD_ID,
				self::CALCULATION_TITLE
			);
		}
		if ($this->objectFields)
		{
			$fields += $this->objectFields->prepareFields();
		}

		return $fields;
	}

	/**
	 * @return array|null null when the field is not one of ours
	 */
	public function prepareFieldData(string $fieldId): ?array
	{
		if ($this->objectFields && $fieldId === ObjectFilterFields::getLookupFieldId())
		{
			return $this->objectFields->getLookupFieldData();
		}
		if ($this->dealSearch && $fieldId === self::DEAL_FIELD_ID)
		{
			return LookupHelper::getFieldData($this->dealSearch->getSelectorEntityId(), 'ALDAGI_CONTACT_FILTER_DEAL');
		}
		if ($this->calculationSearch && $fieldId === self::CALCULATION_FIELD_ID)
		{
			return LookupHelper::getFieldData(
				$this->calculationSearch->getSelectorEntityId(),
				'ALDAGI_CONTACT_FILTER_CALCULATION'
			);
		}
		if ($this->objectFields && ObjectFilterFields::isOwnFieldId($fieldId))
		{
			return $this->objectFields->prepareFieldData($fieldId);
		}

		return null;
	}

	public function isOwnFieldId(string $fieldId): bool
	{
		return in_array($fieldId, [ObjectFilterFields::getLookupFieldId(), self::DEAL_FIELD_ID, self::CALCULATION_FIELD_ID], true)
			|| ObjectFilterFields::isOwnFieldId($fieldId);
	}

	/**
	 * Replaces all lookup conditions with '@ID' => [contact ids].
	 */
	public function applyToContactFilter(array &$contactFilter): void
	{
		$objectIdSets = [];

		$objectIds = $this->objectFields?->extractObjectIds($contactFilter);
		if ($objectIds !== null)
		{
			$objectIdSets[] = $objectIds;
		}

		$calculationValues = $this->calculationSearch
			? LookupHelper::extractValues($contactFilter, self::CALCULATION_FIELD_ID)
			: null;
		if ($calculationValues !== null)
		{
			$objectIdSets[] = $this->calculationSearch->findObjectIds(...$calculationValues);
		}

		$dealValues = $this->dealSearch
			? LookupHelper::extractValues($contactFilter, self::DEAL_FIELD_ID, fn($value) => (int)$value)
			: null;
		$dealIds = $dealValues !== null ? $this->dealSearch->findItemIds(...$dealValues) : null;

		if (!empty($objectIdSets))
		{
			if ($dealIds !== null)
			{
				$objectIdSets[] = !empty($dealIds) ? $this->dealSearch->getObjectIdsByItemIds($dealIds) : [];
			}
			$matchedObjectIds = count($objectIdSets) > 1 ? array_intersect(...$objectIdSets) : $objectIdSets[0];
			$contactIds = !empty($matchedObjectIds)
				? (new ObjectSearch())->getContactIdsByItemIds($matchedObjectIds)
				: [];
		}
		elseif ($dealIds !== null)
		{
			$contactIds = !empty($dealIds) ? $this->dealSearch->getContactIdsByItemIds($dealIds) : [];
		}
		else
		{
			return;
		}

		LookupHelper::restrictContactIds($contactFilter, $contactIds);
	}
}
