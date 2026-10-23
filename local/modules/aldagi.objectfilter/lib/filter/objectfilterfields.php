<?php

namespace Aldagi\ObjectFilter\Filter;

use Aldagi\ObjectFilter\Search\ObjectSearch;
use Bitrix\Crm;
use Bitrix\Crm\Service\ParentFieldManager;
use Bitrix\Main\Filter\DataProvider;
use Bitrix\Main\Filter\Field;

/**
 * Contact filter fields built on the "ობიექტი" smart process (SPA 130):
 * - the "ობიექტი" lookup, searching objects by any of their fields;
 * - every object field as a separate "ობიექტი: ..." filter field.
 * Both narrow down the same object; ContactLookupFilter turns the objects into contacts.
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
		return LookupHelper::createField(
			$this->ownerProvider,
			self::getLookupFieldId(),
			self::LOOKUP_TITLE,
			$standardField?->isDefault() ?? false
		);
	}

	public function getLookupFieldData(): array
	{
		return LookupHelper::getFieldData($this->search->getSelectorEntityId(), 'ALDAGI_CONTACT_FILTER_OBJECT');
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
	 * Removes the "ობიექტი" lookup and "ობიექტი: ..." fields from the contact filter.
	 * Returns readable objects matching all of them, or null when none was set.
	 *
	 * @return int[]|null
	 */
	public function extractObjectIds(array &$contactFilter): ?array
	{
		$fieldName = self::getLookupFieldId();
		$lookupValues = LookupHelper::extractValues(
			$contactFilter,
			$fieldName,
			// legacy saved values look like "CRMDYNAMIC-130_<id>"
			fn($value) => (int)ParentFieldManager::transformEncodedFilterValueIntoInteger($fieldName, $value)
		);

		$itemFilter = array_merge(
			$lookupValues !== null ? $this->search->buildLookupFilter(...$lookupValues) : [],
			$this->extractObjectFieldsFilter($contactFilter)
		);
		if (empty($itemFilter))
		{
			return null;
		}

		return $this->search->getReadableIds($itemFilter);
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
