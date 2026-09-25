# URL Shortener Improvement Strategy

This document is a guided roadmap for improving the URL shortener beyond a toy project.

The goal is to implement each phase yourself. Use the code hints as direction, not as copy-paste solutions.

## Working rules

- Complete one phase at a time.
- Add or update tests before calling a phase complete.
- Keep public redirects separate from private management APIs.
- Prefer small migrations and focused commits.
- Do not expose raw Eloquent models as API responses.
- Run the relevant tests after every meaningful change.

## Current baseline

Already implemented:

- Random six-character short codes
- URL validation
- URL expiration
- Public redirects
- Aggregate click counts
- Detailed click records
- IP address and user-agent tracking
- Create, list, show, update, delete, and stats endpoints
- Feature tests for the main URL behavior

The next major improvement is ownership and authentication.

---

## Phase 1: Add URL ownership

### Goal

Associate each URL with a user so management operations can later be restricted to the owner.

Do not add login yet. First make the database and model relationships correct.

### Files to inspect

- `app/Models/User.php`
- `app/Models/Url.php`
- `app/Http/Controllers/UrlController.php`
- `database/migrations/0001_01_01_000000_create_users_table.php`
- `database/factories/UserFactory.php`
- `tests/Feature/UrlApiTest.php`

### Tasks

1. Create a migration that adds `user_id` to `urls`.
2. Decide whether the column is nullable during migration.
3. Add the foreign-key behavior for deleted users.
4. Add a `Url` → `User` relationship.
5. Add a `User` → `Url` relationship.
6. Add `user_id` to the URL model's assignable attributes only if your project convention requires it.
7. Prepare URL creation to receive an owner later.

### Relationship shape hint

The relationship should express:

```text
User hasMany Url
Url belongsTo User
```

The database relationship should express:

```text
urls.user_id → users.id
```

### Important design decision

Existing URLs were created before ownership existed. Choose one policy:

- keep old rows ownerless temporarily;
- assign them to a system user;
- write a data migration that assigns them to a chosen user.

For this project, nullable ownership during migration is acceptable, but newly created URLs should eventually always have an owner.

### Tests to add

- a URL can belong to a user;
- a user can retrieve its URLs through the relationship;
- deleting a user follows the chosen foreign-key policy;
- old ownerless URLs behave according to your chosen policy.

### Verification

```bash
php artisan make:migration add_user_id_to_urls_table
php artisan migrate:status
php artisan migrate
php artisan test
```

Do not continue until the relationship and migration tests pass.

---

## Phase 2: Add authentication

### Goal

Allow users to register, log in, and revoke API tokens.

Laravel Sanctum is already present in the project.

### Suggested endpoints

```text
POST /api/auth/register
POST /api/auth/login
POST /api/auth/logout
```

### Tasks

1. Confirm `User` uses Sanctum's token trait.
2. Create request classes for registration and login.
3. Validate name, email, password, and password confirmation.
4. Hash passwords through Laravel's normal password handling.
5. Return a token only after successful registration/login.
6. Never include password or token hashes in JSON responses.
7. Revoke the current token during logout.

### Code-shape hints

Registration flow:

```text
validated input
→ create User
→ create Sanctum token
→ return safe user fields + plain token
```

Login flow:

```text
validated credentials
→ find user by email
→ compare password with Hash::check
→ reject with 401 if invalid
→ create token
```

Logout flow:

```text
authenticated request
→ get current access token
→ delete current token
```

### Tests to add

- registration succeeds;
- duplicate email is rejected;
- weak password is rejected;
- login succeeds with correct credentials;
- invalid credentials return `401`;
- logout revokes the current token;
- password fields are absent from responses.

---

## Phase 3: Protect management endpoints

### Goal

Only authenticated users can manage their own URLs.

### Public endpoint

Keep this public:

```text
GET /{code}
```

Anyone with a valid short link should be able to redirect.

### Protected endpoints

Protect these with Sanctum:

```text
POST /api/urls
GET /api/urls
GET /api/urls/{code}
PUT /api/urls/{code}
DELETE /api/urls/{code}
GET /api/urls/{code}/stats
```

### Ownership query hint

For management actions, avoid this unrestricted pattern:

```text
Url::where('code', $code)->first()
```

Prefer the authenticated user's relationship:

```text
request user
→ urls relationship
→ filter by code
→ first
```

This prevents one user from reading or modifying another user's URL.

### Security behavior

If a code belongs to another user, return `404` rather than revealing that it exists.

### Tests to add

- unauthenticated management requests return `401`;
- authenticated users can create URLs;
- a user sees only their own URLs;
- User A cannot show User B's URL;
- User A cannot update User B's URL;
- User A cannot delete User B's URL;
- User A cannot view User B's statistics;
- public redirects work without authentication.

---

## Phase 4: Add pagination

### Goal

Avoid loading every URL into memory.

### Tasks

1. Choose a default page size, such as 15.
2. Choose a maximum page size.
3. Decide whether clients can request `?per_page=`.
4. Validate or clamp the requested page size.
5. Choose a stable sort order, usually newest first.
6. Return pagination metadata.

### Code-shape hint

Replace the conceptual operation:

```text
query → get()
```

with:

```text
query → latest() → paginate(perPage)
```

### Tests to add

- response includes pagination metadata;
- default page size works;
- requested page size works;
- page two contains different records;
- users cannot paginate into another user's records.

---

## Phase 5: Standardize API responses

### Goal

Make every endpoint return predictable JSON.

### Recommended shape

For one resource:

```json
{
  "data": {
    "code": "abc123",
    "long_url": "https://example.com",
    "expires_at": null,
    "click_count": 0
  }
}
```

For collections:

```json
{
  "data": [],
  "meta": {},
  "links": {}
}
```

### Tasks

1. Decide which fields are public.
2. Create an API Resource for a URL.
3. Reuse it for create, show, update, and list responses.
4. Keep statistics as a separate response because it contains analytics.
5. Standardize error messages where practical.

### Avoid

- returning full models directly;
- exposing database IDs without a reason;
- exposing password, token, or internal columns;
- using different field names for the same concept.

---

## Phase 6: Add abuse protection

### Goal

Prevent the service from being easily abused.

### Rate-limit candidates

- registration;
- login;
- URL creation;
- redirect traffic if necessary.

### Validation improvements

- restrict schemes to `http` and `https`;
- set a maximum URL length;
- reject malformed input;
- do not fetch submitted URLs from the server merely to check whether they are alive.

### Security warning

Server-side URL fetching can create SSRF vulnerabilities. A URL shortener normally stores the URL and redirects to it; it does not need to request the destination during creation.

---

## Phase 7: Improve click analytics

### Possible fields

- referrer;
- accepted language;
- device category;
- browser;
- operating system;
- timestamp.

### Privacy considerations

- IP addresses are sensitive data.
- Collect only what the project needs.
- Document retention expectations.
- Consider anonymizing or hashing IP addresses.

### Data consistency

For every successful redirect:

```text
create detailed click row
→ increment aggregate click_count
→ redirect
```

For missing or expired URLs:

```text
do not create click row
do not increment click_count
return 404
```

---

## Phase 8: Add caching

### Goal

Reduce database lookups on redirects.

### Cache key hint

Use a namespaced key based on the short code:

```text
url:{code}
```

### Invalidation events

Invalidate or refresh the cache when:

- a URL is created;
- a URL is updated;
- a URL is deleted;
- a URL expires.

### Important expiration rule

The cache lifetime must not outlive the URL's remaining expiration time. An expired URL must never continue redirecting because of stale cache data.

---

## Phase 9: Operational quality

Consider adding:

- health-check verification;
- structured logs;
- indexes for common queries;
- consistent exception responses;
- queueing for heavy analytics work;
- deployment documentation;
- environment-specific configuration;
- monitoring and alerting.

## Completion standard

The project is meaningfully beyond a toy when:

- ownership is enforced;
- users are authenticated;
- users cannot access one another's management data;
- public redirects remain simple and reliable;
- list endpoints paginate;
- responses do not leak model internals;
- abuse controls exist;
- analytics behavior is tested;
- caching handles expiration and invalidation correctly;
- the README documents the actual API.

## Recommended implementation order

1. URL ownership.
2. Authentication.
3. Ownership authorization.
4. Pagination.
5. API Resources.
6. Rate limiting and stronger validation.
7. Analytics improvements.
8. Caching.
9. Operational improvements.

After each phase:

```bash
php artisan migrate:status
php artisan route:list
php artisan test
vendor/bin/pint --test
```
