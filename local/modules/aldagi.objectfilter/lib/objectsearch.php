<?php

namespace Aldagi\ObjectFilter;

use Bitrix\Crm\Category\PermissionEntityTypeHelper;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main;

/**
 * Search over the "ობიექტი" smart process (SPA 130) items and their bound contacts.
 */
class ObjectSearch
{
	public const ENTITY_TYPE_ID = 130;
	/** Lookup value meaning "every object that matches this text" */
	public const TEXT_VALUE_PREFIX = 'search:';

	private const TYPE_FIELD = 'UF_CRM_2_1723106717724';
	private const SUBTITLE_FIELDS = [
		'UF_CRM_2_1722586103385', // სახელმწიფო ნომერი
		'UF_CRM_2_1729682986245', // ვინკოდი
		'UF_CRM_2_1723107503113', // საკადასტრო კოდი
	];
	private const ID_CHUNK_SIZE = 5000;

	private Factory $factory;

	public static function isAvailable(): bool
	{
		return Main\Loader::includeModule('crm')
			&& Container::getInstance()->getFactory(self::ENTITY_TYPE_ID) !== null;
	}

	public function __construct()
	{
		Main\Loader::includeModule('crm');
		$this->factory = Container::getInstance()->getFactory(self::ENTITY_TYPE_ID);
	}

	/**
	 * Substring search over the title and every scalar user field, including
	 * captions of list (enumeration) and iblock element values.
	 */
	public function buildTextFilter(string $text): array
	{
		$conditions = [
			'LOGIC' => 'OR',
			['%TITLE' => $text],
		];
		if (ctype_digit($text))
		{
			$conditions[] = ['=ID' => (int)$text];
		}

		$enumFieldIds = [];
		foreach ($this->factory->getUserFields() as $fieldName => $userField)
		{
			if (($userField['MULTIPLE'] ?? 'N') === 'Y')
			{
				continue;
			}

			switch ($userField['USER_TYPE_ID'])
			{
				case 'string':
				case 'money':
				case 'url':
					$conditions[] = ['%' . $fieldName => $text];
					break;

				case 'integer':
				case 'double':
					if (is_numeric($text))
					{
						$conditions[] = ['=' . $fieldName => $text];
					}
					break;

				case 'enumeration':
					$enumFieldIds[(int)$userField['ID']] = $fieldName;
					break;

				case 'iblock_element':
					$elementIds = $this->findIblockElementIds((int)($userField['SETTINGS']['IBLOCK_ID'] ?? 0), $text);
					if (!empty($elementIds))
					{
						$conditions[] = ['@' . $fieldName => $elementIds];
					}
					break;
			}
		}

		foreach ($this->findEnumValueIds($enumFieldIds, $text) as $fieldName => $valueIds)
		{
			$conditions[] = ['@' . $fieldName => $valueIds];
		}

		return $conditions;
	}

	/**
	 * @return int[] IDs of objects the current user may read, newest first when limited
	 */
	public function getReadableObjectIds(array $filter, ?int $limit = null): array
	{
		$userPermissions = Container::getInstance()->getUserPermissions();
		if ($userPermissions->getUserId() <= 0)
		{
			return [];
		}

		$permissionHelper = new PermissionEntityTypeHelper(self::ENTITY_TYPE_ID);
		$permissionEntityTypes = [];
		foreach ($this->factory->getCategories() as $category)
		{
			$permissionEntityTypes[] = $permissionHelper->getPermissionEntityTypeForCategory($category->getId());
		}
		if (empty($permissionEntityTypes))
		{
			$permissionEntityTypes[] = $permissionHelper->getPermissionEntityTypeForCategory(0);
		}

		$parameters = ['select' => ['ID'], 'filter' => $filter];
		if ($limit !== null)
		{
			$parameters['order'] = ['ID' => 'DESC'];
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
	 * Contacts linked to the objects either through the object's "Contacts" field
	 * or through a parent relation.
	 *
	 * @param int[] $objectIds
	 * @return int[]
	 */
	public function getContactIds(array $objectIds): array
	{
		$connection = Main\Application::getConnection();
		$contactIds = [];
		foreach (array_chunk(array_unique(array_map('intval', $objectIds)), self::ID_CHUNK_SIZE) as $chunk)
		{
			$idList = implode(',', $chunk);
			$rows = $connection->query(
				'SELECT CONTACT_ID FROM b_crm_entity_contact'
				. ' WHERE ENTITY_TYPE_ID = ' . self::ENTITY_TYPE_ID . " AND ENTITY_ID IN ({$idList})"
				. ' UNION SELECT DST_ENTITY_ID FROM b_crm_entity_relation'
				. ' WHERE SRC_ENTITY_TYPE_ID = ' . self::ENTITY_TYPE_ID
				. ' AND DST_ENTITY_TYPE_ID = ' . \CCrmOwnerType::Contact . " AND SRC_ENTITY_ID IN ({$idList})"
			);
			while ($row = $rows->fetch())
			{
				$contactIds[(int)$row['CONTACT_ID']] = true;
			}
		}

		return array_keys($contactIds);
	}

	/**
	 * @param int[] $ids
	 * @return array<int, array{title: string, subtitle: string, url: string}> in the order of $ids
	 */
	public function getObjectsInfo(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		if (empty($ids))
		{
			return [];
		}

		$rows = $this->factory->getDataClass()::getList([
			'select' => array_merge(['ID', 'TITLE', self::TYPE_FIELD], self::SUBTITLE_FIELDS),
			'filter' => ['@ID' => $ids],
		])->fetchAll();
		$rowsById = array_column($rows, null, 'ID');

		$typeCaptions = $this->getEnumCaptions(self::TYPE_FIELD);
		$contactNames = $this->getContactNames($ids);

		$result = [];
		foreach ($ids as $id)
		{
			$row = $rowsById[$id] ?? null;
			if (!$row)
			{
				continue;
			}

			$subtitle = [];
			if (!empty($row[self::TYPE_FIELD]) && isset($typeCaptions[(int)$row[self::TYPE_FIELD]]))
			{
				$subtitle[] = $typeCaptions[(int)$row[self::TYPE_FIELD]];
			}
			foreach (self::SUBTITLE_FIELDS as $fieldName)
			{
				if (trim((string)$row[$fieldName]) !== '')
				{
					$subtitle[] = trim((string)$row[$fieldName]);
				}
			}
			if (!empty($contactNames[$id]))
			{
				$subtitle[] = implode(', ', $contactNames[$id]);
			}

			$title = trim((string)$row['TITLE']);
			$result[$id] = [
				'title' => $title !== '' ? $title : ('ობიექტი #' . $id),
				'subtitle' => implode(' · ', $subtitle),
				'url' => '/crm/type/' . self::ENTITY_TYPE_ID . '/details/' . $id . '/',
			];
		}

		return $result;
	}

	/**
	 * @param int[] $objectIds
	 * @return array<int, string[]> object ID => contact names
	 */
	private function getContactNames(array $objectIds): array
	{
		$rows = Main\Application::getConnection()->query(
			'SELECT ec.ENTITY_ID, c.NAME, c.LAST_NAME FROM b_crm_entity_contact ec'
			. ' INNER JOIN b_crm_contact c ON c.ID = ec.CONTACT_ID'
			. ' WHERE ec.ENTITY_TYPE_ID = ' . self::ENTITY_TYPE_ID
			. ' AND ec.ENTITY_ID IN (' . implode(',', $objectIds) . ')'
		);

		$result = [];
		while ($row = $rows->fetch())
		{
			$name = trim($row['NAME'] . ' ' . $row['LAST_NAME']);
			if ($name !== '')
			{
				$result[(int)$row['ENTITY_ID']][] = $name;
			}
		}

		return $result;
	}

	/**
	 * @return array<int, string> enum value ID => caption
	 */
	private function getEnumCaptions(string $fieldName): array
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
			'filter' => ['=IBLOCK_ID' => $iblockId, '%NAME' => $text],
			'limit' => 1000,
		])->fetchAll();

		return array_map('intval', array_column($ids, 'ID'));
	}
}
