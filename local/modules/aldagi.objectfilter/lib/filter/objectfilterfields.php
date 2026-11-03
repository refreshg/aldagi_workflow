<?php

namespace Aldagi\ObjectFilter\Filter;

use Aldagi\ObjectFilter\EntitySelector\CarModelProvider;
use Aldagi\ObjectFilter\Search\ObjectSearch;
use Bitrix\Crm;
use Bitrix\Crm\Service\ParentFieldManager;
use Bitrix\Main\Filter\DataProvider;
use Bitrix\Main\Filter\Field;

/**
 * Contact filter fields built on the "ობიექტი" smart process (SPA 130):
 * - the "ობიექტი" lookup, searching objects by any of their fields;
 * - the vehicle fields of the object card as separate "ობიექტი: ..." filter fields.
 * Both narrow down the same object; ContactLookupFilter turns the objects into contacts.
 */
class ObjectFilterFields
{
	public const FIELD_PREFIX = 'OBJ130_';
	public const MAKE_FIELD = 'UF_CRM_2_1722418477';
	public const MODEL_FIELD = 'UF_CRM_2_1722418725';

	private const LOOKUP_TITLE = 'ობიექტი';
	private const TITLE_PREFIX = 'ობიექტი: ';
	private const SUPPORTED_FILTER_ID_PREFIX = 'CRM_CONTACT_LIST';
	private const ITEM_FILTER_ID = 'crm-type-item-list-130-0';
	/** Vehicle fields only for now (D-10), in the order of the object card */
	private const CAR_FIELDS = [
		'UF_CRM_2_1723106717724', // ობიექტის ტიპი
		'UF_CRM_2_1724400410453', // დანიშნულება
		self::MAKE_FIELD,         // მარკა
		self::MODEL_FIELD,        // მოდელი
		'UF_CRM_2_1733743367634', // ავტომობილის ღირებულება
		'UF_CRM_2_1725628662391', // ვალუტა
		'UF_CRM_2_1723804031377', // გამოშვების წელი
		'UF_CRM_2_1725628946689', // ძრავის მოცულობა (ლიტრი)
		'UF_CRM_2_1733671313605', // მესაკუთრის ასაკი
		'UF_CRM_2_1725629174528', // მესაკუთრეს გამოცდილება
		'UF_CRM_2_1722586144916', // მესაკუთრედ გახდომის თარიღი
		'UF_CRM_2_1725629073343', // ავტომობილი საკუთრებაშია
		'UF_CRM_2_1725629521922', // ყველაზე ახალგაზრდა მძღოლის ასაკი
		'UF_CRM_2_1754640611196', // ჩანაძირი
		'UF_CRM_2_1757063701598', // საჭის მდგომარეობა
		'UF_CRM_2_1757063831738', // სალონის ტიპი
		'UF_CRM_2_1757064932680', // გადაცემათა კოლოფი
		'UF_CRM_2_1757065192951', // საწვავის ტიპი - ცნობარი
		'UF_CRM_2_1757065375856', // ძარის ტიპი
		'UF_CRM_2_1729682986245', // ვინკოდი
		'UF_CRM_2_1739454816',    // პოლისის ნომერი
		'UF_CRM_2_1722586103385', // სახელმწიფო ნომერი
		'UF_CRM_2_1722586113575', // სახელმწიფო ნომერი(ძველი)
		'UF_CRM_2_1757063888332', // გასაღების რაოდენობა
		'UF_CRM_2_1757064785297', // სიგნალიზაცია
		'UF_CRM_2_1757064871495', // აქვს ტაქსის ლიცენზია
		'UF_CRM_2_1733753033361', // არასტანდარტული ნომერი
	];

	private DataProvider $ownerProvider;
	private ObjectSearch $search;
	private Crm\Filter\ItemDataProvider $itemProvider;
	private Crm\Filter\ItemUfDataProvider $ufProvider;
	private NumericListFields $numericLists;
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
		$this->numericLists = new NumericListFields();
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
			$fieldId = self::FIELD_PREFIX . $sourceId;
			$title = self::TITLE_PREFIX . trim($info['field']->getName());
			if ($this->numericLists->isNumericList($sourceId))
			{
				// year, engine volume...: more than / less than / range instead of ticking values
				$result[$fieldId] = new Field($this->ownerProvider, $fieldId, ['name' => $title, 'type' => 'number']);
				continue;
			}
			if ($sourceId === self::MODEL_FIELD)
			{
				// searchable list limited to the makes chosen in the "მარკა" field
				$result[$fieldId] = LookupHelper::createField($this->ownerProvider, $fieldId, $title);
				continue;
			}
			if ($info['userField']['USER_TYPE_ID'] === 'enumeration')
			{
				// the same drop-down for every list field (some came as a search dialog)
				$result[$fieldId] = new Field(
					$this->ownerProvider,
					$fieldId,
					['name' => $title, 'type' => 'list', 'partial' => true]
				);
				continue;
			}

			$field = clone $info['field'];
			$field->setId($fieldId);
			$field->setName($title);
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
		if (!$info || $this->numericLists->isNumericList($sourceId))
		{
			return null;
		}
		if ($sourceId === self::MODEL_FIELD)
		{
			return $this->getModelFieldData();
		}
		if ($info['userField']['USER_TYPE_ID'] === 'enumeration')
		{
			return [
				'params' => ['multiple' => 'Y'],
				'items' => $this->getEnumItems((int)$info['userField']['ID']),
			];
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
	 * Model dialog; the page script (js/aldagi/objectfilter/carmodel) passes the makes
	 * currently chosen in the "მარკა" field of the same filter as the makeIds option.
	 */
	private function getModelFieldData(): array
	{
		return [
			'params' => [
				'multiple' => 'Y',
				'dialogOptions' => [
					'height' => 350,
					'context' => 'ALDAGI_CONTACT_FILTER_CAR_MODEL',
					'entities' => [
						[
							'id' => CarModelProvider::ENTITY_ID,
							'dynamicLoad' => true,
							'dynamicSearch' => true,
							'options' => [
								'filterId' => (string)$this->ownerProvider->getSettings()->getID(),
								'makeFieldId' => self::FIELD_PREFIX . self::MAKE_FIELD,
							],
						],
					],
					'recentTabOptions' => ['visible' => false],
					'dropdownMode' => false,
				],
			],
		];
	}

	/**
	 * @return array<int, string> enum value ID => caption
	 */
	private function getEnumItems(int $userFieldId): array
	{
		$items = [];
		$enumList = \CUserFieldEnum::GetList(['SORT' => 'ASC', 'VALUE' => 'ASC'], ['USER_FIELD_ID' => $userFieldId]);
		while ($enum = $enumList->Fetch())
		{
			$items[(int)$enum['ID']] = (string)$enum['VALUE'];
		}

		return $items;
	}

	/**
	 * @return array<string, array{field: Field, isUserField: bool, userField: array}>
	 */
	private function getSourceFields(): array
	{
		if ($this->sourceFields !== null)
		{
			return $this->sourceFields;
		}

		$this->sourceFields = [];
		$ufFields = $this->ufProvider->prepareFields();
		$userFields = Crm\Service\Container::getInstance()->getFactory(ObjectSearch::ENTITY_TYPE_ID)->getUserFields();
		foreach (self::CAR_FIELDS as $id)
		{
			if (isset($ufFields[$id], $userFields[$id]))
			{
				$this->sourceFields[$id] = [
					'field' => $ufFields[$id],
					'isUserField' => true,
					'userField' => $userFields[$id],
				];
			}
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

		$filter = $this->numericLists->extractFilter($requestFilter);
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
