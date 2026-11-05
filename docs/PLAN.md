<!-- last-synced: 2026-10-15, commit: 301d031 -->
# Plan — contact search by linked object

> M0–M3 were implemented on testmycrm before this plan existed (requested in chat, 2026-10-15). M4–M5 need approval.

## M0 — Standard-first verification
- [x] Standard filter field "ობიექტი" (`PARENT_ID_130`) — does not cover → SPEC Standard-first, D-1
- [x] Standard entity-selector `dynamic` — title only → D-3
- [x] Filter factory events — none exist → D-2
- [x] Contact filter does not expose linked SPA fields → D-2

## M1 — Filter extension (risky: core integration point)
- [x] `crm.filter.factory` override — `lib/filter/filterfactory.php`, `include.php`
- [x] `ContactDataProvider` + `applyActivityFastSearchFilter` hook — `lib/filter/contactdataprovider.php`
- [x] `OBJ130_*` fields and conversion — `lib/filter/objectfilterfields.php`

## M2 — Lookup
- [x] Local module `aldagi.objectfilter` — `install/index.php`, `install/version.php`, `.settings.php`
- [x] Entity-selector provider `aldagi-object` — `lib/entityselector/objectprovider.php`
- [x] Text search + permissions — `lib/objectsearch.php`
- [x] `PARENT_ID_130` → multiple `entity_selector`
- [x] `init.php` → `Loader::includeModule('aldagi.objectfilter')`

## M3 — Filter within filter
- [x] AND between text chips, OR between picked objects, `OBJ130_*` on the same object — `lib/filter/objectfilterfields.php`
- [x] Manual checks AC-1..6, AC-8 (SPEC → Tests)

## M3.1 — Dropdown order and chip grouping (bug fixes, 2026-10-23)
- [x] "ყველა ობიექტი..." always first, one row per text — `searchTabOptions.itemOrder`, lowercase id (`objectfilterfields.php`, `objectprovider.php`)
- [x] Same-field chips OR, different fields AND — `ObjectSearch::getPrimaryMatchField`, D-5

## Phase 2 — contact filter: "დილი" and "კალკულაცია" (approved 2026-10-23)

### M6 — Standard-first verification + risky parts first
- [x] Deal search through standard `SEARCH_CONTENT`: 0.04–0.22 s over 153k deals
- [ ] Agree the list of deal UFs to search → `IS_SEARCHABLE=Y` + index rebuild (waiting for the field list)
- [x] Calculation search: technical json/table/bug fields excluded; ~0.3 s
- [x] Recorded: SPEC Phase 2, D-6

### M7 — Generic search core
- [x] `lib/search/`: `LookupSearch`, `FactorySearch`, `ObjectSearch`, `DealSearch`, `CalculationTypeSearch`, `CalculationSearch`
- [x] `lib/entityselector/LookupProvider` base + Object/Deal/Calculation providers, `.settings.php`
- [x] Georgian text search fix (`%=`), D-8

### M8 — "დილი" lookup
- [x] Provider `aldagi-deal`, field `ALDAGI_DEAL`, contacts via `b_crm_deal_contact`; short-word guard

### M9 — "კალკულაცია" lookup
- [x] Provider `aldagi-calculation` (1040/1054/1080), field `ALDAGI_CALCULATION`, contacts via parent object

### M10 — Combination, tests, docs
- [x] Chain through the object (D-7) — `lib/filter/contactlookupfilter.php`
- [x] Manual checks: regression AC-1..6 unchanged; deal "ტყეშელაშვილი" 135, deal 173001 → 1, calc "kia" 324, kia optima + "5 წელზე მეტი" 32 (= SQL), deal + object 3; selector AJAX for all three
- [ ] User check in the browser; self-test script (M4)

## M11 — Number filters for numeric list fields (approved 2026-11-03)
- [x] `lib/filter/numericlistfields.php`, wired in `objectfilterfields.php` — D-9
- [x] Checks: toyota + year>2000 + engine>2.0 = 1258 (= SQL); range / less / exact / "4.3 +" / no match; list page rows verified

## M12 — Vehicle fields, formats, model by make (approved 2026-11-03)
- [x] 27 vehicle fields only, card order — `objectfilterfields.php` (`CAR_FIELDS`), D-10
- [x] Enumerations as drop-down lists; numbers/dates/text/checkbox native
- [x] `CarModelProvider` (`aldagi-car-model`) + page script `local/js/aldagi/objectfilter/carmodel`, loaded from `include.php` on `/crm/contact/`
- [x] Server checks: Toyota 91 models, Toyota+BMW 180, search "camry" (BMW → 0), model filter = SQL
- [ ] Browser check of the make → model drop-down (user)

## M4 — Verification and automated tests (needs approval)
- [ ] Confirm test runner approach (SPEC open question 1)
- [ ] Self-test script covering AC-1..6, AC-8 with fixed fixtures/expectations — `tests/objectfilter_selftest.php` (run through admin PHP console)
- [ ] AC-7 with a restricted user (needs a test user)
- [ ] Lookup UI check in a browser: dropdown, chips saved/restored in presets
- [ ] List load time with `search:toyota` (7628 objects)

## M5 — Production rollout (done 2026-11-05: vehicle fields only, D-11)
- [x] Check prod versions (main 26.600.0, crm 26.500.100, ui 26.500.0 — core files identical to test) and `/local`, `init.php` (none existed); 27/27 vehicle fields, iblocks 16/17, MARK property identical
- [x] Option `lookups = ""` set first; upload `local/modules/aldagi.objectfilter/` + `local/js/aldagi/objectfilter/` (23 files), `php -l`
- [x] `DoInstall()`; dry run in console: +27 fields, 0 changed/removed, TOYOTA+year>2000+engine>2.0 = 1309 = SQL
- [x] `/local/php_interface/init.php` created (did not exist) — rollback: delete this file
- [x] Smoke test: contact/deal/object lists + admin 200, no errors; Add field = 27 vehicle fields, standard ობიექტი unchanged, no დილი/კალკულაცია; model dropdown TOYOTA = 91; list rows all matching

## Status
Test: M0–M12 done. Prod (mycrm): vehicle fields (M11–M12) live since 2026-11-05, lookups off (D-11). Open: deal searchable fields, M4 automated tests, lookups on prod.
