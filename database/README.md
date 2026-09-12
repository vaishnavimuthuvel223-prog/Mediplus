# MediPlus database files

**Import `mediplus_full.sql` only.** It is the single merged file — it already
contains everything from `schema.sql` plus everything `migration_token_travel.sql`
used to add (booking type, consultation timer, travel reminders, walk-in
tokens, hospital location settings), and everything `migration_patient_module.sql`
used to add (consultation records, prescriptions/pharmacy, lab tests). You do
not need any of the three separately for a fresh install.

If you already have a live database from before the Patient Module work,
just run the new migration once — it's pure `CREATE TABLE IF NOT EXISTS`,
nothing existing is touched:
```
mysql -u root mediplus < database/migration_patient_module.sql
```

```
mysql -u root -p < database/mediplus_full.sql
```

Or in phpMyAdmin: create/open the `mediplus` database → Import → choose
`mediplus_full.sql` → Go.

`schema.sql` and `migration_token_travel.sql` are kept in this folder only
as historical reference (e.g. if you ever need to upgrade an old existing
database that was created before the migration existed). For any new
install, use `mediplus_full.sql` and ignore the other two.

### Seeded logins after import
| Role   | Username        | Password         |
|--------|-----------------|------------------|
| Admin  | admin_001       | (set by you — hash is pre-seeded, reset it before go-live) |
| Doctor | doc_dept1_01 / doc_dept2_01 / doc_dept3_01 | `Doctor@2026!` |

Patients register themselves from the login hub. Nurse and management
accounts should be created directly in the `users` table (or via an
admin tool) since public registration is intentionally disabled for
staff roles.
