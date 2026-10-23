<?php

namespace Aldagi\ObjectFilter\Search;

/**
 * "ობიექტი" smart process (SPA 130) and its bound contacts.
 */
class ObjectSearch extends FactorySearch
{
	public const ENTITY_TYPE_ID = 130;

	private const TYPE_FIELD = 'UF_CRM_2_1723106717724';
	private const SUBTITLE_FIELDS = [
		'UF_CRM_2_1722586103385', // სახელმწიფო ნომერი
		'UF_CRM_2_1729682986245', // ვინკოდი
		'UF_CRM_2_1723107503113', // საკადასტრო კოდი
	];
	/** Object → contact: the object's "Contacts" field, or a parent relation */
	private const CONTACT_BINDING_SQL =
		'SELECT ENTITY_ID AS ITEM_ID, CONTACT_ID FROM b_crm_entity_contact'
		. ' WHERE ENTITY_TYPE_ID = 130 AND ENTITY_ID IN ({ids})'
		. ' UNION SELECT SRC_ENTITY_ID, DST_ENTITY_ID FROM b_crm_entity_relation'
		. ' WHERE SRC_ENTITY_TYPE_ID = 130 AND DST_ENTITY_TYPE_ID = 3 AND SRC_ENTITY_ID IN ({ids})';

	public static function isAvailable(): bool
	{
		return static::isFactoryAvailable(self::ENTITY_TYPE_ID);
	}

	public function __construct()
	{
		parent::__construct(self::ENTITY_TYPE_ID);
	}

	public function getSelectorEntityId(): string
	{
		return 'aldagi-object';
	}

	public function getCaptions(): array
	{
		return [
			'all' => 'ყველა ობიექტი, რომელიც შეიცავს «%s»',
			'found' => 'ნაპოვნია %d ობიექტი',
			'tab' => 'ობიექტები',
			'stub' => 'ჩაწერეთ ობიექტის ნებისმიერი პარამეტრი: სახელი, ნომერი, ვინკოდი...',
		];
	}

	public function getContactIdsByItemIds(array $itemIds): array
	{
		return $this->queryIdsByChunks(
			'SELECT CONTACT_ID FROM (' . self::CONTACT_BINDING_SQL . ') b',
			$itemIds
		);
	}

	public function getItemsInfo(array $keys): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $keys))));
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
		$contactNames = $this->getContactNamesByBinding(self::CONTACT_BINDING_SQL, $ids);

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
				$subtitle[] = implode(', ', array_unique($contactNames[$id]));
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
}
