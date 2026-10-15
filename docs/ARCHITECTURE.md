<!-- last-synced: 2026-10-15, commit: 301d031 -->
# Architecture — aldagi.objectfilter

## Components
| Component | File | Responsibility |
|---|---|---|
| `init.php` | `local/php_interface/init.php` | includes the module before crm |
| Module include | `local/modules/aldagi.objectfilter/include.php` | registers `crm.filter.factory` in ServiceLocator |
| `FilterFactory` | `lib/filter/filterfactory.php` | returns the custom `ContactDataProvider` for contact list filters |
| `ContactDataProvider` | `lib/filter/contactdataprovider.php` | replaces/adds fields, filter hook |
| `ObjectFilterFields` | `lib/filter/objectfilterfields.php` | lookup field, `OBJ130_*`, UI filter → object ORM filter → `@ID` |
| `ObjectSearch` | `lib/objectsearch.php` | text search, permissions, object→contact, item display data |
| `ObjectProvider` | `lib/entityselector/objectprovider.php` | entity-selector `aldagi-object` (load / search / selected) |
| `.settings.php` | module root | registers the provider in `ui.entity-selector` |

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
  CDP --> OFF[ObjectFilterFields]
  OFF --> OS
  OS -->|object IDs| EC[(b_crm_entity_contact + b_crm_entity_relation)]
  OFF -->|"@ID = contact IDs"| CL
```

| Step | From → to | Data |
|---|---|---|
| 1 | lookup → `ObjectProvider::doSearch` | text → `search:<text>` + ≤50 objects |
| 2 | filter save | `PARENT_ID_130` array in `b_user_option` |
| 3 | list load → `applyActivityFastSearchFilter` | UI filter → object ORM filter |
| 4 | `ObjectSearch::getReadableObjectIds` | readable object IDs |
| 5 | `ObjectSearch::getContactIds` | contact IDs → `@ID` → `CCrmContact::GetListEx` |

## Extension points
- ServiceLocator service `crm.filter.factory` (core `Bitrix\Crm\Filter\Factory`)
- `Bitrix\Crm\Filter\ContactDataProvider::prepareFields / prepareFieldData / applyActivityFastSearchFilter`
- `Bitrix\UI\EntitySelector\BaseProvider` (`isAvailable`, `getItems`, `getSelectedItems`, `fillDialog`, `doSearch`)
- No core files modified
