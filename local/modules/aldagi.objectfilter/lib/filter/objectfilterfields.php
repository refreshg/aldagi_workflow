<?php

namespace Aldagi\ObjectFilter\Filter;

use Aldagi\ObjectFilter\EntitySelector\ObjectProvider;
use Aldagi\ObjectFilter\ObjectSearch;
use Bitrix\Crm;
use Bitrix\Crm\Service\ParentFieldManager;
use Bitrix\Main\Filter\DataProvider;
use Bitrix\Main\Filter\Field;
use Bitrix\Main\Web\Json;

/**
 * Contact filter fields built on the "ობიექტი" smart process (SPA 130):
 * - the "ობიექტი" lookup, searching objects by any of their fields;
 * - every object field as a separate "ობიექტი: ..." filter field.
 * Both are converted into a filter by IDs of the bound contacts.
 */
class ObjectFilterFields
{
	public const FIELD_PREFIX = 'OBJ130_';

	private const LOOKUP_TITLE = 'ობიექტი';
	private const TITLE_PREFIX = 'ობიექტი: ';
	private const SUPPORTED_FILTER_ID_PREFIX = 'CRM_CONTACT_LIST';
	private const ITEM_FILTER_ID = 'crm-type-item-list-130-0';
	private const IGNORED_FIELD_IDS = [
		'CONTACT_ID',
		'COMPANY_ID',
		'MYCOMPANY_ID',
		'ACTIVITY_COUNTER',
		'ACTIVITY_RESPONSIBLE_IDS',
		'LAST_ACTIVITY_TIME',
	];

	private DataProvider $ownerProvider;
	private ObjectSearch $search;
	private Crm\Filter\ItemDataProvider $itemProvider;
	private Crm\Filter\ItemUfDataProvider $ufProvider;
	/** @var array<string, array{field: Field, isUserField: bool}>|null */
	private ?array $sourceFields = null;

	public static function isSupportedFilterId(string $filterId): bool
	{
		return str_starts_with($filterId, self::SUPPORTED_FILTER_ID_PREFIX);
	}

	public static function isOwnFieldId(string $fieldId): bool
	{
		return str_starts_with($fieldId, self::FIELD_PREFIX);
	}

	/**
	 * The standard parent field ID is kept, so saved filters and presets stay valid.
	 */
	public static function getLookupFieldId(): string
	{
		return ParentFieldManager::getParentFieldName(ObjectSearch::ENTITY_TYPE_ID);
	}

	public function __construct(DataProvider $ownerProvider, ObjectSearch $search)
	{
		$this->ownerProvider = $ownerProvider;
		$this->search = $search;

		// base factory on purpose: the overridden one only matters for contacts
		$filterFactory = new Crm\Filter\Factory();
		$settings = $filterFactory->getSettings(ObjectSearch::ENTITY_TYPE_ID, self::ITEM_FILTER_ID);
		$this->itemProvider = $filterFactory->getDataProvider($settings);
		$this->ufProvider = $filterFactory->getUserFieldDataProvider($settings);
	}

	public function createLookupField(?Field $standardField): Field
	{
		return new Field(
			$this->ownerProvider,
			self::getLookupFieldId(),
			[
				'name' => self::LOOKUP_TITLE,
				'type' => 'entity_selector',
				'default' => $standardField?->isDefault() ?? false,
				'partial' => true,
			]
		);
	}

	public function getLookupFieldData(): array
	{
		return [
			'params' => [
				'multiple' => 'Y',
				'dialogOptions' => [
					'height' => 350,
					'context' => 'ALDAGI_CONTACT_FILTER_OBJECT',
					'entities' => [
						[
							'id' => ObjectProvider::ENTITY_ID,
							'dynamicLoad' => true,
							'dynamicSearch' => true,
							// show every object the server matched, even when the match is not in the title
							'dynamicSearchMatchMode' => 'all',
						],
					],
					'dropdownMode' => false,
				],
			],
		];
	}

	/**
	 * @return Field[]
	 */
	public function prepareFields(): array
	{
		$result = [];
		foreach ($this->getSourceFields() as $sourceId => $info)
		{
			$field = clone $info['field'];
			$fieldId = self::FIELD_PREFIX . $sourceId;
			$field->setId($fieldId);
			$field->setName(self::TITLE_PREFIX . trim($info['field']->getName()));
			$field->markAsDefault(false);
			$field->setDataProvider($this->ownerProvider);
			$result[$fieldId] = $field;
		}

		return $result;
	}

	public function prepareFieldData(string $fieldId): ?array
	{
		$sourceId = mb_substr($fieldId, mb_strlen(self::FIELD_PREFIX));
		$info = $this->getSourceFields()[$sourceId] ?? null;
		if (!$info)
		{
			return null;
		}

		return $info['isUserField']
			? $this->ufProvider->prepareFieldData($sourceId)
			: $this->itemProvider->prepareFieldData($sourceId);
	}

	/**
	 * Removes object conditions from the contact filter and replaces them with '@ID' => [contact ids].
	 * All conditions narrow down the same object: "honda" + "სედანი" keeps owners of a Honda sedan.
	 */
	public function applyToContactFilter(array &$contactFilter): void
	{
		$itemFilter = array_merge(
			$this->extractLookupFilter($contactFilter),
			$this->extractObjectFieldsFilter($contactFilter)
		);
		if (empty($itemFilter))
		{
			return;
		}

		$this->restrictContactIds(
			$contactFilter,
			$this->getContactIds($this->search->getReadableObjectIds($itemFilter))
		);
	}

	/**
	 * Picked objects are alternatives (OR), every text chip is one more condition (AND).
	 */
	private function extractLookupFilter(array &$contactFilter): array
	{
		$fieldName = self::getLookupFieldId();
		$value = $contactFilter[$fieldName] ?? null;
		if ($value === null || $value === '' || $value === [] || $value === false)
		{
			return [];
		}

		$objectIds = [];
		$texts = [];
		foreach ((array)$value as $singleValue)
		{
			$singleValue = $this->normalizeLookupValue($singleValue);
			if (is_string($singleValue) && str_starts_with($singleValue, ObjectSearch::TEXT_VALUE_PREFIX))
			{
				$text = trim(mb_substr($singleValue, mb_strlen(ObjectSearch::TEXT_VALUE_PREFIX)));
				if ($text !== '')
				{
					$texts[] = $text;
				}
				continue;
			}

			$objectId = (int)ParentFieldManager::transformEncodedFilterValueIntoInteger($fieldName, $singleValue);
			if ($objectId > 0)
			{
				$objectIds[] = $objectId;
			}
		}
		if (empty($objectIds) && empty($texts))
		{
			return [];
		}
		unset($contactFilter[$fieldName], $contactFilter[$fieldName . '_label']);

		$filter = [];
		if (!empty($objectIds))
		{
			$filter['@ID'] = $objectIds;
		}
		foreach (array_unique($texts) as $text)
		{
			$filter[] = $this->search->buildTextFilter($text);
		}

		return $filter;
	}

	/**
	 * Entity selector values may come as an ID or as a JSON pair [entityId, id].
	 */
	private function normalizeLookupValue(mixed $value): mixed
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

	private function extractObjectFieldsFilter(array &$contactFilter): array
	{
		$requestFilter = [];
		foreach ($contactFilter as $key => $value)
		{
			if (is_string($key) && self::isOwnFieldId($key))
			{
				$requestFilter[mb_substr($key, mb_strlen(self::FIELD_PREFIX))] = $value;
				unset($contactFilter[$key]);
			}
		}

		return $this->prepareItemFilter($requestFilter);
	}

	private function getContactIds(array $objectIds): array
	{
		return !empty($objectIds) ? $this->search->getContactIds($objectIds) : [];
	}

	private function restrictContactIds(array &$contactFilter, array $contactIds): void
	{
		if (isset($contactFilter['@ID']))
		{
			$contactIds = array_values(array_intersect($contactIds, array_map('intval', (array)$contactFilter['@ID'])));
		}

		// [0] keeps the condition valid and returns nothing when no object matched
		$contactFilter['@ID'] = !empty($contactIds) ? $contactIds : [0];
	}

	/**
	 * @return array<string, array{field: Field, isUserField: bool}>
	 */
	private function getSourceFields(): array
	{
		if ($this->sourceFields !== null)
		{
			return $this->sourceFields;
		}

		$this->sourceFields = [];
		foreach ($this->itemProvider->prepareFields() as $id => $field)
		{
			if (!in_array($id, self::IGNORED_FIELD_IDS, true) && !str_contains($id, '.'))
			{
				$this->sourceFields[$id] = ['field' => $field, 'isUserField' => false];
			}
		}
		foreach ($this->ufProvider->prepareFields() as $id => $field)
		{
			$this->sourceFields[$id] = ['field' => $field, 'isUserField' => true];
		}

		return $this->sourceFields;
	}

	private function prepareItemFilter(array $requestFilter): array
	{
		if (empty($requestFilter))
		{
			return [];
		}

		$requestFilter = Crm\Filter\FieldsTransform\UserBasedField::breakDepartmentsToUsers(
			$requestFilter,
			array_column($this->getSourceFields(), 'field'),
		);

		$filter = [];
		$this->itemProvider->prepareListFilter($filter, $requestFilter);
		$this->itemProvider->processStageSemanticFilter($requestFilter, $filter);

		$ufFilterFields = [];
		foreach ($this->getSourceFields() as $sourceId => $info)
		{
			if ($info['isUserField'] && $this->isFieldInRequest($sourceId, $requestFilter))
			{
				$ufFilterFields[] = [
					'id' => $sourceId,
					'type' => $info['field']->getType(),
					'subtype' => $info['field']->toArray()['subtype'] ?? null,
				];
			}
		}
		if (!empty($ufFilterFields))
		{
			$this->ufProvider->prepareListFilter($filter, $ufFilterFields, $requestFilter);
		}

		// same post-processing as crm.item.list applies to its own filter
		Crm\Filter\FieldsTransform\UserBasedField::applyTransformWrapper($filter);
		Crm\Filter\FieldsTransform\DateTimeField::applyTimezoneOffset(ObjectSearch::ENTITY_TYPE_ID, $filter);

		return $filter;
	}

	private function isFieldInRequest(string $fieldId, array $requestFilter): bool
	{
		foreach (array_keys($requestFilter) as $key)
		{
			if (ltrim($key, '!') === $fieldId || str_starts_with(ltrim($key, '!'), $fieldId . '_'))
			{
				return true;
			}
		}

		return false;
	}
}
