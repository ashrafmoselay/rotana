# Project Instructions

## Project

This is an existing production Laravel application.

Treat the existing behavior as the source of truth unless the requested task
explicitly requires changing it.

The primary goal is:

- minimal changes
- no regressions
- preserve existing business logic
- preserve backward compatibility
- avoid unrelated refactoring

---

## Laravel

Follow the existing Laravel architecture and coding style.

Before creating new classes, services, helpers, repositories, traits,
middleware, migrations, or abstractions, search for an existing implementation
that can be reused.

Do not introduce a new architectural pattern unless explicitly requested.

Prefer modifying the smallest possible number of files.

---

## Allowed project areas

Focus primarily on:

- app/
- routes/
- resources/views/
- resources/js/
- resources/css/
- database/migrations/
- database/seeders/
- config/
- tests/

Read other project files only when they are directly relevant to the task.

---

## Ignore by default

Do NOT inspect, search, index, modify, or analyze these directories unless
explicitly required:

- vendor/
- node_modules/
- storage/framework/
- storage/logs/
- bootstrap/cache/
- public/build/
- public/vendor/
- .git/
- coverage/
- dist/

Do not recursively scan the whole repository without a specific reason.

---

## Scope control

For every task:

1. Understand the requested behavior.
2. Identify the likely relevant files.
3. Inspect only those files and their direct dependencies.
4. Make the smallest safe change.
5. Test the affected behavior.
6. Review the final diff.

Do not fix unrelated issues discovered during the task.

Do not perform cleanup or refactoring outside the requested scope.

If an unrelated issue is discovered, report it separately without modifying it.

---

## Existing code

Before changing a method:

- find its callers
- identify related business rules
- inspect relevant tests
- check model relationships if applicable

Do not redesign working code merely because another implementation appears
cleaner.

---

## Database

Never:

- drop tables
- truncate production data
- rename columns casually
- modify historical migrations
- delete existing migrations

Prefer new backward-compatible migrations when database changes are required.

Avoid destructive schema changes unless explicitly requested.

---

## Financial logic

Wallets, balances, payments, installments, transactions, accounting,
withdrawals, deposits and settlements are HIGH-RISK areas.

For these areas:

- inspect the complete transaction flow
- preserve database transactions
- preserve row locking
- check rollback behavior
- check duplicate execution
- check idempotency
- verify balance direction
- verify linked ledger records

Never change financial logic based on assumptions.

---

## Git

Never automatically:

- commit
- push
- merge
- rebase
- reset
- force push

unless explicitly requested.

Always review:

git diff

before considering the task complete.

---

## Testing

Run the smallest relevant tests first.

Do not run the entire test suite unless necessary.

Preferred order:

1. targeted test
2. related feature tests
3. broader test group only if needed

Do not modify tests merely to make a broken implementation pass.

---

## UI

Preserve the existing UI framework and visual identity.

Use existing:

- Bootstrap
- AdminLTE
- jQuery
- DataTables
- Select2
- SweetAlert
- Toastr
- ApexCharts

when they are already used by the project.

Do not introduce a new frontend framework without explicit approval.

---

## Final response

After implementation report only:

1. Root cause / requested change
2. Files changed
3. What was changed
4. Tests performed
5. Any remaining risk

Keep the report concise.
