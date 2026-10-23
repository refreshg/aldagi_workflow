<?php

namespace Aldagi\ObjectFilter\EntitySelector;

use Aldagi\ObjectFilter\Search\LookupSearch;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;
use Bitrix\UI\EntitySelector\Tab;

/**
 * Entity selector over a LookupSearch: searches by any field and offers
 * "ყველა ..., რომელიც შეიცავს «X»" as the first item.
 */
abstract class LookupProvider extends BaseProvider
{
	private const SEARCH_LIMIT = 50;
	private const RECENT_LIMIT = 20;

	private ?LookupSearch $search = null;

	abstract protected function createSearch(): LookupSearch;

	/**
	 * @return class-string<LookupSearch>
	 */
	abstract protected function getSearchClass(): string;

	public function __construct(array $options = [])
	{
		parent::__construct();
		$this->options = $options;
	}

	public function isAvailable(): bool
	{
		global $USER;

		return $USER instanceof \CUser && $USER->isAuthorized() && $this->getSearchClass()::isAvailable();
	}

	public function getItems(array $ids): array
	{
		$items = [];
		$keys = [];
		foreach ($ids as $id)
		{
			if (is_string($id) && str_starts_with($id, LookupSearch::TEXT_VALUE_PREFIX))
			{
				$items[] = $this->makeTextItem(mb_substr($id, mb_strlen(LookupSearch::TEXT_VALUE_PREFIX)), null);
			}
			elseif ($id !== '' && $id !== null)
			{
				$keys[] = $id;
			}
		}

		if (!empty($keys))
		{
			array_push($items, ...$this->makeItems($this->getSearch()->filterReadableKeys($keys)));
		}

		return $items;
	}

	public function getSelectedItems(array $ids): array
	{
		return $this->getItems($ids);
	}

	public function fillDialog(Dialog $dialog): void
	{
		$captions = $this->getSearch()->getCaptions();
		$dialog->addTab(new Tab([
			'id' => $this->getSearch()->getSelectorEntityId(),
			'title' => $captions['tab'],
			'stub' => true,
			'stubOptions' => ['title' => $captions['stub']],
		]));

		$dialog->addItems($this->makeItems($this->getSearch()->getRecentKeys(self::RECENT_LIMIT)));
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$searchQuery->setCacheable(false);

		$text = trim($searchQuery->getQuery());
		if ($text === '')
		{
			return;
		}

		$keys = $this->getSearch()->findKeys($text);
		if (empty($keys))
		{
			return;
		}

		$dialog->addItem($this->makeTextItem($text, count($keys)));
		$dialog->addItems($this->makeItems(array_slice($keys, 0, self::SEARCH_LIMIT)));
	}

	private function makeTextItem(string $text, ?int $count): Item
	{
		$captions = $this->getSearch()->getCaptions();

		return new Item([
			// lowercase id: "Toyota" and "toyota" are the same choice, not two dropdown rows
			'id' => LookupSearch::TEXT_VALUE_PREFIX . mb_strtolower($text),
			'entityId' => $this->getSearch()->getSelectorEntityId(),
			'title' => sprintf($captions['all'], $text),
			'subtitle' => $count !== null ? sprintf($captions['found'], $count) : '',
			'tabs' => $this->getSearch()->getSelectorEntityId(),
			'sort' => 0,
		]);
	}

	/**
	 * @return Item[]
	 */
	private function makeItems(array $keys): array
	{
		$items = [];
		$sort = 1;
		foreach ($this->getSearch()->getItemsInfo($keys) as $key => $info)
		{
			$items[] = new Item([
				'id' => $key,
				'entityId' => $this->getSearch()->getSelectorEntityId(),
				'title' => $info['title'],
				'subtitle' => $info['subtitle'],
				'link' => $info['url'],
				'linkTitle' => 'გახსნა',
				'tabs' => $this->getSearch()->getSelectorEntityId(),
				'sort' => $sort++,
			]);
		}

		return $items;
	}

	private function getSearch(): LookupSearch
	{
		return $this->search ??= $this->createSearch();
	}
}
