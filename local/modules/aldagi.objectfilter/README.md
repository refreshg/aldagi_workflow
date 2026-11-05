<!-- last-synced: 2026-10-15, commit: 301d031 -->
# aldagi.objectfilter

Contact list filter lookups "ობიექტი" (SPA 130), "დილი" (deals) and "კალკულაცია" (SPA 1040/1054/1080): each searches its items by any field and keeps the linked contacts; combined lookups are chained through the object. Also exposes every SPA 130 field as a separate filter field ("ობიექტი: ...").

## Dependencies
- Bitrix24 box: `main`, `crm`, `ui`, `iblock` (tested: main 26.600.0, crm 26.500.100)
- PHP ≥ 8.1 (`?->`, `??=`, `str_starts_with`); tested on 8.3
- SPA `entityTypeId=130` and its fields (constants in `ObjectSearch`)

## Install / upgrade
1. Copy this folder to `/local/modules/aldagi.objectfilter/` and `local/js/aldagi/objectfilter/` to `/local/js/aldagi/objectfilter/`
2. `php -l` every file (through the admin PHP console)
3. Register once: Marketplace → Installed solutions, or in the console `(new aldagi_objectfilter())->DoInstall();`
4. Add to `/local/php_interface/init.php` (merge, don't overwrite): `\Bitrix\Main\Loader::includeModule('aldagi.objectfilter');` — must run **before crm** loads
5. Upgrade: replace files; no restart needed

## Configuration
- Module option `lookups` (comma list `object,deal,calculation`, default all): which lookups are on for this portal. Prod: empty (vehicle fields only). Set with `\Bitrix\Main\Config\Option::set('aldagi.objectfilter', 'lookups', '...')`
- No groups or cron jobs
- Permissions: standard CRM read access to SPA 130

## Known limitations
- Contact list only (`CRM_CONTACT_LIST*`); the main search box (FIND) does not search objects
- "ნაპოვნია N ობიექტი" in the dropdown ignores chips already selected
- Searches filled fields only; multiple UFs are not part of the text search
- `iblock_element` fields: ≤1000 matching elements per field
- Subtitle fields are hardcoded (`ObjectSearch::SUBTITLE_FIELDS`)
- Deals: searched through the standard full-text index only (title, ID, client, stage...); deal UFs need `IS_SEARCHABLE=Y` + index rebuild; words <3 characters are ignored
- Calculation fields whose label contains json/table/bug are not searched
- Object list fields with numeric values (year, engine volume...) are filtered as numbers (more/less/range); detected automatically: ≥3 values, ≥90% numeric
- Only the 27 vehicle fields of the object are offered (`ObjectFilterFields::CAR_FIELDS`); add a field code there to expose more
- Model drop-down depends on the chosen make via `local/js/aldagi/objectfilter/carmodel/script.js` (deploy it together with the module); it patches the entity-selector dialog — re-check after `ui` updates

## Docs
[../../../docs/](../../../docs/) — PRD, SPEC, PLAN, ARCHITECTURE, DECISIONS
