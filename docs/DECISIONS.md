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

### D-4: Filter semantics — one object, AND between text chips
- **Date:** 2026-10-15
- **Context:** User: "honda + სედანი → every Honda sedan; filter within filter".
- **Decision:** all conditions (picked IDs OR, each text AND, `OBJ130_*` AND) form one ORM filter on the same object; zero matches → `@ID=[0]`.
- **Alternatives rejected:** OR between texts (first version); conditions matched on different objects of the same contact.
- **Consequences:** results depend on data completeness (e.g. 1458 of 1525 Honda objects have an empty body type).
