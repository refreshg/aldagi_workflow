<?php

namespace Aldagi\ObjectFilter\Filter;

use Aldagi\ObjectFilter\Search\LookupSearch;
use Bitrix\Main\Filter\DataProvider;
use Bitrix\Main\Filter\Field;
use Bitrix\Main\Web\Json;

/**
 * Shared pieces of the contact filter lookups ("ობიექტი", "დილი", "კალკულაცია").
 */
final class LookupHelper
{
	public static function createField(DataProvider $provider, string $fieldId, string $title, bool $isDefault = false): Field
	{
		return new Field(
			$provider,
			$fieldId,
			[
				'name' => $title,
				'type' => 'entity_selector',
				'default' => $isDefault,
				'partial' => true,
			]
		);
	}

	public static function getFieldData(string $selectorEntityId, string $context): array
	{
		return [
			'params' => [
				'multiple' => 'Y',
				'dialogOptions' => [
					'height' => 350,
					'context' => $context,
					'entities' => [
						[
							'id' => $selectorEntityId,
							'dynamicLoad' => true,
							'dynamicSearch' => true,
							// show every item the server matched, even when the match is not in the title
							'dynamicSearchMatchMode' => 'all',
							// no client-side index: results of earlier queries must not be re-matched locally,
							// otherwise the newest server batch (with "ყველა ...") is appended below them
							'searchFields' => [
								['name' => 'title', 'system' => true, 'searchable' => false],
								['name' => 'subtitle', 'system' => true, 'searchable' => false],
							],
						],
					],
					'dropdownMode' => false,
					// within one server batch "ყველა ..." (sort 0) goes first
					'searchTabOptions' => [
						'itemOrder' => ['sort' => 'asc nulls last'],
					],
				],
			],
		];
	}

	/**
	 * Removes the field (and its label) from the filter and splits its values into
	 * picked keys and text chips. Null when the field holds nothing.
	 *
	 * @param callable|null $decodeKey converts legacy values into a key, e.g. "CRMDYNAMIC-130_5" → 5
	 * @return array{0: array, 1: string[]}|null
	 */
	public static function extractValues(array &$filter, string $fieldId, ?callable $decodeKey = null): ?array
	{
		$value = $filter[$fieldId] ?? null;
		unset($filter[$fieldId], $filter[$fieldId . '_label']);
		if ($value === null || $value === '' || $value === [] || $value === false)
		{
			return null;
		}

		$keys = [];
		$texts = [];
		foreach ((array)$value as $singleValue)
		{
			$singleValue = self::normalizeValue($singleValue);
			if (is_string($singleValue) && str_starts_with($singleValue, LookupSearch::TEXT_VALUE_PREFIX))
			{
				$text = trim(mb_substr($singleValue, mb_strlen(LookupSearch::TEXT_VALUE_PREFIX)));
				if ($text !== '')
				{
					$texts[] = $text;
				}
				continue;
			}

			$key = $decodeKey ? $decodeKey($singleValue) : $singleValue;
			if ($key !== null && $key !== '' && $key !== 0)
			{
				$keys[] = $key;
			}
		}

		return (empty($keys) && empty($texts)) ? null : [$keys, $texts];
	}

	public static function restrictContactIds(array &$contactFilter, array $contactIds): void
	{
		if (isset($contactFilter['@ID']))
		{
			$contactIds = array_values(array_intersect($contactIds, array_map('intval', (array)$contactFilter['@ID'])));
		}

		// [0] keeps the condition valid and returns nothing when nothing matched
		$contactFilter['@ID'] = !empty($contactIds) ? array_values(array_unique($contactIds)) : [0];
	}

	/**
	 * Entity selector values may come as an ID or as a JSON pair [entityId, id].
	 */
	private static function normalizeValue(mixed $value): mixed
	{
		if (is_string($value) && str_starts_with($value, '['))
		{
			try
			{
				$decoded = Json::decode($value);
				if (is_array($decoded) && isset($decoded[1]))
				{
					return $decoded[1];
				}
			}
			catch (\Throwable)
			{
			}
		}

		return $value;
	}
}
