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

## M4 — Verification and automated tests (needs approval)
- [ ] Confirm test runner approach (SPEC open question 1)
- [ ] Self-test script covering AC-1..6, AC-8 with fixed fixtures/expectations — `tests/objectfilter_selftest.php` (run through admin PHP console)
- [ ] AC-7 with a restricted user (needs a test user)
- [ ] Lookup UI check in a browser: dropdown, chips saved/restored in presets
- [ ] List load time with `search:toyota` (7628 objects)

## M5 — Production rollout (needs approval + date)
- [ ] Check prod (`mycrm.aldagi.ge`) versions of `main`, `crm`, `ui` and existing `/local`, `init.php`
- [ ] Upload `local/modules/aldagi.objectfilter/`, `php -l`
- [ ] `DoInstall()`
- [ ] Add the line to `init.php` (merge, never overwrite)
- [ ] Smoke test AC-1..4 on prod

## Status
M0–M3 done (testmycrm); M4–M5 — Not started
