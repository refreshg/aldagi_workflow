<?php

namespace Aldagi\ObjectFilter\Search;

use Bitrix\Crm\Category\PermissionEntityTypeHelper;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main;

/**
 * Text search over one CRM entity type (title + scalar user fields), permission-aware.
 */
abstract class FactorySearch implements LookupSearch
{
	protected const ID_CHUNK_SIZE = 5000;

	/** Technical fields (JSON dumps, tables, bug workarounds) are not searched */
	private const IGNORED_FIELD_LABEL_PATTERN = '/json|table|bug/iu';
	private const STRUCTURED_FIELD_SHARE = 0.8;

	protected int $entityTypeId;
	protected Factory $factory;

	/**
	 * @param int[] $itemIds
	 * @return int[]
	 */
	abstract public function getContactIdsByItemIds(array $itemIds): array;

	public static function isAvailable(): bool
	{
		return Main\Loader::includeModule('crm');
	}

	protected static function isFactoryAvailable(int $entityTypeId): bool
	{
		return Main\Loader::includeModule('crm')
			&& Container::getInstance()->getFactory($entityTypeId) !== null;
	}

	public function __construct(int $entityTypeId)
	{
		Main\Loader::includeModule('crm');
		$this->entityTypeId = $entityTypeId;
		$this->factory = Container::getInstance()->getFactory($entityTypeId);
	}

	public function getEntityTypeId(): int
	{
		return $this->entityTypeId;
	}

	/**
	 * @return int[] readable items that are among $itemIds and match the text chips
	 */
	public function findItemIds(array $itemIds, array $texts): array
	{
		$filter = $this->buildLookupFilter(array_map('intval', $itemIds), $texts);

		return !empty($filter) ? $this->getReadableIds($filter) : [];
	}

	public function findKeys(string $text): array
	{
		return $this->getReadableIds($this->buildTextFilter($text), null, true);
	}

	public function getRecentKeys(int $limit): array
	{
		return $this->getReadableIds([], $limit);
	}

	public function filterReadableKeys(array $keys): array
	{
		$ids = array_values(array_filter(array_map('intval', $keys)));

		return !empty($ids) ? $this->getReadableIds(['@ID' => $ids]) : [];
	}

	public function findContactIds(array $keys, array $texts): array
	{
		$itemIds = $this->findItemIds($keys, $texts);

		return !empty($itemIds) ? $this->getContactIdsByItemIds($itemIds) : [];
	}

	/**
	 * Picked items narrow by ID; text chips are grouped by buildChipsFilter().
	 */
	public function buildLookupFilter(array $itemIds, array $texts): array
	{
		$filter = [];
		$itemIds = array_values(array_filter($itemIds));
		if (!empty($itemIds))
		{
			$filter['@ID'] = $itemIds;
		}
		$texts = array_values(array_filter(array_map('trim', $texts), 'strlen'));
		if (!empty($texts))
		{
			$filter[] = $this->buildChipsFilter($texts);
		}

		return $filter;
	}

	/**
	 * Texts matching the same field are alternatives ("toyota", "bmw" → OR),
	 * texts matching different fields refine each other ("honda" AND "სედანი").
	 */
	public function buildChipsFilter(array $texts): array
	{
		$groups = [];
		foreach (array_unique(array_map('mb_strtolower', $texts)) as $text)
		{
			$groupKey = $this->getPrimaryMatchField($text) ?? ('nomatch:' . $text);
			$groups[$groupKey][] = $text;
		}

		$filter = [];
		foreach ($groups as $groupTexts)
		{
			$filter[] = count($groupTexts) === 1
				? $this->buildTextFilter($groupTexts[0])
				: ['LOGIC' => 'OR'] + array_map([$this, 'buildTextFilter'], $groupTexts);
		}

		return $filter;
	}

	/**
	 * Substring search over the title and every scalar user field, including
	 * captions of list (enumeration) and iblock element values.
	 */
	public function buildTextFilter(string $text): array
	{
		return ['LOGIC' => 'OR'] + array_values($this->getTextConditions($text));
	}

	/**
	 * The field where the text matches most items (e.g. "toyota" → make, "სედანი" → body type),
	 * or null when it matches nothing.
	 */
	public function getPrimaryMatchField(string $text): ?string
	{
		$conditions = $this->getTextConditions($text);
		$connection = Main\Application::getConnection();
		$helper = $connection->getSqlHelper();

		$sums = [];
		$aliases = [];
		foreach ($conditions as $fieldName => $condition)
		{
			$alias = 'C' . count($aliases);
			$aliases[$alias] = $fieldName;
			$sums[] = 'SUM(CASE WHEN ' . $this->conditionToSql($condition, $helper) . " THEN 1 ELSE 0 END) AS {$alias}";
		}

		$counts = $connection->query(
			'SELECT ' . implode(', ', $sums) . ' FROM ' . $this->factory->getDataClass()::getTableName()
		)->fetch();

		$countsByField = [];
		foreach ($aliases as $alias => $fieldName)
		{
			$countsByField[$fieldName] = (int)$counts[$alias];
		}
		$maxCount = max($countsByField ?: [0]);
		if ($maxCount === 0)
		{
			return null;
		}

		// TITLE is free text ("TOYOTA RAV 4") repeating make/model, so a structured field
		// (make, model, body type...) with nearly as many matches is the better grouping key
		$structuredCounts = array_diff_key($countsByField, ['TITLE' => true, 'ID' => true]);
		arsort($structuredCounts);
		$bestStructured = array_key_first($structuredCounts);
		if ($bestStructured !== null && $structuredCounts[$bestStructured] >= $maxCount * self::STRUCTURED_FIELD_SHARE)
		{
			return $bestStructured;
		}

		return array_search($maxCount, $countsByField, true);
	}

	/**
	 * @return int[] IDs the current user may read; newest first when limited or $newestFirst
	 */
	public function getReadableIds(array $filter, ?int $limit = null, bool $newestFirst = false): array
	{
		$userPermissions = Container::getInstance()->getUserPermissions();
		if ($userPermissions->getUserId() <= 0)
		{
			return [];
		}

		$permissionHelper = new PermissionEntityTypeHelper($this->entityTypeId);
		$permissionEntityTypes = [];
		if ($this->factory->isCategoriesSupported())
		{
			foreach ($this->factory->getCategories() as $category)
			{
				$permissionEntityTypes[] = $permissionHelper->getPermissionEntityTypeForCategory($category->getId());
			}
		}
		if (empty($permissionEntityTypes))
		{
			$permissionEntityTypes[] = $permissionHelper->getPermissionEntityTypeForCategory(0);
		}

		$parameters = ['select' => ['ID'], 'filter' => $filter];
		if ($limit !== null || $newestFirst)
		{
			$parameters['order'] = ['ID' => 'DESC'];
		}
		if ($limit !== null)
		{
			$parameters['limit'] = $limit;
		}
		$parameters = $userPermissions->itemsList()->applyAvailableItemsGetListParameters(
			$parameters,
			$permissionEntityTypes,
			UserPermissions::OPERATION_READ,
		);

		$ids = [];
		$rows = $this->factory->getDataClass()::getList($parameters);
		while ($row = $rows->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}

	/**
	 * @return array<string, array> field name => ORM condition
	 */
	protected function getTextConditions(string $text): array
	{
		// '%=' (plain LIKE) instead of '%': the latter compares UPPER() values, and PHP uppercases
		// Georgian into Mtavruli while MySQL does not, so Georgian text never matched.
		// Latin case is still ignored thanks to the case-insensitive collation.
		$likeValue = self::toLikeValue($text);
		$conditions = [
			'TITLE' => ['%=TITLE' => $likeValue],
		];
		if (ctype_digit($text))
		{
			$conditions['ID'] = ['=ID' => (int)$text];
		}

		$enumFieldIds = [];
		foreach ($this->factory->getUserFields() as $fieldName => $userField)
		{
			if (($userField['MULTIPLE'] ?? 'N') === 'Y' || $this->isIgnoredUserField($userField))
			{
				continue;
			}

			switch ($userField['USER_TYPE_ID'])
			{
				case 'string':
				case 'money':
				case 'url':
					$conditions[$fieldName] = ['%=' . $fieldName => $likeValue];
					break;

				case 'integer':
				case 'double':
					if (is_numeric($text))
					{
						$conditions[$fieldName] = ['=' . $fieldName => $text];
					}
					break;

				case 'enumeration':
					$enumFieldIds[(int)$userField['ID']] = $fieldName;
					break;

				case 'iblock_element':
					$elementIds = $this->findIblockElementIds((int)($userField['SETTINGS']['IBLOCK_ID'] ?? 0), $text);
					if (!empty($elementIds))
					{
						$conditions[$fieldName] = ['@' . $fieldName => $elementIds];
					}
					break;
			}
		}

		foreach ($this->findEnumValueIds($enumFieldIds, $text) as $fieldName => $valueIds)
		{
			$conditions[$fieldName] = ['@' . $fieldName => $valueIds];
		}

		return $conditions;
	}

	/**
	 * @return array<int, string> enum value ID => caption
	 */
	protected function getEnumCaptions(string $fieldName): array
	{
		$userField = $this->factory->getUserFields()[$fieldName] ?? null;
		if (!$userField)
		{
			return [];
		}

		$result = [];
		$enumList = \CUserFieldEnum::GetList([], ['USER_FIELD_ID' => $userField['ID']]);
		while ($enum = $enumList->Fetch())
		{
			$result[(int)$enum['ID']] = (string)$enum['VALUE'];
		}

		return $result;
	}

	/**
	 * @param string $bindingSql query returning (ITEM_ID, CONTACT_ID) pairs, with {ids} placeholder
	 * @return array<int, string[]> item ID => contact names
	 */
	protected function getContactNamesByBinding(string $bindingSql, array $itemIds): array
	{
		$itemIds = array_values(array_filter(array_map('intval', $itemIds)));
		if (empty($itemIds))
		{
			return [];
		}

		$rows = Main\Application::getConnection()->query(
			'SELECT b.ITEM_ID, c.NAME, c.LAST_NAME FROM (' . str_replace('{ids}', implode(',', $itemIds), $bindingSql) . ') b'
			. ' INNER JOIN b_crm_contact c ON c.ID = b.CONTACT_ID'
		);

		$result = [];
		while ($row = $rows->fetch())
		{
			$name = trim($row['NAME'] . ' ' . $row['LAST_NAME']);
			if ($name !== '')
			{
				$result[(int)$row['ITEM_ID']][] = $name;
			}
		}

		return $result;
	}

	/**
	 * @return int[] values of the first selected column for every chunk of $ids
	 */
	protected function queryIdsByChunks(string $sql, array $ids): array
	{
		$connection = Main\Application::getConnection();
		$result = [];
		foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), self::ID_CHUNK_SIZE) as $chunk)
		{
			$rows = $connection->query(str_replace('{ids}', implode(',', $chunk), $sql));
			while ($row = $rows->fetch())
			{
				$result[(int)reset($row)] = true;
			}
		}
		unset($result[0]);

		return array_keys($result);
	}

	private function isIgnoredUserField(array $userField): bool
	{
		$label = $userField['EDIT_FORM_LABEL'] ?? '';
		if (is_array($label))
		{
			$label = implode(' ', $label);
		}

		return preg_match(self::IGNORED_FIELD_LABEL_PATTERN, $label . ' ' . ($userField['XML_ID'] ?? '')) === 1;
	}

	/**
	 * "%text%" with LIKE wildcards in the text escaped, for the ORM '%=' operator.
	 */
	private static function toLikeValue(string $text): string
	{
		return '%' . addcslashes($text, '\\%_') . '%';
	}

	/**
	 * SQL for a single-key condition built by getTextConditions() ('%=F' like, '=F' equals, '@F' in).
	 */
	private function conditionToSql(array $condition, Main\DB\SqlHelper $helper): string
	{
		$key = (string)array_key_first($condition);
		$value = $condition[$key];
		$operator = str_starts_with($key, '%=') ? '%=' : $key[0];
		$column = $helper->quote(substr($key, strlen($operator)));

		return match ($operator)
		{
			'%=' => $column . " LIKE '" . $helper->forSql((string)$value) . "'",
			'@' => $column . ' IN (' . implode(',', array_map('intval', (array)$value)) . ')',
			default => $column . " = '" . $helper->forSql((string)$value) . "'",
		};
	}

	/**
	 * @param array<int, string> $enumFieldIds user field ID => field name
	 * @return array<string, int[]> field name => matching enum value IDs
	 */
	private function findEnumValueIds(array $enumFieldIds, string $text): array
	{
		if (empty($enumFieldIds))
		{
			return [];
		}

		$result = [];
		$enumList = \CUserFieldEnum::GetList([], ['USER_FIELD_ID' => array_keys($enumFieldIds)]);
		while ($enum = $enumList->Fetch())
		{
			if (mb_stripos((string)$enum['VALUE'], $text) !== false)
			{
				$fieldName = $enumFieldIds[(int)$enum['USER_FIELD_ID']] ?? null;
				if ($fieldName)
				{
					$result[$fieldName][] = (int)$enum['ID'];
				}
			}
		}

		return $result;
	}

	/**
	 * @return int[]
	 */
	private function findIblockElementIds(int $iblockId, string $text): array
	{
		if ($iblockId <= 0 || !Main\Loader::includeModule('iblock'))
		{
			return [];
		}

		$ids = \Bitrix\Iblock\ElementTable::getList([
			'select' => ['ID'],
			'filter' => ['=IBLOCK_ID' => $iblockId, '%=NAME' => self::toLikeValue($text)],
			'limit' => 1000,
		])->fetchAll();

		return array_map('intval', array_column($ids, 'ID'));
	}
}
