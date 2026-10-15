<?php

namespace Aldagi\ObjectFilter\EntitySelector;

use Aldagi\ObjectFilter\ObjectSearch;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;

/**
 * Entity selector of "ობიექტი" items that searches by any object field.
 */
class ObjectProvider extends BaseProvider
{
	public const ENTITY_ID = 'aldagi-object';

	private const SEARCH_LIMIT = 50;
	private const RECENT_LIMIT = 20;

	private ?ObjectSearch $search = null;

	public function __construct(array $options = [])
	{
		parent::__construct();
		$this->options = $options;
	}

	public function isAvailable(): bool
	{
		global $USER;

		return $USER instanceof \CUser && $USER->isAuthorized() && ObjectSearch::isAvailable();
	}

	public function getItems(array $ids): array
	{
		$items = [];
		$objectIds = [];
		foreach ($ids as $id)
		{
			if (is_string($id) && str_starts_with($id, ObjectSearch::TEXT_VALUE_PREFIX))
			{
				$items[] = $this->makeTextItem(mb_substr($id, mb_strlen(ObjectSearch::TEXT_VALUE_PREFIX)), null);
			}
			elseif ((int)$id > 0)
			{
				$objectIds[] = (int)$id;
			}
		}

		if (!empty($objectIds))
		{
			$readableIds = $this->getSearch()->getReadableObjectIds(['@ID' => $objectIds]);
			array_push($items, ...$this->makeObjectItems($readableIds));
		}

		return $items;
	}

	public function getSelectedItems(array $ids): array
	{
		return $this->getItems($ids);
	}

	public function fillDialog(Dialog $dialog): void
	{
		$dialog->addTab(new Tab([
			'id' => self::ENTITY_ID,
			'title' => 'ობიექტები',
			'stub' => true,
			'stubOptions' => ['title' => 'ჩაწერეთ ობიექტის ნებისმიერი პარამეტრი: სახელი, ნომერი, ვინკოდი...'],
		]));

		$dialog->addItems($this->makeObjectItems($this->getSearch()->getReadableObjectIds([], self::RECENT_LIMIT)));
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$searchQuery->setCacheable(false);

		$text = trim($searchQuery->getQuery());
		if ($text === '')
		{
			return;
		}

		$search = $this->getSearch();
		$objectIds = $search->getReadableObjectIds($search->buildTextFilter($text));
		if (empty($objectIds))
		{
			return;
		}

		rsort($objectIds);
		$dialog->addItem($this->makeTextItem($text, count($objectIds)));
		$dialog->addItems($this->makeObjectItems(array_slice($objectIds, 0, self::SEARCH_LIMIT)));
	}

	private function makeTextItem(string $text, ?int $count): Item
	{
		return new Item([
			'id' => ObjectSearch::TEXT_VALUE_PREFIX . $text,
			'entityId' => self::ENTITY_ID,
			'title' => "ყველა ობიექტი, რომელიც შეიცავს «{$text}»",
			'subtitle' => $count !== null ? "ნაპოვნია {$count} ობიექტი" : '',
			'tabs' => self::ENTITY_ID,
			'sort' => 0,
		]);
	}

	/**
	 * @param int[] $objectIds
	 * @return Item[]
	 */
	private function makeObjectItems(array $objectIds): array
	{
		$items = [];
		$sort = 1;
		foreach ($this->getSearch()->getObjectsInfo($objectIds) as $id => $info)
		{
			$items[] = new Item([
				'id' => $id,
				'entityId' => self::ENTITY_ID,
				'title' => $info['title'],
				'subtitle' => $info['subtitle'],
				'link' => $info['url'],
				'linkTitle' => 'გახსნა',
				'tabs' => self::ENTITY_ID,
				'sort' => $sort++,
			]);
		}

		return $items;
	}

	private function getSearch(): ObjectSearch
	{
		return $this->search ??= new ObjectSearch();
	}
}
