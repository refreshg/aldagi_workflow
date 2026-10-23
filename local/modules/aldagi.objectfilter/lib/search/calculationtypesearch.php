<?php

namespace Aldagi\ObjectFilter\Search;

use Bitrix\Main;

/**
 * One "კალკულაციები" smart process. Calculations reach contacts only through their
 * parent object (130 → calculation in b_crm_entity_relation). Used through CalculationSearch.
 */
class CalculationTypeSearch extends FactorySearch
{
	public function getSelectorEntityId(): string
	{
		return CalculationSearch::SELECTOR_ENTITY_ID;
	}

	public function getCaptions(): array
	{
		return CalculationSearch::CAPTIONS;
	}

	public function getEntityDescription(): string
	{
		return (string)$this->factory->getEntityDescription();
	}

	/**
	 * @param int[] $itemIds
	 * @return int[]
	 */
	public function getObjectIdsByItemIds(array $itemIds): array
	{
		return $this->queryIdsByChunks(
			'SELECT SRC_ENTITY_ID FROM b_crm_entity_relation'
			. ' WHERE SRC_ENTITY_TYPE_ID = ' . ObjectSearch::ENTITY_TYPE_ID
			. ' AND DST_ENTITY_TYPE_ID = ' . $this->entityTypeId . ' AND DST_ENTITY_ID IN ({ids})',
			$itemIds
		);
	}

	public function getContactIdsByItemIds(array $itemIds): array
	{
		$objectIds = $this->getObjectIdsByItemIds($itemIds);

		return !empty($objectIds) ? (new ObjectSearch())->getContactIdsByItemIds($objectIds) : [];
	}

	public function getItemsInfo(array $keys): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $keys))));
		if (empty($ids))
		{
			return [];
		}

		$rows = $this->factory->getDataClass()::getList([
			'select' => ['ID', 'TITLE'],
			'filter' => ['@ID' => $ids],
		])->fetchAll();
		$rowsById = array_column($rows, null, 'ID');
		$objects = $this->getParentObjects($ids);

		$result = [];
		foreach ($ids as $id)
		{
			$row = $rowsById[$id] ?? null;
			if (!$row)
			{
				continue;
			}

			$subtitle = [$this->getEntityDescription()];
			foreach ($objects[$id] ?? [] as $object)
			{
				$subtitle[] = $object['title'] . ($object['subtitle'] !== '' ? ' · ' . $object['subtitle'] : '');
			}

			$title = trim((string)$row['TITLE']);
			$result[$id] = [
				'title' => $title !== '' ? $title : ('კალკულაცია #' . $id),
				'subtitle' => implode(' · ', $subtitle),
				'url' => '/crm/type/' . $this->entityTypeId . '/details/' . $id . '/',
			];
		}

		return $result;
	}

	/**
	 * @return array<int, array<array{title: string, subtitle: string}>> calculation ID => parent objects
	 */
	private function getParentObjects(array $ids): array
	{
		$rows = Main\Application::getConnection()->query(
			'SELECT DST_ENTITY_ID, SRC_ENTITY_ID FROM b_crm_entity_relation'
			. ' WHERE SRC_ENTITY_TYPE_ID = ' . ObjectSearch::ENTITY_TYPE_ID
			. ' AND DST_ENTITY_TYPE_ID = ' . $this->entityTypeId
			. ' AND DST_ENTITY_ID IN (' . implode(',', $ids) . ')'
		)->fetchAll();

		$objectInfo = (new ObjectSearch())->getItemsInfo(array_column($rows, 'SRC_ENTITY_ID'));
		$result = [];
		foreach ($rows as $row)
		{
			$info = $objectInfo[(int)$row['SRC_ENTITY_ID']] ?? null;
			if ($info)
			{
				$result[(int)$row['DST_ENTITY_ID']][] = $info;
			}
		}

		return $result;
	}
}
