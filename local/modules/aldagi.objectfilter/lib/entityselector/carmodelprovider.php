<?php

namespace Aldagi\ObjectFilter\EntitySelector;

use Aldagi\ObjectFilter\Filter\ObjectFilterFields;
use Aldagi\ObjectFilter\Search\ObjectSearch;
use Bitrix\Crm\Service\Container;
use Bitrix\Main;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;

/**
 * Car models of the "მოდელი" reference (iblock), limited to the makes passed in the
 * makeIds option. A model is linked to its make by the MARK property of the reference.
 */
class CarModelProvider extends BaseProvider
{
	public const ENTITY_ID = 'aldagi-car-model';

	private const MAKE_PROPERTY_CODE = 'MARK';
	private const LIST_LIMIT = 500;
	private const SEARCH_LIMIT = 100;

	public function __construct(array $options = [])
	{
		parent::__construct();
		$this->options['makeIds'] = array_values(array_filter(array_map('intval', (array)($options['makeIds'] ?? []))));
	}

	public function isAvailable(): bool
	{
		global $USER;

		return $USER instanceof \CUser && $USER->isAuthorized()
			&& Main\Loader::includeModule('crm') && Main\Loader::includeModule('iblock')
			&& $this->getModelIblockId() > 0;
	}

	public function getItems(array $ids): array
	{
		return $this->makeItems($this->queryModels(['ids' => array_map('intval', $ids)]));
	}

	public function getSelectedItems(array $ids): array
	{
		return $this->getItems($ids);
	}

	public function fillDialog(Dialog $dialog): void
	{
		$hasMakes = !empty($this->getMakeIds());
		$dialog->addTab(new Tab([
			'id' => self::ENTITY_ID,
			'title' => 'მოდელები',
			'stub' => true,
			'stubOptions' => [
				'title' => $hasMakes ? 'ამ მარკის მოდელები ვერ მოიძებნა' : 'აირჩიეთ მარკა ან ჩაწერეთ მოდელის სახელი',
			],
		]));

		if ($hasMakes)
		{
			$dialog->addItems($this->makeItems($this->queryModels(['limit' => self::LIST_LIMIT])));
		}
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$searchQuery->setCacheable(false);
		$text = trim($searchQuery->getQuery());
		if ($text !== '')
		{
			$dialog->addItems($this->makeItems($this->queryModels(['text' => $text, 'limit' => self::SEARCH_LIMIT])));
		}
	}

	/**
	 * @param array{ids?: int[], text?: string, limit?: int} $params
	 * @return array<array{ID: string, NAME: string, MAKE_NAME: ?string}>
	 */
	private function queryModels(array $params): array
	{
		$modelIblockId = $this->getModelIblockId();
		$makePropertyId = $this->getMakePropertyId();
		if ($modelIblockId <= 0 || $makePropertyId <= 0)
		{
			return [];
		}

		$helper = Main\Application::getConnection()->getSqlHelper();
		$where = ['m.IBLOCK_ID = ' . $modelIblockId, "m.ACTIVE = 'Y'"];
		if (isset($params['ids']))
		{
			$ids = array_filter($params['ids']);
			if (empty($ids))
			{
				return [];
			}
			$where[] = 'm.ID IN (' . implode(',', $ids) . ')';
		}
		$makeIds = $this->getMakeIds();
		if (!isset($params['ids']) && !empty($makeIds))
		{
			$where[] = 'mk.ID IN (' . implode(',', $makeIds) . ')';
		}
		if (($params['text'] ?? '') !== '')
		{
			$like = "'%" . $helper->forSql(addcslashes($params['text'], '\\%_')) . "%'";
			$where[] = "(m.NAME LIKE {$like} OR mk.NAME LIKE {$like} OR CONCAT(mk.NAME, ' ', m.NAME) LIKE {$like})";
		}

		return Main\Application::getConnection()->query(
			'SELECT m.ID, m.NAME, mk.NAME AS MAKE_NAME FROM b_iblock_element m'
			. ' LEFT JOIN b_iblock_element_property p ON p.IBLOCK_ELEMENT_ID = m.ID AND p.IBLOCK_PROPERTY_ID = ' . $makePropertyId
			. ' LEFT JOIN b_iblock_element mk ON mk.ID = p.VALUE'
			. ' WHERE ' . implode(' AND ', $where)
			. ' ORDER BY mk.NAME, m.NAME'
			. (isset($params['limit']) ? ' LIMIT ' . (int)$params['limit'] : '')
		)->fetchAll();
	}

	/**
	 * @return Item[]
	 */
	private function makeItems(array $rows): array
	{
		$items = [];
		foreach ($rows as $row)
		{
			$items[] = new Item([
				'id' => (int)$row['ID'],
				'entityId' => self::ENTITY_ID,
				'title' => (string)$row['NAME'],
				'subtitle' => (string)($row['MAKE_NAME'] ?? ''),
				'tabs' => self::ENTITY_ID,
			]);
		}

		return $items;
	}

	/**
	 * @return int[]
	 */
	private function getMakeIds(): array
	{
		return (array)$this->getOption('makeIds', []);
	}

	private function getModelIblockId(): int
	{
		$userField = Container::getInstance()->getFactory(ObjectSearch::ENTITY_TYPE_ID)
			?->getUserFields()[ObjectFilterFields::MODEL_FIELD] ?? null;

		return (int)($userField['SETTINGS']['IBLOCK_ID'] ?? 0);
	}

	private function getMakePropertyId(): int
	{
		$property = \Bitrix\Iblock\PropertyTable::getList([
			'select' => ['ID'],
			'filter' => ['=IBLOCK_ID' => $this->getModelIblockId(), '=CODE' => self::MAKE_PROPERTY_CODE],
			'cache' => ['ttl' => 3600],
		])->fetch();

		return (int)($property['ID'] ?? 0);
	}
}
