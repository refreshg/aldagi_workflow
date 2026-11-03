<!-- last-synced: 2026-10-15, commit: 301d031 -->
# Decisions

### D-1: Replace the standard "ობიექტი" filter with a custom lookup
- **Date:** 2026-10-15 — confirmed by the user (standard-first answer: yes)
- **Context:** Contact filter field `PARENT_ID_130` queries `b_crm_entity_relation`, which has 0 rows for 130→3; objects are bound via `b_crm_entity_contact`. The field is single-select and searches by title only. The user explicitly asked for a lookup field and rejected a free-text field.
- **Decision:** Keep the id `PARENT_ID_130`, turn it into a multiple `entity_selector` backed by our provider; the filter checks both link types (UNION).
- **Alternatives rejected:** separate text field "ობიექტი (ძებნა ნებისმიერი პარამეტრით)" — rejected by the user; copying links into `b_crm_entity_relation` — doesn't give search by any field.
- **Consequences:** saved presets keep working; legacy `CRMDYNAMIC-130_<id>` values are supported.

### D-2: Extend the filter by overriding ServiceLocator `crm.filter.factory`
- **Date:** 2026-10-15
- **Context:** The filter factory and data providers fire no events; core edits are forbidden.
- **Decision:** `Aldagi\ObjectFilter\Filter\FilterFactory extends Crm\Filter\Factory`, registered before crm (`registerByModuleSettings` skips existing services). Applies only to `CRM_CONTACT_LIST*` filter ids.
- **Alternatives rejected:** denormalized text index in a contact UF synced from SPA events (stale data, sync code); copying the core list component.
- **Consequences:** after crm updates, re-check `ContactDataProvider` / `Factory` signatures.

### D-3: Own entity-selector provider in a local module
- **Date:** 2026-10-15
- **Context:** Providers load only from installed modules' `.settings.php`; the standard `dynamic` provider searches titles only.
- **Decision:** Module `aldagi.objectfilter` in `/local/modules`, entity `aldagi-object`; `dynamicSearchMatchMode: 'all'` so non-title matches are shown; top item `search:<text>` = all matches.
- **Alternatives rejected:** picking concrete objects only — "toyota" matches 7628 objects, manual picking is impossible.
- **Consequences:** the module must be installed (`DoInstall`); all code lives in one module.

### D-4: Filter semantics — one object, AND between text chips (partly superseded by D-5)
- **Date:** 2026-10-15
- **Context:** User: "honda + სედანი → every Honda sedan; filter within filter".
- **Decision:** all conditions (picked IDs OR, each text AND, `OBJ130_*` AND) form one ORM filter on the same object; zero matches → `@ID=[0]`.
- **Alternatives rejected:** OR between texts (first version); conditions matched on different objects of the same contact.
- **Consequences:** results depend on data completeness (e.g. 1458 of 1525 Honda objects have an empty body type).

### D-5: Facet-style grouping of text chips
- **Date:** 2026-10-23
- **Context:** With D-4, "toyota" + "bmw" returned nothing (no object is both). The user expects alternatives of the same kind to add up, while "honda" + "სედანი" must still narrow.
- **Decision:** each text chip gets a primary match field (`ObjectSearch::getPrimaryMatchField`: the field with most matching objects; a structured field wins over `TITLE` when it has ≥80% of the top count). Chips with the same primary field → OR; groups → AND, all on the same object.
- **Alternatives rejected:** pure AND (D-4); pure OR (honda + სედანი would return every sedan); shared-any-field overlap (bmw + x5 would become OR).
- **Consequences:** one extra aggregate query per chip (~0.27 s on 37k objects). Verified: toyota+bmw 6605, toyota+honda 6170, honda+სედანი 44, bmw+x5 344, toyota+bmw+სედანი 258 contacts.

### D-6: "დილი" and "კალკულაცია" lookups in the contact filter
- **Date:** 2026-10-23 — approved by the user ("შემდეგი ნაწილი")
- **Context:** Story: contacts must be searchable by attributes of their deals, insurance objects and saved calculations. Contact filter has no deal or calculation fields; calculations link to contacts only through the object (130→1040/1054/1080, then `b_crm_entity_contact`). LIKE over 153k deals takes seconds.
- **Decision:** two more lookups `ALDAGI_DEAL`, `ALDAGI_CALCULATION` built like D-1/D-3 (`LookupProvider` base, `aldagi-deal`, `aldagi-calculation`). Deal text search uses the standard `SEARCH_CONTENT` full-text index (`SearchEnvironment::prepareSearchFilter`); several deal chips are alternatives (OR), words inside one chip must all match. Calculation search covers 1040, 1054, 1080 (keys `<type>:<id>`) and skips technical fields whose label contains json/table/bug.
- **Alternatives rejected:** LIKE over all deal fields (5.4 s for two fields); own deal search index (duplicates the standard one).
- **Consequences:** deal search finds only what is in `SEARCH_CONTENT`; extra deal UFs need the standard `IS_SEARCHABLE` flag + index rebuild (not changed yet). Words shorter than 3 characters are ignored by full-text, so such deal queries return nothing (guard against "all deals").

### D-7: Lookups are chained through the object
- **Date:** 2026-10-23
- **Context:** User: "Kia Optima" + calculation "5 წელზე მეტი" must narrow to owners whose Kia Optima has that calculation, not to anyone with a Kia Optima and any 5+ calculation.
- **Decision:** `ContactLookupFilter` intersects object sets: object lookup/fields → objects; calculation → parent objects; deal (when combined) → child objects (2→130 relation); contacts of the remaining objects. A deal condition alone keeps the deal's contacts (`b_crm_deal_contact`).
- **Alternatives rejected:** independent per-contact AND (planned in D-6 draft) — mixes different cars of the same contact.
- **Consequences:** verified: kia optima + "5 წელზე მეტი" = 32 contacts, equal to a direct SQL check of the same object.

### D-8: Plain LIKE (`%=`) instead of ORM `%` for text search
- **Date:** 2026-10-23
- **Context:** ORM `%` builds `UPPER(col) LIKE '<PHP-uppercased value>'`; PHP turns Georgian into Mtavruli, MySQL does not → every Georgian search returned 0 (e.g. "5 წელზე მეტი": 0 vs 16 526; "თბილისი": 0 vs 415).
- **Decision:** `FactorySearch` uses `%=` with `%text%` and escaped wildcards; Latin stays case-insensitive through the collation (toyota: 7562 both ways).
- **Consequences:** applies to object and calculation search and iblock names. Standard Bitrix filters using `%` probably have the same Georgian issue (not ours to fix).

### D-9: Number filters for numeric list fields of the object
- **Date:** 2026-11-03 — confirmed by the user ("თუ კი გავაკეთოთ")
- **Context:** "Toyota, newer than 2000, engine above 2.0". Release year and engine volume are enumeration fields; the standard filter only lets you tick list values — no more/less/range.
- **Decision:** `NumericListFields` detects object enumeration fields with ≥3 values of which ≥90% parse as numbers (`გამოშვების წელი`, `ძრავის მოცულობა`, `ყველაზე ახალგაზრდა მძღოლის ასაკი`, `რემონტის... ლიმიტი USD`) and shows `OBJ130_<field>` as a `number` filter; the range is translated into the matching enum IDs (`@<field>`), on the same object as the lookup. "1990 - მდე" = just below 1990, "4.3 +" / "90 - ზე მეტი" = just above. No match → `@<field> = [-1]` (an empty field may be stored as 0).
- **Alternatives rejected:** ticking every year/volume by hand; a duplicate range field next to the list field.
- **Consequences:** verified: toyota + year>2000 + engine>2.0 = 1258 contacts, equal to SQL. New numeric-looking list fields become number filters automatically.

### D-10: Vehicle fields only, uniform formats, model depends on make
- **Date:** 2026-11-03 — confirmed by the user ("1) დამალე… 2) დიახ 3) გავაკეთოთ")
- **Context:** ~130 "ობიექტი: …" filter fields cluttered the list; some list fields came as a search dialog, others as a drop-down; the model list had all 1404 models. The user manages only vehicles for now.
- **Decision:** `ObjectFilterFields::CAR_FIELDS` — 27 vehicle fields in the order of the object card; other object fields are hidden. Formats: numbers → number filter, numeric lists → number (D-9), other enumerations → `list` drop-down, dates → date, text → text, booleans → checkbox. "ობიექტი: მოდელი" → entity-selector `aldagi-car-model` (`CarModelProvider`, model reference iblock 17, make via property `MARK` → iblock 16); the page script `local/js/aldagi/objectfilter/carmodel` (loaded on `/crm/contact/` pages) passes the makes currently chosen in "ობიექტი: მარკა" as the `makeIds` option and reloads the list when they change. No make chosen → search by model or make name.
- **Alternatives rejected:** dependent drop-downs — the standard filter has no such feature; one tree field "make → models" — the user wants the two card fields.
- **Consequences:** the script patches `BX.UI.EntitySelector.Dialog.prototype.show/getAjaxJson` for dialogs of `aldagi-car-model` only and uses `loadState` — re-check after `ui` module updates. Verified: Toyota → 91 models, Toyota+BMW → 180, "camry" with BMW → 0, model Camry → 1335 contacts (= SQL).
