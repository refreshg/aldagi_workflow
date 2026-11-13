<!-- last-synced: 2026-11-13, commit: 862a93f -->
# Architecture — aldagi.objectfilter

## Components
| Component | File | Responsibility |
|---|---|---|
| `init.php` | `local/php_interface/init.php` | includes the module before crm |
| Module include | `local/modules/aldagi.objectfilter/include.php` | registers `crm.filter.factory` in ServiceLocator |
| `FilterFactory` | `lib/filter/filterfactory.php` | returns the custom `ContactDataProvider` (`CRM_CONTACT_LIST*`) / `DealDataProvider` (`CRM_DEAL_LIST*`) |
| `ContactDataProvider` | `lib/filter/contactdataprovider.php` | delegates fields and the filter hook to `ContactLookupFilter` |
| `DealDataProvider` | `lib/filter/dealdataprovider.php` | deal filter: object make/model/year → deals via 2→130 relation (D-12) |
| `ContactLookupFilter` | `lib/filter/contactlookupfilter.php` | fields `PARENT_ID_130`, `ALDAGI_DEAL`, `ALDAGI_CALCULATION`; chains conditions through the object (D-7) → `@ID` |
| `ObjectFilterFields` | `lib/filter/objectfilterfields.php` | object lookup + `OBJ130_*` → readable object IDs |
| `LookupHelper` | `lib/filter/lookuphelper.php` | lookup field/dialog options, value parsing, `@ID` restriction |
| `LookupSearch` / `FactorySearch` | `lib/search/` | lookup contract; text search (`%=`), chip grouping, permissions for one CRM type |
| `ObjectSearch`, `DealSearch`, `CalculationTypeSearch`, `CalculationSearch` | `lib/search/` | per-entity links to contacts and dropdown display data; deals use full-text `SEARCH_CONTENT` |
| `LookupProvider` + Object/Deal/Calculation providers | `lib/entityselector/` | entity-selectors `aldagi-object`, `aldagi-deal`, `aldagi-calculation` |
| `NumericListFields` | `lib/filter/numericlistfields.php` | numeric list fields (year, engine...) as number filters (D-9) |
| `CarModelProvider` | `lib/entityselector/carmodelprovider.php` | entity-selector `aldagi-car-model`: models of the chosen makes (D-10) |
| car model page script | `local/js/aldagi/objectfilter/carmodel/` | passes chosen makes to the model dialog; loaded on `/crm/contact/` and `/crm/deal/` by `include.php` |
| `.settings.php` | module root | registers the four providers in `ui.entity-selector` |

## Data flow
```mermaid
flowchart LR
  U[User] -->|types text in lookup| ES[ui.entityselector.doSearch]
  ES --> OP[ObjectProvider]
  OP --> OS[ObjectSearch]
  OS -->|ORM + permissions| DI[(b_crm_dynamic_items_130)]
  OS --> EN[(b_user_field_enum / b_iblock_element)]
  U -->|Search| CL[crm.contact.list]
  CL -->|crm.filter.factory| FF[FilterFactory]
  FF --> CDP[ContactDataProvider]
  CL -->|applySubQueryBasedFiltersWrapper| CDP
  CDP --> CLF[ContactLookupFilter]
  CLF --> OFF[ObjectFilterFields]
  CLF --> DS[DealSearch / CalculationSearch]
  OFF --> OS
  OS -->|object IDs| EC[(b_crm_entity_contact + b_crm_entity_relation)]
  CLF -->|"@ID = contact IDs"| CL
  U -->|Search| DL[crm.deal.list / deal kanban]
  DL -->|crm.filter.factory| FF
  FF --> DDP[DealDataProvider]
  DDP --> OFF
  DDP -->|object IDs → 2→130 relation| ER[(b_crm_entity_relation)]
  DDP -->|"@ID = deal IDs"| DL
  JS[carmodel page script] -->|makeIds| CMP[CarModelProvider]
  CMP --> IB[(b_iblock_element 16 / 17)]
```

| Step | From → to | Data |
|---|---|---|
| 1 | lookup → `LookupProvider::doSearch` | text → `search:<text>` + ≤50 items |
| 2 | filter save | `PARENT_ID_130` / `OBJ130_*` / `ALDAGI_*` values in `b_user_option` |
| 3 | list / kanban load → `applyActivityFastSearchFilter` | UI filter → object ORM filter |
| 4 | `FactorySearch::getReadableIds` | readable object IDs |
| 5a | contacts: `ObjectSearch::getContactIdsByItemIds` | contact IDs → `@ID` → `CCrmContact::GetListEx` |
| 5b | deals: `DealDataProvider::getDealIdsByObjectIds` | deal IDs → `@ID` → deal list / kanban |

## Extension points
- ServiceLocator service `crm.filter.factory` (core `Bitrix\Crm\Filter\Factory`)
- `Bitrix\Crm\Filter\ContactDataProvider` and `DealDataProvider`: `prepareFields / prepareFieldData / applyActivityFastSearchFilter`
- `BX.UI.EntitySelector.Dialog.prototype.show / getAjaxJson` patched by the page script for `aldagi-car-model` only
- `Bitrix\UI\EntitySelector\BaseProvider` (`isAvailable`, `getItems`, `getSelectedItems`, `fillDialog`, `doSearch`)
- No core files modified
