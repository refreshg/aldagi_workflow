<!-- last-synced: 2026-10-15, commit: 301d031 -->
# Aldagi — Bitrix24 customizations

## Stack
- Bitrix24 **box** (self-hosted) on Ubuntu; `main` 26.600.0, `crm` 26.500.100 (prod reported to be the same version)
- PHP 8.3.33, MySQL
- Test portal: `https://testmycrm.aldagi.ge` — docroot `/var/www/html/bx-site`, PHP user `www-data`
- Prod portal: `https://mycrm.aldagi.ge` — same docroot/versions; module live since 2026-11-05 with option `lookups = ""` (vehicle fields only, D-11)
- No REST / webhooks / OAuth / business processes — code is a local PHP module

## Commands
No SSH access. All server work goes through the admin PHP console `/bitrix/admin/php_command_line.php`. Password only via env var, never in files.
```bash
# login (cookie jar)
curl -sk -c cj.txt -b cj.txt -X POST "https://testmycrm.aldagi.ge/auth/?login=yes" \
  --data-urlencode AUTH_FORM=Y --data-urlencode TYPE=AUTH \
  --data-urlencode USER_LOGIN=admin --data-urlencode "USER_PASSWORD=$BX_PASSWORD"
# sessid
SID=$(curl -sk -b cj.txt "https://testmycrm.aldagi.ge/bitrix/admin/php_command_line.php?lang=en" | grep -o 'sessid":"[0-9a-f]*' | head -1 | cut -d'"' -f3)
# run PHP from a file (code without the <?php tag)
curl -sk -b cj.txt -X POST "https://testmycrm.aldagi.ge/bitrix/admin/php_command_line.php?lang=en&mode=frame" \
  --data-urlencode "query@script.php" --data-urlencode result_as_text=y --data-urlencode ajax=y --data-urlencode "sessid=$SID"
```
- Deploy a file: in the console, `file_put_contents($_SERVER['DOCUMENT_ROOT'].'/local/<path>', base64_decode('<b64>'));`
- Lint (in console): `echo shell_exec('php -l '.escapeshellarg($path).' 2>&1');`
- Install module: `require $_SERVER['DOCUMENT_ROOT'].'/local/modules/aldagi.objectfilter/install/index.php'; (new aldagi_objectfilter())->DoInstall();`
- Restart/cache: not needed (opcache revalidates PHP files)
- Logs, automated test runner: not available yet (see Open questions in docs/SPEC.md)

## Layout
- `local/php_interface/init.php` — loads local modules before crm
- `local/modules/aldagi.objectfilter/` — contact filter by linked object (SPA 130)
- `docs/` — PRD (Georgian), SPEC, PLAN, ARCHITECTURE, DECISIONS (English)

## Conventions
- Module id `aldagi.<name>`, namespace `Aldagi\<Name>\...`, class file `lib/<subdir>/<lowercase>.php` (Bitrix autoload)
- Filter field ids: object fields `OBJ130_<SPA field>`; the lookup keeps the standard id `PARENT_ID_130`
- Entity-selector entity ids: `aldagi-<entity>`; text-match value prefix `search:`
- UI strings in Georgian, as class constants (no lang files yet)
- Tabs for indentation, Bitrix code style
- Commits: `feat(<module>): ...`, `fix(...)`, `docs(...)`, `chore(...)`
- Docs: PRD in Georgian, everything technical in English

## Rules (MUST / NEVER)
- **STANDARD FIRST**: before any custom code, check standard Bitrix24 — CRM settings, standard filter fields, robots, BP designer, standard REST methods. Custom code only after naming the standard feature checked, stating concretely why it doesn't fit, and getting my confirmation, recorded as `D-<n>` in `docs/DECISIONS.md`. Preference: configuration → robots/BP → service override/extension → new code/tables.
- NEVER edit `/bitrix/modules/*` (core) — only `/local`, ServiceLocator overrides, events
- NEVER commit passwords, webhook URLs or tokens
- ALWAYS respect CRM permissions when reading objects (`UserPermissions::itemsList()`)
- ALWAYS `php -l` on the server after each upload; a fatal in `init.php` takes down the whole portal, including the PHP console
- ALWAYS deploy to testmycrm first; prod (mycrm) only after my go-ahead
- If REST is ever used: ALWAYS `batch` for >50 calls, back off on `QUERY_LIMIT_EXCEEDED`

## Workflow
PRD → SPEC → PLAN → code → tests → `/docs-sync`. **PLAN.md must be approved by me before implementation starts.**

## Docs map
- `docs/PRD.md` — problem, users, acceptance criteria (Georgian)
- `docs/SPEC.md` — technical spec, standard-first check, traceability
- `docs/PLAN.md` — steps and status
- `docs/ARCHITECTURE.md` — components and data flow
- `docs/DECISIONS.md` — decisions (D-<n>)
- `local/modules/aldagi.objectfilter/README.md` — install and limitations
