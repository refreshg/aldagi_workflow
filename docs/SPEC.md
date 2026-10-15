<!-- last-synced: 2026-10-15, commit: 301d031 -->
# Technical spec — contact search by linked object

## Data model
No NEW tables or fields. Existing structures used:

| Entity | Table | Usage |
|---|---|---|
| SPA "ობიექტი", `entityTypeId=130`, category 4 | `b_crm_dynamic_items_130` | search: `TITLE` + every non-multiple `string`/`money`/`url`/`integer`/`double`/`enumeration`/`iblock_element` UF (`UF_CRM_2_*`) |
| Object→contact binding (primary) | `b_crm_entity_contact` (`ENTITY_TYPE_ID=130`) | ~25k rows |
| Parent relation 130→3 | `b_crm_entity_relation` | 0 rows today; still included (UNION) |
| Enum values | `b_user_field_enum` | text search over list captions |
| Reference iblocks (make, model...) | `b_iblock_element` | text search over `iblock_element` fields, ≤1000 matches per field |

Subtitle fields (`ObjectSearch`): `UF_CRM_2_1723106717724` object type, `UF_CRM_2_1722586103385` plate, `UF_CRM_2_1729682986245` VIN, `UF_CRM_2_1723107503113` cadastral code.

Filter values (`b_user_option`, `main.ui.filter` / `CRM_CONTACT_LIST_V12`):
| Field | Format |
|---|---|
| `PARENT_ID_130` (MODIFIED behaviour) | array of: object ID, `search:<text>`, legacy `CRMDYNAMIC-130_<id>`, or JSON `["aldagi-object", id]` |
| `OBJ130_<field>` (NEW) | SPA filter UI format (`_from`, `_to`, `_datesel`, `_numsel`...) |

## Business logic
| # | Trigger | Condition | Action | AC | Standard coverage |
|---|---|---|---|---|---|
| L-1 | Contact list filter build | `filterId` starts with `CRM_CONTACT_LIST` | `PARENT_ID_130` replaced by multiple `entity_selector` (`aldagi-object`); `OBJ130_*` fields added | AC-1..5 | custom: D-1, D-2 |
| L-2 | Lookup search (`ui.entityselector.doSearch`) | non-empty text | item `search:<text>` + ≤50 newest matches, permission-filtered | AC-1, AC-2, AC-8 | custom: D-3 |
| L-3 | `ContactDataProvider::applyActivityFastSearchFilter` | `PARENT_ID_130` or `OBJ130_*` present | one ORM filter on the object: picked IDs OR, each text AND, `OBJ130_*` AND → contact IDs → `@ID` | AC-1..6 | custom: D-1, D-4 |
| L-4 | same | zero matches | `@ID = [0]` | AC-6 | custom: D-4 |
| L-5 | Object ID query | always | `UserPermissions::itemsList()->applyAvailableItemsGetListParameters`, READ | AC-7 | standard: CRM permissions API |
| L-6 | `OBJ130_*` conversion | always | `ItemDataProvider::prepareListFilter`, `processStageSemanticFilter`, `ItemUfDataProvider::prepareListFilter`, `UserBasedField`, `DateTimeField::applyTimezoneOffset` — same as `crm.item.list` | AC-5 | standard: SPA filter providers |

## Standard-first check
| Requirement | Standard feature checked | Covers it? | Custom + ref |
|---|---|---|---|
| Find contacts by object | Contact filter field "ობიექტი" (`PARENT_ID_130`) | no — queries `b_crm_entity_relation` (0 rows), single select, title only | lookup override, D-1 |
| Search by any object field | standard entity-selector `dynamic` | no — title only | own provider, D-3 |
| Filter contacts by object fields | contact filter fields / UF | no — linked SPA fields not exposed | `OBJ130_*`, D-2 |
| Extend filter without core edits | filter factory events | no — none exist | ServiceLocator override, D-2 |
| Permission enforcement | CRM permissions API | yes | standard |

## Views / UI
| Element | Type | Identifier | Notes |
|---|---|---|---|
| "ობიექტი" | filter field `entity_selector`, multiple | `PARENT_ID_130`, dialog context `ALDAGI_CONTACT_FILTER_OBJECT` | `dynamicSearch`, `dynamicSearchMatchMode: 'all'`, `dropdownMode: false` |
| "ყველა ობიექტი, რომელიც შეიცავს «X»" | selector item | id `search:X`, sort 0 | subtitle "ნაპოვნია N ობიექტი" |
| Object item | selector item | id = object ID | title `TITLE`; subtitle type · plate/VIN/code · contacts; link `/crm/type/130/details/<id>/` |
| "ობიექტი: <field>" (~129) | filter fields | `OBJ130_<field>` | in "Add field", not default |

## Security
- No new groups or access rules
- Objects: READ permission on all SPA 130 categories (L-5); anonymous → empty
- Contacts: the standard list applies contact permissions itself
- `ObjectProvider::isAvailable()`: authorized users only

## Integrations
- No external integrations
- Internal AJAX: `ui.entityselector.load` / `doSearch` (core controller, JSON body, CSRF header `X-Bitrix-Csrf-Token`); `crm.contact.list/filter.ajax.php` (`action=list|field`)

## Bitrix specifics
- Box; no REST/OAuth/webhooks/BP
- ServiceLocator override: `crm.filter.factory` → `Aldagi\ObjectFilter\Filter\FilterFactory`, registered before crm (`registerByModuleSettings` skips existing services)
- `.settings.php` → `ui.entity-selector.entities[aldagi-object]`
- Hook point: `EntityDataProvider::applyActivityFastSearchFilter`, called from `CCrmEntityHelper::applySubQueryBasedFiltersWrapper` and `Filter\Factory::getFilterValue`

## Migration / data
- Install: no data changes; `ModuleManager::registerModule('aldagi.objectfilter')`
- Saved filters with `PARENT_ID_130 = CRMDYNAMIC-130_<id>` keep working (L-3)
- 2026-10-15 (test portal): obsolete `OBJ130_ANY` removed from user 1's saved filters
- Uninstall: `DoUninstall()` + remove the line from `init.php` → standard behaviour returns

## Tests
Automated tests: required (user answer), not written yet — see PLAN M4. Manual checks done on testmycrm, 2026-10-15:
| AC | Scenario | Result |
|---|---|---|
| AC-1 | `search:toyota` | 5198 contacts; list page filtered |
| AC-2 | `search:MI-041-QA` | 1 contact (66938) |
| AC-3 | `search:honda` + `search:სედანი` | 44 contacts (45 objects, contact 84081 has 2); 44 list rows, all verified by SQL |
| AC-4 | IDs `3489`, `35587` | 2 contacts (22273, 9012) |
| AC-5 | `OBJ130_UF_CRM_2_1723106717724 = 102` | 1637 contacts |
| AC-6 | `nothingmatches_zz` | `@ID=[0]` |
| AC-7 | — | not tested (needs restricted user) |
| AC-8 | selector `doSearch` "toyota", "BMW x5" | 51 items each; subtitles with plate/contacts |

## Traceability
| AC | Classes / fields | Test |
|---|---|---|
| AC-1 | `ObjectSearch::buildTextFilter`, `ObjectFilterFields::extractLookupFilter` | manual AC-1 |
| AC-2 | `ObjectSearch::buildTextFilter` (`UF_CRM_2_1722586103385`) | manual AC-2 |
| AC-3 | `ObjectFilterFields::applyToContactFilter` | manual AC-3 |
| AC-4 | `extractLookupFilter` (`@ID`) | manual AC-4 |
| AC-5 | `extractObjectFieldsFilter`, `prepareItemFilter` | manual AC-5 |
| AC-6 | `restrictContactIds` | manual AC-6 |
| AC-7 | `ObjectSearch::getReadableObjectIds` | — |
| AC-8 | `ObjectProvider::doSearch`, `dynamicSearchMatchMode` | manual AC-8 |

## Open questions
1. Automated test runner: PHPUnit needs a CLI, and there is no SSH. Proposal: assertion-based self-test script run through the admin PHP console — to confirm.
2. Restricted test user for AC-7.
3. Server log location.
4. Subtitle fields are hardcoded for vehicles/property — do other object types (agro, cargo) need different ones?
5. Does prod (mycrm) already have `init.php` or `/local` code?
