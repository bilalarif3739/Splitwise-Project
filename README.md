# Splitwise-Like Expense Sharing API

A backend-only REST API for sharing expenses inside groups — built with **Laravel 12** and **MongoDB**.
No SQL database, no JWT/Sanctum/Passport: authentication is a custom random-token scheme, and every
document lives in MongoDB.

---

## Stack

| Component      | Choice                                                                                                                                                                                 |
| -------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Framework      | Laravel 12 (PHP 8.2+)                                                                                                                                                                  |
| Database       | MongoDB via `mongodb/laravel-mongodb` (>= 5.8.1), requires the PECL `mongodb` extension                                                                                                |
| Authentication | Random bearer tokens — `bin2hex(random_bytes(32))` — stored in a `tokens` collection, checked by a custom middleware. No token is derived from a user id, email, password or timestamp |
| Receipts       | MongoDB GridFS (bucket `attachments`)                                                                                                                                                  |
| Transactions   | MongoDB multi-document transactions                                                                                                                                                    |

> **Why >= 5.8.1:** `mongodb/laravel-mongodb` 5.8.0 ships a broken `orderBy()` which raises
> `Class "SortDirection" not found` on every sorted query.

---

## Requirements

- PHP >= 8.2 with the `mongodb` PECL extension
- Composer
- **MongoDB running as a replica set** (a single-node set is sufficient). The application uses
  multi-document transactions for writes that span more than one collection, and a standalone
  `mongod` rejects them with _"Transaction numbers are only allowed on a replica set member or mongos"_.

---

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configure the MongoDB connection in `.env` (the app defines no SQL connections at all):

```
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mongodb
DB_HOST=127.0.0.1
DB_PORT=27017
DB_DATABASE=splitwise
```

Create collections and indexes, then load the demo data:

```bash
php artisan migrate     # creates the MongoDB collections and their indexes
php artisan db:seed     # 12 users, 4 groups, 12 expenses, 3 settlements
php artisan serve       # http://127.0.0.1:8000
```

`GET /api/ping` confirms the API and the active driver are alive.

---

## Tests

```bash
php artisan test
```

65 tests / ~660 assertions covering authentication, groups and membership, all three expense split
types (valid and invalid), balances, debt simplification, settlements and transaction rollback.

Tests run against a dedicated **`splitwise_test`** database which is wiped before every test, so the
development data is never touched. (Laravel's usual `RefreshDatabase` relies on SQL transactions and
is not usable here; `tests/TestCase.php` replaces it.)

---

## Demo data

All seeded users share the password `password123`:

```
POST /api/login
{ "email": "ali@example.com", "password": "password123" }
```

12 users, 4 groups (Dubai Trip, Office Lunch, Apartment, Friends Weekend), 12 expenses across all
three split types and 3 settlements.

> Postman variables such as `member_id`, `group_id` and `expense_id` are filled in by hand and become
> stale after `db:seed` — re-copy them from Compass or from the relevant list response. Only `token`
> and `user_id` refresh automatically (they come from `POST /api/login`).

---

## API overview

See [`docs/API.md`](docs/API.md) for request bodies, validation rules and success/error examples.

| Method    | Endpoint                                   | Who                                       |
| --------- | ------------------------------------------ | ----------------------------------------- |
| POST      | `/api/register`                            | public                                    |
| POST      | `/api/login`                               | public                                    |
| POST      | `/api/logout`                              | bearer token                              |
| GET       | `/api/me`                                  | bearer token                              |
| GET       | `/api/groups`                              | bearer token (own groups)                 |
| POST      | `/api/groups`                              | bearer token                              |
| GET       | `/api/groups/{group}`                      | group member                              |
| PUT/PATCH | `/api/groups/{group}`                      | group owner                               |
| DELETE    | `/api/groups/{group}`                      | group owner                               |
| GET       | `/api/groups/{group}/members`              | group member                              |
| POST      | `/api/groups/{group}/members`              | group owner                               |
| DELETE    | `/api/groups/{group}/members/{user}`       | group owner                               |
| GET       | `/api/groups/{group}/expenses`             | group member                              |
| POST      | `/api/groups/{group}/expenses`             | group member                              |
| GET       | `/api/expenses/{expense}`                  | member of the expense's group             |
| PUT/PATCH | `/api/expenses/{expense}`                  | payer or group owner                      |
| DELETE    | `/api/expenses/{expense}`                  | payer or group owner                      |
| GET       | `/api/groups/{group}/balances`             | group member                              |
| GET       | `/api/groups/{group}/debts`                | group member                              |
| GET       | `/api/groups/{group}/settlements`          | group member                              |
| POST      | `/api/groups/{group}/settlements`          | group member (payer = authenticated user) |
| POST      | `/api/settlements/{settlement}/attachment` | member of the settlement's group          |
| GET       | `/api/settlements/{settlement}/attachment` | member of the settlement's group          |
| DELETE    | `/api/settlements/{settlement}/attachment` | member of the settlement's group          |

Every response uses one envelope: `{success, message, data}` on success, `{success, message, errors}`
on failure. Statuses: 200, 201, 401, 403, 404, 422, 500 (sanitised — stack traces, passwords, tokens
and database internals are never returned).

---

## Database design

| Collection                                 | Holds                                                           | Key fields                                                      |
| ------------------------------------------ | --------------------------------------------------------------- | --------------------------------------------------------------- |
| `users`                                    | accounts                                                        | `name`, `email` (unique), `password` (hashed)                   |
| `tokens`                                   | one document per issued token                                   | `api_token` (unique), `user_id`                                 |
| `groups`                                   | expense groups                                                  | `owner_id`, `member_ids[]`                                      |
| `expenses`                                 | one document per expense with its split embedded                | `group_id`, `paid_by`, `amount`, `split_type`, `participants[]` |
| `settlements`                              | payments between members, optional attachment metadata embedded | `group_id`, `paid_by`, `paid_to`, `amount`, `attachment`        |
| `group_balances`                           | materialised net balance per member per group                   | `group_id`, `user_id`, `net_balance`                            |
| `attachments.files` / `attachments.chunks` | GridFS bucket holding receipt binaries                          | `filename`, `length`, `metadata`                                |

**Embedded vs referenced**

- `participants` is **embedded** in the expense: it belongs to exactly one expense, is small and is
  always read together with it.
- `member_ids` is an **array of user ids** inside the group, indexed as a multikey index — membership
  checks become a single index seek, and membership changes are atomic `$addToSet` / `$pull` updates.
- Everything else is **referenced by id**. Ids are stored as 24-character strings and compared as
  strings, except `_id` / `*._id` lookups where the driver converts them automatically.
- The attachment **metadata is embedded** in the settlement (tiny, always displayed with it) while the
  **binary lives in GridFS**, because a file can exceed MongoDB's 16 MB document limit.
- `group_balances` is **materialised** for read speed, and is always rebuildable from the expenses and
  settlements of the group.

**Indexes** (created by `php artisan migrate`)

`users.email` (unique) · `tokens.api_token` (unique) · `groups.member_ids` (multikey) ·
`expenses.{group_id, created_at}` · `expenses.paid_by` · `expenses.split_type` ·
`settlements.{group_id, created_at}` · `group_balances.{group_id, user_id}` (unique)

---

## Architecture

```
Request
  → AuthenticateApiToken      (bearer token → user, 401 otherwise)
  → EnsureGroupMember/Owner   (resolves the group, asks GroupPolicy → 403 otherwise)
  → Form Request              (all validation; controllers never validate)
  → Controller                (request → service → response envelope)
  → Service                   (business rules, split maths, balances, transactions)
  → Model / MongoDB
```

- **Services** own the rules: `AuthenticationService`, `GroupService`, `ExpenseService`,
  `BalanceService`, `SettlementService`, `SettlementAttachmentService`.
- **Policies** (`GroupPolicy`, `ExpensePolicy`) own the authorization decisions; the group middleware
  and the expense service ask them rather than duplicating the rules.
- **Exceptions** are named business exceptions; a single renderer in `bootstrap/app.php` maps them to
  the right status code and the standard envelope.
- **Logging** (§39): registrations, expense/settlement creation _and their rejections_, group
  operations and denials. Passwords, tokens and full request payloads are never logged.

---

## Transactions

MongoDB transactions are used wherever one request writes to more than one document/collection:

| Operation                                      | Documents written together                |
| ---------------------------------------------- | ----------------------------------------- |
| `AuthenticationService::register`              | user + token                              |
| `ExpenseService::create` / `update` / `delete` | expense + `group_balances`                |
| `SettlementService::create`                    | settlement + two balance rows             |
| `GroupService::delete`                         | group + expenses + settlements + balances |
| `GroupService::removeMember`                   | `member_ids` + balance row                |

Single-document writes rely on MongoDB's own atomicity and need no transaction. Validation always
runs _before_ the transaction opens, so a rejected request never starts a session.
`tests/Feature/TransactionTest.php` proves that a failure inside a transaction leaves no documents behind.

---

## Known limitations

- The database must be a replica set (see Requirements) — a standalone `mongod` cannot run transactions.
- A settlement has at most one receipt; uploading again replaces the previous file (and removes it from GridFS).
- Settlements cannot be edited or deleted — they are financial records, and the brief asks for creation
  and history only. Correcting one means deleting it _and_ reversing its balance effect, which is out of scope.
- Bonus features from the brief (expense categories, recurring expenses, search, receipt upload for
  expenses, notifications) are deliberately not implemented.

---

## Project layout

```
app/
  Exceptions/     named business exceptions
  Http/
    Controllers/Api/   thin controllers
    Middleware/        AuthenticateApiToken, EnsureGroupMember, EnsureGroupOwner
    Requests/          all validation
    Resources/         response shaping
    Responses/         ApiResponse envelope
  Models/         User, Token, Group, Expense, Settlement, GroupBalance (+ BaseModel)
  Policies/       GroupPolicy, ExpensePolicy
  Services/       the six services above
routes/api/       auth, groups, expenses, balances, settlements
tests/Feature/    AuthTest, GroupTest, ExpenseTest, BalanceTest, SettlementTest, TransactionTest
docs/API.md       endpoint reference
postman/          Postman collection + environment
```
