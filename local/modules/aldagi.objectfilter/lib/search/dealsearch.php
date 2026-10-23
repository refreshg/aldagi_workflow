<?php

namespace Aldagi\ObjectFilter\Search;

use Bitrix\Crm\Search\SearchEnvironment;

/**
 * Deals and their contacts. Text search uses the standard deal full-text index
 * (SEARCH_CONTENT, same as the deal list search box): LIKE over 153k deals takes seconds.
 */
class DealSearch extends FactorySearch
{
	private const CONTACT_BINDING_SQL =
		'SELECT DEAL_ID AS ITEM_ID, CONTACT_ID FROM b_crm_deal_contact WHERE DEAL_ID IN ({ids})';

	/** \CCrmOwnerType::Deal, usable before the crm module is loaded */
	private const ENTITY_TYPE_ID = 2;
	private const MIN_WORD_LENGTH = 3;

	public static function isAvailable(): bool
	{
		return static::isFactoryAvailable(self::ENTITY_TYPE_ID);
	}

	public function __construct()
	{
		parent::__construct(self::ENTITY_TYPE_ID);
	}

	/**
	 * Objects that are children of the deals (deal → object relation).
	 *
	 * @param int[] $dealIds
	 * @return int[]
	 */
	public function getObjectIdsByItemIds(array $dealIds): array
	{
		return $this->queryIdsByChunks(
			'SELECT DST_ENTITY_ID FROM b_crm_entity_relation'
			. ' WHERE SRC_ENTITY_TYPE_ID = ' . \CCrmOwnerType::Deal
			. ' AND DST_ENTITY_TYPE_ID = ' . ObjectSearch::ENTITY_TYPE_ID . ' AND SRC_ENTITY_ID IN ({ids})',
			$dealIds
		);
	}

	public function getSelectorEntityId(): string
	{
		return 'aldagi-deal';
	}

	public function getCaptions(): array
	{
		return [
			'all' => 'ყველა დილი, რომელიც შეიცავს «%s»',
			'found' => 'ნაპოვნია %d დილი',
			'tab' => 'დილები',
			'stub' => 'ჩაწერეთ დილის სახელი, ნომერი, კლიენტი...',
		];
	}

	/**
	 * Every word of the text must be present (full-text), so refining happens inside one chip:
	 * "toyota კასკო".
	 */
	public function buildTextFilter(string $text): array
	{
		// words shorter than innodb_ft_min_token_size (3) are dropped by the ORM; with no word left
		// there is no condition at all and every deal would match
		$words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
		if (empty(array_filter($words, fn($word) => mb_strlen($word) >= self::MIN_WORD_LENGTH)))
		{
			return ['=ID' => ctype_digit($text) ? (int)$text : 0];
		}

		$filter = ['SEARCH_CONTENT' => $text];
		SearchEnvironment::prepareSearchFilter(\CCrmOwnerType::Deal, $filter, ['ENABLE_PHONE_DETECTION' => false]);

		return $filter;
	}

	/**
	 * No per-field statistics in a full-text index: several chips are alternatives.
	 */
	public function buildChipsFilter(array $texts): array
	{
		$texts = array_values(array_unique(array_map('mb_strtolower', $texts)));

		return count($texts) === 1
			? $this->buildTextFilter($texts[0])
			: ['LOGIC' => 'OR'] + array_map([$this, 'buildTextFilter'], $texts);
	}

	public function getContactIdsByItemIds(array $itemIds): array
	{
		return $this->queryIdsByChunks(
			'SELECT CONTACT_ID FROM b_crm_deal_contact WHERE DEAL_ID IN ({ids})',
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
			'select' => ['ID', 'TITLE', 'STAGE_ID', 'CATEGORY_ID', 'OPPORTUNITY', 'CURRENCY_ID'],
			'filter' => ['@ID' => $ids],
		])->fetchAll();
		$rowsById = array_column($rows, null, 'ID');
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
			$stageName = $this->getStageName((string)$row['STAGE_ID'], (int)$row['CATEGORY_ID']);
			if ($stageName !== '')
			{
				$subtitle[] = $stageName;
			}
			if ((float)$row['OPPORTUNITY'] > 0)
			{
				$subtitle[] = \CCrmCurrency::MoneyToString($row['OPPORTUNITY'], $row['CURRENCY_ID']);
			}
			if (!empty($contactNames[$id]))
			{
				$subtitle[] = implode(', ', array_unique($contactNames[$id]));
			}

			$title = trim((string)$row['TITLE']);
			$result[$id] = [
				'title' => ($title !== '' ? $title : 'დილი') . ' #' . $id,
				'subtitle' => html_entity_decode(strip_tags(implode(' · ', $subtitle))),
				'url' => '/crm/deal/details/' . $id . '/',
			];
		}

		return $result;
	}

	private function getStageName(string $stageId, int $categoryId): string
	{
		static $names = [];
		if (!isset($names[$categoryId]))
		{
			$names[$categoryId] = [];
			foreach ($this->factory->getStages($categoryId) as $stage)
			{
				$names[$categoryId][$stage->getStatusId()] = $stage->getName();
			}
		}

		return (string)($names[$categoryId][$stageId] ?? '');
	}
}
