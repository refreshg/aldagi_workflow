<?php

namespace Aldagi\ObjectFilter\Filter;

use Aldagi\ObjectFilter\Search\ObjectSearch;
use Bitrix\Crm;
use Bitrix\Main;

/**
 * Deal filter data provider extended with object fields (make, model, release year):
 * keeps deals that have a matching object (deal → object relation, D-12).
 */
class DealDataProvider extends Crm\Filter\DealDataProvider
{
	public const SUPPORTED_FILTER_ID_PREFIX = 'CRM_DEAL_LIST';

	private const ID_CHUNK_SIZE = 5000;

	private ?ObjectFilterFields $objectFields = null;

	public static function isSupportedFilterId(string $filterId): bool
	{
		return str_starts_with($filterId, self::SUPPORTED_FILTER_ID_PREFIX);
	}

	public function prepareFields()
	{
		$fields = parent::prepareFields();
		$objectFields = $this->getObjectFields();

		return $objectFields ? $fields + $objectFields->prepareFields() : $fields;
	}

	public function prepareFieldData($fieldID)
	{
		$objectFields = $this->getObjectFields();
		if ($objectFields && ObjectFilterFields::isOwnFieldId((string)$fieldID))
		{
			return $objectFields->prepareFieldData((string)$fieldID);
		}

		return parent::prepareFieldData($fieldID);
	}

	/**
	 * Called by the deal list and kanban right after the raw UI filter is read.
	 */
	public function applyActivityFastSearchFilter(int $entityTypeId, array &$filterFields): void
	{
		parent::applyActivityFastSearchFilter($entityTypeId, $filterFields);

		if ($entityTypeId !== \CCrmOwnerType::Deal || !$this->getObjectFields())
		{
			return;
		}

		// the standard "ობიექტი" field is not ours here: only "ობიექტი: ..." fields are taken
		$objectIds = $this->getObjectFields()->extractObjectIds($filterFields, false);
		if ($objectIds === null)
		{
			return;
		}

		LookupHelper::restrictContactIds($filterFields, $this->getDealIdsByObjectIds($objectIds));
	}

	/**
	 * @param int[] $objectIds
	 * @return int[]
	 */
	private function getDealIdsByObjectIds(array $objectIds): array
	{
		$connection = Main\Application::getConnection();
		$dealIds = [];
		foreach (array_chunk(array_values(array_unique(array_map('intval', $objectIds))), self::ID_CHUNK_SIZE) as $chunk)
		{
			$rows = $connection->query(
				'SELECT DISTINCT SRC_ENTITY_ID FROM b_crm_entity_relation'
				. ' WHERE SRC_ENTITY_TYPE_ID = ' . \CCrmOwnerType::Deal
				. ' AND DST_ENTITY_TYPE_ID = ' . ObjectSearch::ENTITY_TYPE_ID
				. ' AND DST_ENTITY_ID IN (' . implode(',', $chunk) . ')'
			);
			while ($row = $rows->fetch())
			{
				$dealIds[] = (int)$row['SRC_ENTITY_ID'];
			}
		}

		return $dealIds;
	}

	private function getObjectFields(): ?ObjectFilterFields
	{
		if ($this->objectFields === null && ObjectSearch::isAvailable())
		{
			$this->objectFields = new ObjectFilterFields($this, new ObjectSearch(), ObjectFilterFields::DEAL_FIELDS);
		}

		return $this->objectFields;
	}
}
