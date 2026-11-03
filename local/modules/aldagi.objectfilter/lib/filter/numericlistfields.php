<?php

namespace Aldagi\ObjectFilter\Filter;

use Aldagi\ObjectFilter\Search\ObjectSearch;
use Bitrix\Crm\Service\Container;

/**
 * Object list (enumeration) fields whose values are numbers — release year, engine volume,
 * driver age... The standard filter only lets you tick values; here they become number fields
 * (exact / range / more than / less than) translated into the matching list values.
 */
class NumericListFields
{
	private const MIN_VALUES = 3;
	private const NUMERIC_SHARE = 0.9;
	/** "4.3 +" is a bit more than 4.3, "1990 - მდე" a bit less than 1990 */
	private const BOUND_SHIFT = 0.001;
	private const COMPARE_PRECISION = 0.000001;

	/** @var array<string, array<int, float>>|null field name => enum value ID => number */
	private ?array $numericValues = null;

	/**
	 * Converts the text of a list value into a number: "2.0" → 2.0, "1,6" → 1.6,
	 * "4.3 +" / "90 - ზე მეტი" → just above, "1990 - მდე" → just below.
	 */
	public static function parseValue(string $value): ?float
	{
		$value = trim(str_replace(',', '.', $value));
		if (is_numeric($value))
		{
			return (float)$value;
		}
		if (!preg_match('/-?\d+(?:\.\d+)?/', $value, $matches))
		{
			return null;
		}

		$number = (float)$matches[0];
		if (mb_strpos($value, 'მდე') !== false)
		{
			return $number - self::BOUND_SHIFT;
		}
		if (str_contains($value, '+') || mb_strpos($value, 'მეტი') !== false)
		{
			return $number + self::BOUND_SHIFT;
		}

		return null;
	}

	public function isNumericList(string $fieldName): bool
	{
		return isset($this->getNumericValues()[$fieldName]);
	}

	/**
	 * Removes number-filter keys of numeric list fields from the request and returns
	 * ORM conditions "field is one of the values in range".
	 */
	public function extractFilter(array &$requestFilter): array
	{
		$filter = [];
		foreach ($this->getNumericValues() as $fieldName => $values)
		{
			$type = $requestFilter[$fieldName . '_numsel'] ?? null;
			$from = $this->toNumber($requestFilter[$fieldName . '_from'] ?? null);
			$to = $this->toNumber($requestFilter[$fieldName . '_to'] ?? null);
			unset(
				$requestFilter[$fieldName],
				$requestFilter[$fieldName . '_numsel'],
				$requestFilter[$fieldName . '_from'],
				$requestFilter[$fieldName . '_to']
			);
			if ($from === null && $to === null)
			{
				continue;
			}

			$matchingIds = [];
			foreach ($values as $enumId => $number)
			{
				if ($this->matches($number, (string)$type, $from, $to))
				{
					$matchingIds[] = $enumId;
				}
			}
			// no list value is in the range: [-1] matches nothing (an empty field may be stored as 0)
			$filter['@' . $fieldName] = !empty($matchingIds) ? $matchingIds : [-1];
		}

		return $filter;
	}

	private function matches(float $number, string $type, ?float $from, ?float $to): bool
	{
		return match ($type)
		{
			'more' => $from !== null && $number > $from + self::COMPARE_PRECISION,
			'less' => $to !== null && $number < $to - self::COMPARE_PRECISION,
			'range' => ($from === null || $number >= $from - self::COMPARE_PRECISION)
				&& ($to === null || $number <= $to + self::COMPARE_PRECISION),
			default => $from !== null && abs($number - $from) < self::COMPARE_PRECISION,
		};
	}

	private function toNumber(mixed $value): ?float
	{
		if (!is_scalar($value))
		{
			return null;
		}
		$value = trim(str_replace(',', '.', (string)$value));

		return is_numeric($value) ? (float)$value : null;
	}

	/**
	 * @return array<string, array<int, float>>
	 */
	private function getNumericValues(): array
	{
		if ($this->numericValues !== null)
		{
			return $this->numericValues;
		}

		$this->numericValues = [];
		$factory = Container::getInstance()->getFactory(ObjectSearch::ENTITY_TYPE_ID);
		foreach ($factory->getUserFields() as $fieldName => $userField)
		{
			if ($userField['USER_TYPE_ID'] !== 'enumeration' || ($userField['MULTIPLE'] ?? 'N') === 'Y')
			{
				continue;
			}

			$total = 0;
			$values = [];
			$enumList = \CUserFieldEnum::GetList([], ['USER_FIELD_ID' => $userField['ID']]);
			while ($enum = $enumList->Fetch())
			{
				$total++;
				$number = self::parseValue((string)$enum['VALUE']);
				if ($number !== null)
				{
					$values[(int)$enum['ID']] = $number;
				}
			}

			if ($total >= self::MIN_VALUES && count($values) >= $total * self::NUMERIC_SHARE)
			{
				$this->numericValues[$fieldName] = $values;
			}
		}

		return $this->numericValues;
	}
}
