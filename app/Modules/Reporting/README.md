# M16 — Reporting

M16 exposes a closed, read-only catalog of operational and financial reports. It owns report contracts, scope enforcement, normalized parameters, technical runs, temporary protected results, query audit metadata and outbox events. It never updates business records.

## API

All routes require an active Sanctum session.

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/reports` | Catalog permitted to the current actor. |
| `GET` | `/api/v1/reports/{code}/definition` | Authorized public contract. |
| `GET` | `/api/v1/reports/{code}` | Bounded synchronous JSON query. |
| `POST` | `/api/v1/reports/{code}/runs` | Idempotent asynchronous run request. |
| `GET` | `/api/v1/report-runs` | Current actor's runs. |
| `GET` | `/api/v1/report-runs/{run}` | Current actor's run metadata. |
| `GET` | `/api/v1/report-runs/{run}/results` | Protected paginated result after completion. |

`POST` requires `Idempotency-Key`. M16 does not expose exports, subscriptions, e-mail delivery or relation downloads.

## Role and scope matrix

| Role | Catalog | Mandatory scope |
| --- | --- | --- |
| General manager | All official reports | `GLOBAL` |
| Branch manager | All official reports except those explicitly global-only | `BRANCH` from the session |
| Coordinator | Reports applicable to assigned work; manual reconciliations excluded | `COORDINATOR` plus session branch |
| Administrator | All official reports, read-only | `GLOBAL` |
| Distributor | Own credit, vouchers, relations, payments, portfolio, excesses, refunds, points and mobility | `DISTRIBUTOR` from the session |
| Cashier | Denied | None |
| Verifier | Denied | None |
| Final client | No session and no access | None |

Runs are visible only to their requester. Cross-user visibility for managers remains denied because the functional source does not define it.

## Official contracts and data origin

| Code | Owner source | Distributor access |
| --- | --- | --- |
| `DISTRIBUTORS_BY_BRANCH_COORDINATOR` | M02/M05 | No |
| `CREDIT_LINE_SUMMARY` | M07 | Own |
| `VOUCHERS_BY_STATUS` | M08/M09 | Own |
| `RELATIONS_BY_CUT` | M10 | Own |
| `RELATION_BALANCES` | M10/M11/M12 | Own |
| `DISTRIBUTOR_PAYMENT_CLASSIFICATION` | M10/M11 | Own |
| `DELINQUENT_DISTRIBUTORS` | M14/M10 | No |
| `CLIENT_INFORMATIONAL_PORTFOLIO` | M06 | Own |
| `RISK_ALERT_CONSECUTIVE_RELATIONS` | M14 | No |
| `RECONCILED_PAYMENTS` | M11 | Own |
| `UNRECONCILED_PAYMENTS` | M11 | No |
| `MANUAL_RECONCILIATIONS` | M11 | No |
| `PENDING_EXCESSES` | M12 | Own |
| `CREDIT_BALANCES_AND_APPLICATIONS` | M12 | Own |
| `REFUNDS_BY_STATUS` | M12 | Own |
| `DISTRIBUTOR_POINTS_BALANCE` | M13 | Own |
| `POINT_MOVEMENTS` | M13 | Own |
| `DISTRIBUTOR_APPLICATIONS_BY_RESULT` | M05 | No |
| `CREDIT_INCREASES_BY_STATUS` | M07 | No |
| `CLIENT_TRANSFERS_AND_REASSIGNMENTS` | M15 | Own |
| `BRANCH_AND_COORDINATOR_CHANGES` | M15 | No |

The exact filters, public columns, sortable fields, groupings and totals of every contract are declared in `Domain/Definitions/OfficialReportDefinitions.php` and returned by the definition endpoint. No physical table or SQL expression is accepted.

## Filters, time and pagination

Unsupported filters are rejected. UUID identifiers are validated, multiple values are deduplicated and sorted, and text filters are bounded. Business dates are interpreted as whole days in `America/Monterrey`: `date_from` is inclusive at local midnight and `date_to` is normalized to the exclusive midnight of the following day. Both boundaries are sent to owner adapters in UTC.

The default page size is 25 and the configured maximum is 100. Ordering uses an allowlist plus an owner-adapter stable identifier tie-breaker. Summaries must be calculated by the owner adapter using exactly the same frozen scope, filters and `as_of` as rows; they are never calculated from only the returned page.

## Owner-module integration contract

`ReportReadModelGateway` is the only business-data boundary. A productive adapter must:

- apply the frozen M16 scope before user filters;
- validate entity existence and assignment within that scope;
- select only authoritative persisted columns;
- preserve historical snapshots;
- use exact decimal operations;
- avoid N+1, `SELECT *`, duplicate joins and full in-memory collections;
- return rows and totals from the same logical `as_of`;
- stream deterministic blocks for asynchronous runs.

The default adapter is intentionally unavailable and returns `REPORT_DEPENDENCY_UNAVAILABLE` (`503`). This is the safe integration state until M02–M15 publish their definitive read contracts; M16 does not guess tables, classifications, balances or historical rules.

## Runs, audit and outbox

Run states are `QUEUED`, `RUNNING`, `COMPLETED`, `FAILED` and `EXPIRED`. The database enforces valid state/date combinations. Actor plus idempotency key is unique: replaying the same normalized payload returns the existing run, while changing it returns `IDEMPOTENCY_KEY_REUSED`.

Temporary result blocks use Laravel's encrypted cast and a SHA-256 integrity hash. Expiration removes only result blocks, never run metadata, query audit records or outbox events. Query audit stores hashes and counts, not row payloads, CURP, RFC, bank accounts, tokens or SQL.

Outbox event names are `ReportRunRequested`, `ReportRunStarted`, `ReportRunCompleted`, `ReportRunFailed`, `ReportRunExpired`, `SensitiveReportAccessed` and `ReportAccessDenied`. M17 and M18 must bind their own publishers/consumers; M16 does not send notifications or implement consolidated audit storage.

## Technical limits and pending definitions

Limits are centralized in `config/reporting.php`; they are technical safeguards rather than permanent business rules. Result retention is `null` because the source does not define a period. General exports, export formats, subscriptions, scheduled delivery, materialized views, read replicas, cross-user run visibility, coordinator historical access, synchronous/asynchronous date budgets and performance budgets remain intentionally unimplemented pending authority.

The OpenAPI contract is maintained in `docs/openapi.yaml`.
