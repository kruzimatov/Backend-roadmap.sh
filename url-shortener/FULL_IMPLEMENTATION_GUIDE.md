# URL Shortener: Full Next-Phase Implementation Guide

This guide contains the code and steps for improving the current URL shortener before adding caching.

Implement one phase at a time. Do not paste the entire document into the project at once. After each phase:

```bash
php artisan migrate:status
php artisan route:list
php artisan test
vendor/bin/pint --test
```

The current public redirect must remain:

```text
GET /{code}
```

The management API will become authenticated and user-owned.

---

# Phase 1: Add URL ownership

## 1. Create the migration

From `url-shortener`:

```bash
php artisan make:migration add_user_id_to_urls_table --table=urls
```

Open the generated migration and use this structure:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
```

Why nullable?

- Existing URL rows were created before users owned URLs.
- A non-nullable column would make the migration fail unless old rows were assigned first.
- New URLs will receive an owner after authentication is added.

Run:

```bash
php artisan migrate
```

## 2. Add model relationships

In `app/Models/Url.php`:

```php
public function user()
{
    return $this->belongsTo(User::class);
}
```

Add `user_id` to the model's fillable fields if you use mass assignment:

```php
protected $fillable = [
    'user_id',
    'long_url',
    'code',
    'click_count',
    'expires_at',
];
```

In `app/Models/User.php`:

```php
public function urls()
{
    return $this->hasMany(Url::class);
}
```

Because both models are in `App\Models`, the relationship may resolve without imports. Explicit imports are clearer:

```php
use App\Models\Url;
```

## 3. Test the relationship

Create or extend a model test:

```php
<?php

namespace Tests\Unit;

use App\Models\Url;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UrlOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_own_urls(): void
    {
        $user = User::factory()->create();

        $url = Url::create([
            'user_id' => $user->id,
            'long_url' => 'https://example.com',
            'code' => 'owned1',
        ]);

        $this->assertTrue($url->user->is($user));
        $this->assertTrue($user->urls->contains($url));
    }
}
```

---

# Phase 2: Add Sanctum authentication

Sanctum is already included in this project.

## 1. Update the User model

In `app/Models/User.php`, add:

```php
use Laravel\Sanctum\HasApiTokens;
```

Add the trait:

```php
use HasApiTokens, HasFactory, Notifiable;
```

Keep password and remember-token fields hidden. If the project uses PHP attributes for fillable/hidden fields, preserve that convention. A traditional equivalent is:

```php
protected $hidden = [
    'password',
    'remember_token',
];
```

## 2. Create authentication request classes

```bash
php artisan make:request RegisterRequest
php artisan make:request LoginRequest
```

`app/Http/Requests/RegisterRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
```

`app/Http/Requests/LoginRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
```

## 3. Create the authentication controller

```bash
php artisan make:controller AuthController
```

Use:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user->only(['id', 'name', 'email']),
            'token' => $token,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        return response()->json([
            'user' => $user->only(['id', 'name', 'email']),
            'token' => $user->createToken('api')->plainTextToken,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }
}
```

Important:

- `Hash::check()` compares the plain password with the hashed database password.
- Never return `$user` directly if it could expose fields.
- The plain token is shown only when it is created.
- Logout deletes the current token, not every token owned by the user.

## 4. Add authentication routes

In `routes/api.php`:

```php
use App\Http\Controllers\AuthController;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});
```

Test manually:

```bash
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test User",
    "email": "test@example.com",
    "password": "password123",
    "password_confirmation": "password123"
  }'
```

Copy the returned token:

```bash
curl -X POST http://localhost:8000/api/auth/logout \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

---

# Phase 3: Protect URL management

## 1. Decide route access

Public:

```text
GET /{code}
```

Authentication required:

```text
POST /api/urls
GET /api/urls
GET /api/urls/{code}
PUT /api/urls/{code}
DELETE /api/urls/{code}
GET /api/urls/{code}/stats
```

## 2. Group URL routes

In `routes/api.php`:

```php
use App\Http\Controllers\UrlController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/urls', [UrlController::class, 'index']);
    Route::post('/urls', [UrlController::class, 'store']);
    Route::get('/urls/{code}', [UrlController::class, 'show']);
    Route::put('/urls/{code}', [UrlController::class, 'update']);
    Route::delete('/urls/{code}', [UrlController::class, 'destroy']);
    Route::get('/urls/{code}/stats', [UrlController::class, 'stats']);
});
```

Keep the redirect route in `routes/web.php`:

```php
Route::get('/{code}', [UrlController::class, 'redirect']);
```

## 3. Associate created URLs with the user

In `UrlController::store()`:

```php
$url = Url::create([
    'user_id' => $request->user()->id,
    'long_url' => $request->long_url,
    'code' => $code,
    'expires_at' => $request->expires_at,
]);
```

The key idea is:

```text
authenticated request
→ request user
→ user's ID
→ new URL's user_id
```

## 4. Scope management queries to the owner

Do not use this for protected management actions:

```php
Url::where('code', $code)->first();
```

Use the authenticated user's relationship:

```php
$url = $request->user()
    ->urls()
    ->where('code', $code)
    ->first();
```

For methods without an injected request object:

```php
$url = request()->user()
    ->urls()
    ->where('code', $code)
    ->first();
```

Use this ownership-scoped query in:

- `show()`
- `update()`
- `destroy()`
- `stats()`

If the URL belongs to another user, return `404`. Do not reveal that it exists.

The public `redirect()` method must remain different:

```php
$url = Url::where('code', $code)
    ->where(function ($query) {
        $query->whereNull('expires_at')
            ->orWhere('expires_at', '>', now());
    })
    ->first();
```

It must not require authentication.

---

# Phase 4: Add pagination

## 1. Replace `get()` with `paginate()`

The current list query is conceptually:

```php
$urls = $query->get([...]);
```

Use:

```php
$perPage = min((int) request()->integer('per_page', 15), 50);

$urls = request()->user()
    ->urls()
    ->where(function ($query) {
        $query->whereNull('expires_at')
            ->orWhere('expires_at', '>', now());
    })
    ->latest()
    ->paginate($perPage, [
        'long_url',
        'code',
        'click_count',
        'expires_at',
    ]);
```

The maximum prevents a client from requesting an unreasonable page size.

## 2. Expected pagination response

Laravel returns metadata similar to:

```json
{
  "current_page": 1,
  "data": [],
  "last_page": 2,
  "per_page": 15,
  "total": 20
}
```

Do not manually rebuild pagination metadata unless you have a reason.

## 3. Pagination tests

Create more than one page of URLs and test:

```php
$this->getJson('/api/urls?per_page=2')
    ->assertOk()
    ->assertJsonPath('per_page', 2)
    ->assertJsonPath('current_page', 1);
```

Also verify:

- User A cannot see User B's URLs.
- `per_page=1000` is capped at the chosen maximum.
- page two contains different records.

---

# Phase 5: Add API Resources

## 1. Create a resource

```bash
php artisan make:resource UrlResource
```

`app/Http/Resources/UrlResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UrlResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'long_url' => $this->long_url,
            'expires_at' => $this->expires_at,
            'is_expired' => $this->expires_at?->isPast() ?? false,
            'click_count' => $this->click_count,
        ];
    }
}
```

## 2. Return the resource

For one URL:

```php
return new UrlResource($url);
```

For a collection:

```php
return UrlResource::collection($urls);
```

For a created response:

```php
return (new UrlResource($url))
    ->additional([
        'message' => 'URL created successfully',
    ])
    ->response()
    ->setStatusCode(201);
```

Use a separate resource or explicit structure for statistics because statistics include click history.

---

# Phase 6: Add stronger validation

## 1. Restrict URL schemes

In `StoreUrlRequest` and `UpdateUrlRequest`, use:

```php
'long_url' => [
    'required',
    'url:http,https',
    'max:2048',
],
```

This rejects schemes such as `javascript:` and limits unreasonable input.

## 2. Keep expiration rules

```php
'expires_at' => [
    'nullable',
    'date',
    'after:now',
],
```

The project policy remains:

```text
missing or null expiration → never expires
future expiration → expires at that time
past expiration → validation error
```

---

# Phase 7: Add rate limiting

## 1. Protect expensive or sensitive routes

Start with:

- registration;
- login;
- URL creation.

Laravel has built-in rate-limiting support. You can begin with route-level middleware:

```php
Route::middleware('throttle:login')->post(
    '/auth/login',
    [AuthController::class, 'login']
);
```

For a custom limiter, define it in a service provider:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->by(
        $request->ip().'|'.$request->input('email')
    );
});
```

The exact provider location depends on the Laravel version and project bootstrap configuration. Inspect the existing service provider setup before adding it.

## 2. Test the limit

Send enough requests to exceed the limit and expect:

```text
429 Too Many Requests
```

Do not rate-limit public redirects aggressively until you understand the effect on legitimate shared links.

---

# Phase 8: Add tests for the production boundary

Create a separate test class if `UrlApiTest` becomes too large:

```bash
php artisan make:test AuthenticationTest
php artisan make:test UrlOwnershipTest
```

Important tests:

```php
public function test_management_routes_require_authentication(): void
{
    $this->getJson('/api/urls')->assertUnauthorized();
    $this->postJson('/api/urls', [
        'long_url' => 'https://example.com',
    ])->assertUnauthorized();
}
```

```php
public function test_user_cannot_access_another_users_url(): void
{
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $url = Url::create([
        'user_id' => $owner->id,
        'long_url' => 'https://example.com',
        'code' => 'private',
    ]);

    $this->actingAs($otherUser)
        ->getJson('/api/urls/'.$url->code)
        ->assertNotFound();
}
```

```php
public function test_public_redirect_does_not_require_authentication(): void
{
    Url::create([
        'long_url' => 'https://example.com',
        'code' => 'public1',
    ]);

    $this->get('/public1')
        ->assertRedirect('https://example.com');
}
```

For token authentication, use:

```php
$token = $user->createToken('test')->plainTextToken;

$this->withToken($token)
    ->getJson('/api/urls')
    ->assertOk();
```

---

# Phase 9: Commit in small steps

Use separate commits:

```bash
git add url-shortener/database/migrations url-shortener/app/Models
git commit -m "Add URL ownership"

git add url-shortener/app/Http/Requests url-shortener/app/Http/Controllers url-shortener/routes
git commit -m "Add API authentication"

git add url-shortener/tests
git commit -m "Test URL ownership and authentication"

git add url-shortener/README.md url-shortener/CHECKPOINT.md
git commit -m "Document authenticated URL API"
```

Review before committing:

```bash
git diff --check
git diff
git status --short
```

Do not commit:

- `.env`;
- access tokens;
- passwords;
- database credentials;
- generated cache files.

---

# Before caching

Do not add Redis until these conditions are true:

- ownership tests pass;
- authentication tests pass;
- another user cannot access private management data;
- pagination works;
- response shapes are stable;
- rate-limit behavior is understood;
- the complete test suite passes.

Then the caching flow can be learned separately:

```text
redirect request
→ cache lookup by short code
→ verify expiration
→ database fallback on cache miss
→ cache valid mapping
→ record analytics
→ redirect
```

Caching is not just storing data. The important learning problems are:

- invalidation after update;
- invalidation after delete;
- expiration-aware TTL;
- behavior when Redis is unavailable;
- avoiding stale redirects.
