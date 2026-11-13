<!-- last-synced: 2026-11-13, commit: 862a93f -->
# Technical spec — contact and deal search by linked object

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

Filter values (`b_user_option`, `main.ui.filter` / `CRM_CONTACT_LIST_V12`, `CRM_DEAL_LIST_V12[_C_<n>]`):
| Field | Format |
|---|---|
| `PARENT_ID_130` (MODIFIED behaviour) | array of: object ID, `search:<text>`, legacy `CRMDYNAMIC-130_<id>`, or JSON `["aldagi-object", id]` |
| `OBJ130_<field>` (NEW) | SPA filter UI format (`_from`, `_to`, `_datesel`, `_numsel`...); model = array of model element IDs |
| `ALDAGI_DEAL`, `ALDAGI_CALCULATION` (NEW, contacts) | like `PARENT_ID_130`; calculation keys `<entityTypeId>:<id>` |

Module option `aldagi.objectfilter` / `lookups` (D-11): absent = `object,deal,calculation` (test); `""` on prod.

## Business logic
| # | Trigger | Condition | Action | AC | Standard coverage |
|---|---|---|---|---|---|
| L-1 | Contact list filter build | `filterId` starts with `CRM_CONTACT_LIST` | if the `object` lookup is on (D-11): `PARENT_ID_130` replaced by multiple `entity_selector` (`aldagi-object`); 27 vehicle `OBJ130_*` fields added (L-14) | AC-1..5 | custom: D-1, D-2, D-11 |
| L-2 | Lookup search (`ui.entityselector.doSearch`) | non-empty text | item `search:<text>` + ≤50 newest matches, permission-filtered | AC-1, AC-2, AC-8 | custom: D-3 |
| L-3 | `ContactDataProvider::applyActivityFastSearchFilter` | `PARENT_ID_130` or `OBJ130_*` present | one ORM filter on the object: picked IDs OR; text chips grouped by primary match field (same field OR, groups AND); `OBJ130_*` AND → contact IDs → `@ID` | AC-1..6 | custom: D-1, D-5 |
| L-7 | Lookup dropdown render | always | `searchTabOptions.itemOrder = {sort: 'asc nulls last'}`; text item sort 0, id lowercased → always first, no duplicates | AC-1 | custom: D-3 |
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
| Year / engine as more-than / range | number filter on list (enumeration) fields | no — lists only allow ticking values | `NumericListFields`, D-9 |
| Model list limited to the chosen make | dependent filter fields | no — not supported by the filter | `CarModelProvider` + page script, D-10 |
| Filter deals by object make / model / year | deal filter fields (54) | no — no object fields | `DealDataProvider`, D-12 |
| Different feature set per portal | configuration of our own module | n/a | module option `lookups`, D-11 |

## Views / UI
| Element | Type | Identifier | Notes |
|---|---|---|---|
| "ობიექტი" | filter field `entity_selector`, multiple | `PARENT_ID_130`, dialog context `ALDAGI_CONTACT_FILTER_OBJECT` | `dynamicSearch`, `dynamicSearchMatchMode: 'all'`, `dropdownMode: false` |
| "ყველა ობიექტი, რომელიც შეიცავს «X»" | selector item | id `search:X`, sort 0 | subtitle "ნაპოვნია N ობიექტი" |
| Object item | selector item | id = object ID | title `TITLE`; subtitle type · plate/VIN/code · contacts; link `/crm/type/130/details/<id>/` |
| "ობიექტი: <field>" — contacts: 27 vehicle fields (`CAR_FIELDS`); deals: მარკა, მოდელი, გამოშვების წელი, ავტომობილის ღირებულება, ვალუტა (`DEAL_FIELDS`) | filter fields | `OBJ130_<field>` | in "Add field", not default; list → `list`, numeric list → `number`, model → `entity_selector` `aldagi-car-model` |
| "დილი", "კალკულაცია" (contacts, when on) | filter fields `entity_selector`, multiple | `ALDAGI_DEAL`, `ALDAGI_CALCULATION` | same dialog options as `PARENT_ID_130` |

## Security
- No new groups or access rules
- Objects: READ permission on all SPA 130 categories (L-5); anonymous → empty
- Contacts: the standard list applies contact permissions itself
- `LookupProvider::isAvailable()`, `CarModelProvider::isAvailable()`: authorized users only
- Car model reference (iblock 17) is read without iblock permission checks — reference data only
- Deals: the standard deal list / kanban applies deal permissions itself

## Integrations
- No external integrations
- Internal AJAX: `ui.entityselector.load` / `doSearch` (core controller, JSON body, CSRF header `X-Bitrix-Csrf-Token`); `crm.contact.list/filter.ajax.php`, `crm.deal.list/filter.ajax.php` (`action=list|field`)

## Bitrix specifics
- Box; no REST/OAuth/webhooks/BP
- ServiceLocator override: `crm.filter.factory` → `Aldagi\ObjectFilter\Filter\FilterFactory`, registered before crm (`registerByModuleSettings` skips existing services)
- `.settings.php` → `ui.entity-selector.entities`: `aldagi-object`, `aldagi-deal`, `aldagi-car-model`, `aldagi-calculation`
- Hook point: `EntityDataProvider::applyActivityFastSearchFilter`, called from `CCrmEntityHelper::applySubQueryBasedFiltersWrapper` (lists), `Kanban\Entity::applySubQueryBasedFilters` (deal kanban) and `Filter\Factory::getFilterValue`
- Page script `aldagi.objectfilter.carmodel` (`local/js/aldagi/objectfilter/carmodel`) loaded on `/crm/contact/` and `/crm/deal/` from an `OnProlog` handler in `include.php`

## Migration / data
- Install: no data changes; `ModuleManager::registerModule('aldagi.objectfilter')`
- Saved filters with `PARENT_ID_130 = CRMDYNAMIC-130_<id>` keep working (L-3)
- 2026-10-15 (test portal): obsolete `OBJ130_ANY` removed from user 1's saved filters
- Uninstall: `DoUninstall()` + remove the line from `init.php` → standard behaviour returns
- 2026-11-05 prod (mycrm): no prior `/local` / `init.php`; option `lookups = ""` set before enabling (D-11)

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
| AC-9 | `toyota` + `bmw` / `honda` + `სედანი` | 6605 / 44 contacts (2026-10-23) |
| AC-10 | dropdown order | confirmed by the user in the browser (2026-10-23) |
| AC-11 | deal "ტყეშელაშვილი" / deal 173001 | 135 / 1 contacts |
| AC-12 | calculation "kia" | 324 contacts |
| AC-13 | object "kia optima" + calculation "5 წელზე მეტი" | 32 contacts = SQL |
| AC-14 | TOYOTA + year > 2000 + engine > 2.0 | test 1258 = SQL; prod 1309 = SQL (2026-11-05) |
| AC-15 | deals: TOYOTA / TOYOTA + year > 2015 / Camry / year 2010–2012 | 7405 / 3559 / 1585 / 1979 = SQL; list rows verified (2026-11-13) |

## Traceability
| AC | Classes / fields | Test |
|---|---|---|
| AC-1 | `FactorySearch::buildTextFilter`, `ObjectFilterFields::extractObjectIds` | manual AC-1 |
| AC-2 | `FactorySearch::buildTextFilter` (`UF_CRM_2_1722586103385`) | manual AC-2 |
| AC-3 | `FactorySearch::buildChipsFilter`, `getPrimaryMatchField` | manual AC-3 |
| AC-4 | `FactorySearch::buildLookupFilter` (`@ID`) | manual AC-4 |
| AC-5 | `ObjectFilterFields::extractObjectFieldsFilter`, `prepareItemFilter` | manual AC-5 |
| AC-6 | `LookupHelper::restrictContactIds` | manual AC-6 |
| AC-7 | `FactorySearch::getReadableIds` | — |
| AC-8 | `LookupProvider::doSearch`, `dynamicSearchMatchMode` | manual AC-8 |
| AC-9, AC-10 | `FactorySearch::buildChipsFilter`; `LookupHelper::getFieldData` (`searchTabOptions.itemOrder`) | manual AC-9, AC-10 |
| AC-11..13 | `DealSearch`, `CalculationSearch`, `ContactLookupFilter::applyToContactFilter` | manual AC-11..13 |
| AC-14 | `NumericListFields::extractFilter` | manual AC-14 |
| AC-15 | `DealDataProvider::applyActivityFastSearchFilter`, `CarModelProvider` | manual AC-15 |

## Phase 2 — "დილი" and "კალკულაცია" lookups in the contact filter (implemented 2026-10-23, see D-6..D-8)

### Data (verified on testmycrm, 2026-10-23)
| Link | Storage | Rows |
|---|---|---|
| deal ↔ contact | `b_crm_deal_contact` | 153 988 (153 355 deals) |
| deal → object (parent) | `b_crm_entity_relation` 2→130 | 36 411 |
| object → calculation (parent) | `b_crm_entity_relation` 130→1040 / 130→1054 / 130→1080 | 25 176 / 921 / 82 |
| calculation ↔ contact directly | `b_crm_entity_contact`, `CONTACT_ID` | 0 — only through the object |

| Entity | Table | Search index |
|---|---|---|
| Deal (2), 53 UF (25 string, 12 enum, 5 iblock) | `b_crm_deal` + `b_uts_crm_deal` | standard `SEARCH_CONTENT` + FULLTEXT `IX_B_CRM_DEAL_SEARCH` (0.03 s); no UF has `IS_SEARCHABLE=Y` |
| Calculations 1040 / 1054 / 1080 (22 / 19 / 16 UF) | `b_crm_dynamic_items_<id>` | none; LIKE like objects |

Measured: LIKE on deals (TITLE + 1 UF through UTS join) = 5.4 s → LIKE over all deal fields is not acceptable.

### Standard-first check
| Requirement | Standard feature checked | Covers it? | Approach |
|---|---|---|---|
| Find deals by text fast | deal `SEARCH_CONTENT` full-text (same as deal list search box) | partial — fast, but includes UFs only when `IS_SEARCHABLE=Y` (0 today) | **standard**: use `SearchEnvironment` full-text + mark needed deal UFs searchable (config) + rebuild index |
| Deal lookup in contact filter | contact filter fields | no — no deal fields | custom lookup `ALDAGI_DEAL`, D-6 |
| Calculation lookup in contact filter | contact filter `PARENT_ID_*` fields | no — calculation is a child of the object, not a parent of the contact | custom lookup `ALDAGI_CALCULATION`, D-6 |

### Planned logic
| # | Trigger | Action | AC |
|---|---|---|---|
| L-8 | contact filter build | add `ALDAGI_DEAL`, `ALDAGI_CALCULATION` (`entity_selector`, multiple, same dialog options as `PARENT_ID_130`) | AC-11, AC-12 |
| L-9 | `aldagi-deal` search | full-text `SEARCH_CONTENT` via `SearchEnvironment`, permission-filtered; item `search:<text>` on top | AC-11 |
| L-10 | `aldagi-calculation` search | LIKE over title + scalar UFs of 1040/1054/1080, JSON/table fields excluded; items labelled by calculation type | AC-12 |
| L-11 | contact filter apply (`ContactLookupFilter`) | chained through the object (D-7): object set ∩ calculation parents ∩ deal children → `b_crm_entity_contact`; deal alone → `b_crm_deal_contact` | AC-11..13 |
| L-14 | contact filter build | only the 27 vehicle fields (`CAR_FIELDS`); enumerations as `list`; model as `aldagi-car-model` selector limited to makes chosen in the make field (page script) (D-10) | AC-5 |
| L-15 | deal filter build/apply (`CRM_DEAL_LIST*`) | object fields make/model/year/value/currency (same formats); matching objects → deals via `b_crm_entity_relation` 2→130 → `@ID` (D-12) | AC-15 |
| L-13 | `OBJ130_<numeric list field>` | number filter (exact/range/more/less) → matching enum IDs `@<field>`, same object as the lookup (D-9) | AC-14 |
| L-12 | text search | `%=` LIKE (D-8); deal full-text needs a word of ≥3 chars, else no match | AC-1, AC-11, AC-12 |

## Open questions
1. Automated test runner: PHPUnit needs a CLI, and there is no SSH. Proposal: assertion-based self-test script run through the admin PHP console — to confirm.
2. Restricted test user for AC-7.
3. Server log location.
4. Subtitle fields are hardcoded for vehicles/property — do other object types (agro, cargo) need different ones?
5. ~~Does prod (mycrm) already have `init.php` or `/local` code?~~ Resolved 2026-11-05: no.
6. Calculation SPAs: all three (1040, 1054, 1080) assumed — confirm.
7. Which deal attributes must be searchable (→ which deal UFs get `IS_SEARCHABLE=Y`)?

## Drift log
- 2026-11-13: corrected to match the code — L-1 (27 vehicle fields; lookups depend on option `lookups`), UI row "ობიექტი: <field> (~129)" → 27 contact / 3 deal fields, `.settings.php` entities (1 → 4), hook points (+ deal kanban, page script), Security (`ObjectProvider` → `LookupProvider`, + `CarModelProvider`), Traceability class names (`ObjectSearch::*`, `extractLookupFilter`, `getReadableObjectIds` → `FactorySearch`, `LookupHelper`, `ContactLookupFilter`).
