# AuditHub API Reference — v1

Production-level endpoint reference: route, purpose, required headers, request/response bodies, and error cases for every endpoint.

Base URLs:
- Ingestion/Data API (SDK, customer backends): `https://api.audithub.dev/v1`
- Dashboard/Admin API (your own frontend): `https://app.audithub.dev/api/v1`

---

## Global Headers

### Required on every request
| Header | Value | Notes |
|---|---|---|
| `Content-Type` | `application/json` | Required on all `POST`/`PATCH`/`PUT` bodies |
| `Accept` | `application/json` | Ensures error responses are JSON, not an HTML error page |

### Required on Ingestion API requests (API-key auth)
| Header | Value | Notes |
|---|---|---|
| `Authorization` | `Bearer {key}.{secret}` | e.g. `Bearer ah_live_schoolerp_001.sk_live_9f8a...` |
| `Idempotency-Key` | UUID v4 | Required on all write requests (`POST /events`, `POST /events/batch`, `POST /exports`). Server rejects with `400 missing_idempotency_key` if absent on these routes. |

### Required on Dashboard API requests (session/token auth)
| Header | Value | Notes |
|---|---|---|
| `Authorization` | `Bearer {personal_access_token}` | OR a valid Sanctum session cookie, for browser-based calls |
| `X-Requested-With` | `XMLHttpRequest` | Required for CSRF protection on cookie-based sessions (Laravel default) |
| `X-XSRF-TOKEN` | CSRF token value | Required for cookie-based sessions on state-changing requests |

### Returned on every response
| Header | Value | Notes |
|---|---|---|
| `X-Request-Id` | e.g. `req_9f8a7b6c5d4e` | Echo this back in support tickets/bug reports |
| `X-RateLimit-Limit` | e.g. `600` | Requests allowed in the current window |
| `X-RateLimit-Remaining` | e.g. `582` | Requests left in the current window |
| `X-RateLimit-Reset` | Unix timestamp | When the window resets |

---

## 1. Auth & Tenant

### `POST /v1/auth/register`
**Purpose:** Creates a new tenant (organization) plus its first user, who is granted the `owner` role. This is account signup — the entry point for every new customer.

**Headers:** `Content-Type: application/json` only — this route is unauthenticated by definition.

**Request:**
```json
{
  "organization_name": "Acme Software",
  "name": "Riya Sharma",
  "email": "riya@acme.test",
  "password": "min-12-chars-recommended"
}
```

**Response `201`:**
```json
{
  "data": {
    "tenant": { "id": 1, "uuid": "f3a1c2d0-...", "name": "Acme Software", "slug": "acme-software", "status": "trial" },
    "user": { "id": 1, "name": "Riya Sharma", "email": "riya@acme.test", "role": "owner" }
  }
}
```

**Errors:** `422` (email already registered / weak password), `429` (registration rate limit, prevents mass account creation).

---

### `POST /v1/auth/login`
**Purpose:** Authenticates a dashboard user and issues a session (cookie) or, for API-driven login, a Sanctum token.

**Headers:** `Content-Type: application/json`

**Request:**
```json
{ "email": "riya@acme.test", "password": "••••••••" }
```

**Response `200`:**
```json
{ "data": { "user": { "id": 1, "name": "Riya Sharma", "role": "owner" } } }
```
Sets `Set-Cookie` (httpOnly, secure, SameSite=Lax) for session-based flow.

**Errors:** `401 invalid_credentials` (deliberately identical message whether the email or password was wrong), `429 too_many_attempts` after repeated failures.

---

### `POST /v1/auth/logout`
**Purpose:** Invalidates the current session/token.

**Headers:** `Authorization` (session cookie or token)

**Response:** `204 No Content`

---

### `GET /v1/tenants/me`
**Purpose:** Returns the authenticated user's tenant (organization) profile — name, plan, retention settings, timezone.

**Headers:** `Authorization` (dashboard session/token)

**Response `200`:**
```json
{ "data": { "id": 1, "name": "Acme Software", "status": "active", "timezone": "Asia/Kolkata", "retention_days": 365 } }
```

---

### `PATCH /v1/tenants/me`
**Purpose:** Updates tenant-level settings. Restricted to `owner`/`admin` roles.

**Headers:** `Authorization` (dashboard session/token)

**Request:**
```json
{ "timezone": "Asia/Kolkata", "retention_days": 730 }
```

**Response:** `200` with updated tenant object.

**Errors:** `403 forbidden` if caller's role isn't `owner` or `admin`.

---

## 2. Applications

### `GET /v1/applications`
**Purpose:** Lists every application registered under the caller's tenant. Used to populate the "which app do you want to configure" screen.

**Headers:** `Authorization` (dashboard session/token)

**Response `200`:**
```json
{ "data": [ { "uuid": "aa000000-...-001", "name": "School ERP", "status": "active" } ], "meta": { "total": 3 } }
```

---

### `POST /v1/applications`
**Purpose:** Registers a new application under the tenant — the logical container that API keys and audit events belong to (e.g. "School ERP", "CRM").

**Headers:** `Authorization` (dashboard session/token)

**Request:**
```json
{ "name": "School ERP", "description": "Student & staff management system" }
```

**Response `201`:**
```json
{ "data": { "id": 1, "uuid": "aa000000-0000-4000-8000-000000000001", "name": "School ERP", "status": "active", "created_at": "2026-07-12T09:00:00Z" } }
```

**Errors:** `422` if `name` missing/duplicate within tenant, `403` if role is `viewer`.

---

### `GET /v1/applications/{uuid}`
**Purpose:** Fetches a single application's details, for the app-detail/settings screen.

**Headers:** `Authorization` (dashboard session/token)

**Errors:** `404` if the UUID doesn't exist *or* belongs to a different tenant (identical response either way — see Security §7.1 in the design doc).

---

### `PATCH /v1/applications/{uuid}`
**Purpose:** Updates an application's name, description, or status (e.g. archiving it).

**Headers:** `Authorization` (dashboard session/token)

**Request:**
```json
{ "status": "archived" }
```

**Errors:** `403` if role is `viewer`, `422` on invalid status value.

---

### `DELETE /v1/applications/{uuid}`
**Purpose:** Soft-archives an application. Does not delete historical audit events — those are append-only and must survive application archival for compliance reasons.

**Headers:** `Authorization` (dashboard session/token, `owner`/`admin` only)

**Response:** `204 No Content`

---

## 3. API Keys

### `GET /v1/applications/{uuid}/api-keys`
**Purpose:** Lists all keys issued for an application. Never returns secrets — only the public `key`, label, status, and last-used timestamp, so this is safe to render in the dashboard.

**Headers:** `Authorization` (dashboard session/token)

**Response `200`:**
```json
{ "data": [ { "key": "ah_live_schoolerp_001", "label": "Production", "status": "active", "last_used_at": "2026-07-11T09:12:00Z" } ] }
```

---

### `POST /v1/applications/{uuid}/api-keys`
**Purpose:** Generates a new key/secret pair for an application. This is the single most sensitive dashboard action in the product — it mints the credential customers put into production code.

**Headers:** `Authorization` (dashboard session/token, `owner`/`admin` only)

**Request:**
```json
{ "label": "Production" }
```

**Response `201`:**
```json
{ "data": { "key": "ah_live_schoolerp_001", "secret": "sk_live_9f8a7b6c5d4e3f2a1b0c...", "label": "Production", "status": "active" } }
```
`secret` is returned exactly once. There is no endpoint that can ever retrieve it again — only rotate (issue new) or revoke.

**Errors:** `403` if role isn't `owner`/`admin`, `429` if key-creation is rate-limited (prevents runaway key generation).

---

### `POST /v1/api-keys/{key}/revoke`
**Purpose:** Immediately disables a key. All future requests using it are rejected; already-queued events already accepted before revocation are unaffected.

**Headers:** `Authorization` (dashboard session/token, `owner`/`admin` only)

**Response `200`:**
```json
{ "data": { "key": "ah_live_schoolerp_001", "status": "revoked" } }
```

---

### `DELETE /v1/api-keys/{key}`
**Purpose:** Hard-deletes a key record that is already revoked (cleanup). Only allowed on keys with `status: revoked`.

**Headers:** `Authorization` (dashboard session/token, `owner` only)

**Errors:** `409 conflict` if the key is still `active` — must be revoked first.

---

## 4. Event Ingestion

### `POST /v1/events`
**Purpose:** The core endpoint of the product. Accepts a single audit event from a customer's backend/SDK, queues it, and appends it to that application's tamper-evident hash chain.

**Headers:**
| Header | Required | Notes |
|---|---|---|
| `Authorization` | Yes | `Bearer {key}.{secret}` |
| `Idempotency-Key` | Yes | UUID v4; safe retry on network failure without duplicating the event |
| `Content-Type` | Yes | `application/json` |

**Request:**
```json
{
  "event_time": "2026-07-12T09:15:22.123Z",
  "actor": { "type": "user", "id": "2", "name": "Karan Mehta" },
  "action": "invoice.updated",
  "resource": { "type": "Invoice", "id": "INV-2201", "name": "Term 2 Fees" },
  "context": { "ip_address": "203.0.113.10", "user_agent": "Mozilla/5.0", "correlation_id": "corr-1002", "trace_id": "trace-a2" },
  "metadata": { "old_status": "Pending", "new_status": "Paid", "amount": 15000 }
}
```

**Response `202 Accepted`** (async — queued for hashing/insert, not yet durably chained at response time):
```json
{ "data": { "uuid": "e1000000-0000-4000-8000-000000000002", "status": "queued", "received_at": "2026-07-12T09:15:23.000Z" } }
```

**Errors:**
- `401 invalid_api_key` — key missing, malformed, or revoked
- `400 missing_idempotency_key`
- `409 idempotency_conflict` — same key reused with a different payload
- `422 validation_error` — missing `action`, `metadata` exceeds size cap (32KB), etc.
- `429 rate_limited` — includes `Retry-After` header

---

### `POST /v1/events/batch`
**Purpose:** Same as above but accepts up to 100 events in one call — used by the SDK's internal buffering to reduce request volume under high-throughput customer workloads.

**Headers:** Same as `POST /v1/events`, plus `Idempotency-Key` applies to the whole batch (retrying the batch is safe; individual events within it are deduplicated by their own `event_time` + `action` + `resource` signature).

**Request:**
```json
{ "events": [ { "...": "same shape as POST /v1/events" } ] }
```

**Response `207 Multi-Status`:**
```json
{ "data": { "accepted": 18, "rejected": 2, "errors": [ { "index": 4, "code": "validation_error", "message": "action is required" } ] } }
```

---

## 5. Event Search

### `GET /v1/events`
**Purpose:** Powers the Event Explorer search screen — filter, page through, and locate specific audit events across an application.

**Headers:** `Authorization` (dashboard session/token — this is a human-facing search, not machine ingestion)

**Query params:** `application_id`, `actor_id`, `action`, `resource_type`, `correlation_id`, `date_from`, `date_to`, `q`, `per_page` (max 100), `cursor`

**Response `200`:**
```json
{
  "data": [ { "uuid": "e1000000-...-002", "event_time": "2026-07-10T09:15:22Z", "actor": { "type": "user", "id": "2", "name": "Karan Mehta" }, "action": "invoice.updated" } ],
  "meta": { "per_page": 25, "total": 7, "next_cursor": null }
}
```
Cursor-based, not offset-based — safe under concurrent writes to an append-only table.

---

### `GET /v1/events/{uuid}`
**Purpose:** Full detail view for one event, including the raw `metadata` JSON and the `previous_hash`/`current_hash` pair — used by the event-detail page and by anyone manually verifying chain integrity.

**Headers:** `Authorization` (dashboard session/token)

**Response `200`:** full event object including `metadata`, `previous_hash`, `current_hash`, `api_key_id`.

**Errors:** `404` if not found or not owned by the caller's tenant.

---

## 6. Exports

### `POST /v1/exports`
**Purpose:** Queues an async job that generates a CSV of filtered events for download — used for compliance handoffs (e.g. "give our auditor everything from Q2").

**Headers:** `Authorization` (dashboard session/token), `Idempotency-Key` (recommended — avoids duplicate export jobs on double-click/retry)

**Request:**
```json
{ "filters": { "application_id": 1, "date_from": "2026-07-01", "date_to": "2026-07-10" } }
```

**Response `202`:**
```json
{ "data": { "id": 2, "status": "pending", "created_at": "2026-07-12T09:30:00Z" } }
```

---

### `GET /v1/exports`
**Purpose:** Lists past export jobs and their status, for the "Exports" history screen.

**Headers:** `Authorization` (dashboard session/token)

---

### `GET /v1/exports/{id}`
**Purpose:** Polls the status of a single export job (`pending` → `processing` → `completed`/`failed`).

**Headers:** `Authorization` (dashboard session/token)

---

### `GET /v1/exports/{id}/download`
**Purpose:** Issues a short-lived signed URL to download the completed CSV. Not a permanent public link — the file may contain sensitive customer metadata.

**Headers:** `Authorization` (dashboard session/token)

**Response `200`:**
```json
{ "data": { "url": "https://storage.audithub.dev/exports/...?sig=...&expires=1752345600", "expires_at": "2026-07-12T09:45:00Z" } }
```

**Errors:** `409 export_not_ready` if status isn't `completed`.

---

## 7. Integrity Checks

### `GET /v1/integrity-checks`
**Purpose:** Lists past hash-chain verification runs (nightly cron + any on-demand runs), for the "Integrity" dashboard tab — this is the trust signal the whole product is built around.

**Headers:** `Authorization` (dashboard session/token)

---

### `POST /v1/integrity-checks/run`
**Purpose:** Triggers an on-demand integrity check outside the nightly schedule — e.g. right before a customer downloads an export for an auditor, so they can show a fresh "verified" status.

**Headers:** `Authorization` (dashboard session/token, `owner`/`admin` only — this is a real compute cost, don't let `viewer` trigger it freely)

**Response `202`:**
```json
{ "data": { "id": 4, "status": "running", "started_at": "2026-07-12T09:50:00Z" } }
```

---

### `GET /v1/integrity-checks/{id}`
**Purpose:** Returns the result of a specific check, including which event (if any) broke the chain — critical for incident response.

**Headers:** `Authorization` (dashboard session/token)

**Response `200`:**
```json
{ "data": { "id": 1, "status": "passed", "events_checked": 7, "failed_event_id": null, "message": "All hash chains verified successfully.", "finished_at": "2026-07-11T02:00:04Z" } }
```

---

## Production-Readiness Checklist

Before any of the above goes live, confirm each of these is actually true — not just planned:

- [ ] Every ingestion route rejects requests without `Authorization` and `Idempotency-Key` with a clear `4xx`, not a `500`.
- [ ] Every dashboard route is scoped to the caller's `tenant_id` at the query level (not just checked in a controller `if`), and cross-tenant access returns `404`, not `403` or `200`.
- [ ] `secret` is never present in any response after key creation, never logged, never in Sentry payloads.
- [ ] Rate limits are enforced per-API-key (ingestion) and per-IP (auth), with `Retry-After` on every `429`.
- [ ] `X-Request-Id` is generated per-request and present on every response, including errors.
- [ ] All write endpoints (`POST`/`PATCH`/`DELETE`) are covered by role checks — a `viewer` gets `403` everywhere except reads.
- [ ] TLS enforced end-to-end; HTTP requests redirect, never serve.
- [ ] `audit_events` table has no `UPDATE`/`DELETE` grant at the database user level, independent of application code.
- [ ] Nightly integrity check failures trigger an alert (email/Slack/webhook), not just a silent log line.
- [ ] Load-tested `POST /v1/events` at expected peak throughput before the SDK is publicly published — this is the endpoint strangers will hit first.