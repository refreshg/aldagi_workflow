<!-- last-synced: 2026-10-15, commit: 301d031 -->
# aldagi.objectfilter

Contact list filter lookup "ობიექტი" (SPA 130): searches objects by any field and keeps the contacts that own them. Also exposes every SPA 130 field as a separate filter field ("ობიექტი: ...").

## Dependencies
- Bitrix24 box: `main`, `crm`, `ui`, `iblock` (tested: main 26.600.0, crm 26.500.100)
- PHP ≥ 8.1 (`?->`, `??=`, `str_starts_with`); tested on 8.3
- SPA `entityTypeId=130` and its fields (constants in `ObjectSearch`)

## Install / upgrade
1. Copy this folder to `/local/modules/aldagi.objectfilter/`
2. `php -l` every file (through the admin PHP console)
3. Register once: Marketplace → Installed solutions, or in the console `(new aldagi_objectfilter())->DoInstall();`
4. Add to `/local/php_interface/init.php` (merge, don't overwrite): `\Bitrix\Main\Loader::includeModule('aldagi.objectfilter');` — must run **before crm** loads
5. Upgrade: replace files; no restart needed

## Configuration
- No settings, groups or cron jobs
- Permissions: standard CRM read access to SPA 130

## Known limitations
- Contact list only (`CRM_CONTACT_LIST*`); the main search box (FIND) does not search objects
- "ნაპოვნია N ობიექტი" in the dropdown ignores chips already selected
- Searches filled fields only; multiple UFs are not part of the text search
- `iblock_element` fields: ≤1000 matching elements per field
- Subtitle fields are hardcoded (`ObjectSearch::SUBTITLE_FIELDS`)

## Docs
[../../../docs/](../../../docs/) — PRD, SPEC, PLAN, ARCHITECTURE, DECISIONS
