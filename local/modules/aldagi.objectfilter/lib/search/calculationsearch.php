<?php

namespace Aldagi\ObjectFilter\Search;

use Bitrix\Crm\Service\Container;
use Bitrix\Main;

/**
 * All "კალკულაციები" smart processes as one lookup. Keys are "<entityTypeId>:<id>".
 */
class CalculationSearch implements LookupSearch
{
	public const SELECTOR_ENTITY_ID = 'aldagi-calculation';
	public const CAPTIONS = [
		'all' => 'ყველა კალკულაცია, რომელიც შეიცავს «%s»',
		'found' => 'ნაპოვნია %d კალკულაცია',
		'tab' => 'კალკულაციები',
		'stub' => 'ჩაწერეთ კალკულაციის ნებისმიერი პარამეტრი: პროდუქტი, მწარმოებელი, პრემია...',
	];
	/** 1040 "კალკულაციები", 1054 "კალკულაციები (უძრავი ქონება)", 1080 "კალკულაციები" */
	private const ENTITY_TYPE_IDS = [1040, 1054, 1080];

	/** @var array<int, CalculationTypeSearch>|null */
	private ?array $typeSearches = null;

	public static function isAvailable(): bool
	{
		if (!Main\Loader::includeModule('crm'))
		{
			return false;
		}
		foreach (self::ENTITY_TYPE_IDS as $entityTypeId)
		{
			if (Container::getInstance()->getFactory($entityTypeId))
			{
				return true;
			}
		}

		return false;
	}

	public function getSelectorEntityId(): string
	{
		return self::SELECTOR_ENTITY_ID;
	}

	public function getCaptions(): array
	{
		return self::CAPTIONS;
	}

	public function findKeys(string $text): array
	{
		$keys = [];
		foreach ($this->getTypeSearches() as $entityTypeId => $search)
		{
			$keys = array_merge($keys, $this->toKeys($entityTypeId, $search->findKeys($text)));
		}

		return $keys;
	}

	public function getRecentKeys(int $limit): array
	{
		$keys = [];
		foreach ($this->getTypeSearches() as $entityTypeId => $search)
		{
			$keys = array_merge($keys, $this->toKeys($entityTypeId, $search->getRecentKeys($limit)));
		}

		return array_slice($keys, 0, $limit);
	}

	public function filterReadableKeys(array $keys): array
	{
		$result = [];
		foreach ($this->groupKeys($keys) as $entityTypeId => $ids)
		{
			$readable = $this->getTypeSearches()[$entityTypeId]->filterReadableKeys($ids);
			$result = array_merge($result, $this->toKeys($entityTypeId, $readable));
		}

		return $result;
	}

	public function getItemsInfo(array $keys): array
	{
		$infoByKey = [];
		foreach ($this->groupKeys($keys) as $entityTypeId => $ids)
		{
			foreach ($this->getTypeSearches()[$entityTypeId]->getItemsInfo($ids) as $id => $info)
			{
				$infoByKey[$entityTypeId . ':' . $id] = $info;
			}
		}

		$result = [];
		foreach ($keys as $key)
		{
			if (isset($infoByKey[$key]))
			{
				$result[$key] = $infoByKey[$key];
			}
		}

		return $result;
	}

	public function findContactIds(array $keys, array $texts): array
	{
		$objectIds = $this->findObjectIds($keys, $texts);

		return !empty($objectIds) ? (new ObjectSearch())->getContactIdsByItemIds($objectIds) : [];
	}

	/**
	 * Parent objects of the calculations that are among $keys and match the text chips.
	 *
	 * @return int[]
	 */
	public function findObjectIds(array $keys, array $texts): array
	{
		$pickedByType = $this->groupKeys($keys);
		$objectIds = [];
		foreach ($this->getTypeSearches() as $entityTypeId => $search)
		{
			// with picked calculations, only their types count
			if (!empty($pickedByType) && !isset($pickedByType[$entityTypeId]))
			{
				continue;
			}

			$itemIds = $search->findItemIds($pickedByType[$entityTypeId] ?? [], $texts);
			if (!empty($itemIds))
			{
				$objectIds = array_merge($objectIds, $search->getObjectIdsByItemIds($itemIds));
			}
		}

		return array_values(array_unique($objectIds));
	}

	/**
	 * @return array<int, int[]> entityTypeId => item IDs
	 */
	private function groupKeys(array $keys): array
	{
		$result = [];
		foreach ($keys as $key)
		{
			[$entityTypeId, $id] = array_map('intval', explode(':', (string)$key, 2) + [1 => 0]);
			if ($id > 0 && isset($this->getTypeSearches()[$entityTypeId]))
			{
				$result[$entityTypeId][] = $id;
			}
		}

		return $result;
	}

	/**
	 * @return string[]
	 */
	private function toKeys(int $entityTypeId, array $ids): array
	{
		return array_map(fn($id) => $entityTypeId . ':' . $id, $ids);
	}

	/**
	 * @return array<int, CalculationTypeSearch>
	 */
	private function getTypeSearches(): array
	{
		if ($this->typeSearches === null)
		{
			$this->typeSearches = [];
			foreach (self::ENTITY_TYPE_IDS as $entityTypeId)
			{
				if (Container::getInstance()->getFactory($entityTypeId))
				{
					$this->typeSearches[$entityTypeId] = new CalculationTypeSearch($entityTypeId);
				}
			}
		}

		return $this->typeSearches;
	}
}
