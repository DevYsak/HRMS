# Fortify Login Authentication Flow - Complete Analysis

## 1. Entry Point: Route Definition

**File:** `vendor/laravel/fortify/routes/routes.php`

```php
Route::post(RoutePath::for('login', '/login'), [AuthenticatedSessionController::class, 'store'])
    ->middleware(array_filter([
        'guest:'.config('fortify.guard'),
        $limiter ? 'throttle:'.$limiter : null,
    ]))->name('login.store');
```

**Middleware Applied (in order):**
1. **VerifyCsrfToken** (automatic, via 'web' middleware group, applied at parent Route::group)
2. **guest:{guard}** - Ensures user is not already authenticated
3. **throttle:{limiter}** - Rate limiting (if configured in `config('fortify.limiters.login')`)

---

## 2. CSRF Token Validation

**How it works:**
- CSRF validation is **automatic** because the login route is within a `Route::group(['middleware' => config('fortify.middleware', ['web'])])` wrapper
- The 'web' middleware group includes `VerifyCsrfToken` middleware
- Laravel's VerifyCsrfToken middleware:
  - Checks for `_token` in POST data OR `X-CSRF-TOKEN` header
  - Regenerates token after successful authentication (via `PrepareAuthenticatedSession` action)
  - Throws `TokenMismatchException` if token is invalid/missing

**Early Return Points:**
- ❌ **CSRF validation fails** → Returns 419 error (Session Expired) and halts execution
- ❌ **Rate limiting (throttle) exceeded** → Returns 429 Too Many Requests and halts execution
- ❌ **User already authenticated (guest check)** → Redirects away and halts execution

---

## 3. Request Validation

**File:** `vendor/laravel/fortify/src/Http/Requests/LoginRequest.php`

```php
class LoginRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            Fortify::username() => 'required|string',
            'password' => 'required|string',
        ];
    }
}
```

**Validation Rules:**
- Username field (default: 'email') → required, string
- Password → required, string

**Early Return:**
- ❌ **Validation fails** → Returns 422 Unprocessable Entity with error messages

---

## 4. Authentication Pipeline

**File:** `vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php`

### store() Method

```php
public function store(LoginRequest $request)
{
    return $this->loginPipeline($request)->then(function ($request) {
        return app(LoginResponse::class);
    });
}
```

The method:
1. Gets validated `LoginRequest` (CSRF, validation rules already checked)
2. Passes it through `loginPipeline()`
3. If all pipeline steps succeed, returns `LoginResponse` instance

### loginPipeline() Method

```php
protected function loginPipeline(LoginRequest $request)
{
    if (Fortify::$authenticateThroughCallback) {
        return (new Pipeline(app()))->send($request)->through(array_filter(
            call_user_func(Fortify::$authenticateThroughCallback, $request)
        ));
    }

    if (is_array(config('fortify.pipelines.login'))) {
        return (new Pipeline(app()))->send($request)->through(array_filter(
            config('fortify.pipelines.login')
        ));
    }

    return (new Pipeline(app()))->send($request)->through(array_filter([
        config('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class,
        config('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
        Features::enabled(Features::twoFactorAuthentication()) ? RedirectIfTwoFactorAuthenticatable::class : null,
        AttemptToAuthenticate::class,
        PrepareAuthenticatedSession::class,
    ]));
}
```

**Pipeline Priority (one of three paths):**
1. Custom callback: `Fortify::$authenticateThroughCallback` (if configured)
2. Config array: `config('fortify.pipelines.login')` (if configured)
3. **Default pipeline** (most common):
   - `EnsureLoginIsNotThrottled` (if no rate limiter configured)
   - `CanonicalizeUsername` (if lowercase usernames enabled)
   - `RedirectIfTwoFactorAuthenticatable` (if 2FA enabled)
   - `AttemptToAuthenticate`
   - `PrepareAuthenticatedSession`

---

## 5. Pipeline Step 1: Rate Limiting Check

**File:** `vendor/laravel/fortify/src/Actions/EnsureLoginIsNotThrottled.php`

```php
public function handle($request, $next)
{
    if (! $this->limiter->tooManyAttempts($request)) {
        return $next($request);
    }

    event(new Lockout($request));

    return app(LockoutResponse::class);
}
```

**Rate Limiter Details:**
- Uses `LoginRateLimiter` class that tracks attempts by **username + IP combination**
- Configurable via `config('fortify.limiters.login')` in config/fortify.php
- **Early Return:**
  - ❌ **Too many attempts** → Returns `LockoutResponse` (429 Too Many Requests) and halts pipeline exclusive to this check.

**Note:** This `EnsureLoginIsNotThrottled` action only runs if `config('fortify.limiters.login')` is **NOT** configured. If the limiter is configured, rate limiting is handled by the `throttle` middleware (applied to the route) instead.

---

## 6. Pipeline Step 2: Username Canonicalization (Optional)

**File:** `vendor/laravel/fortify/src/Actions/CanonicalizeUsername.php`

Only runs if `config('fortify.lowercase_usernames')` is true.

---

## 7. Pipeline Step 3: Two-Factor Authentication Check (If Enabled)

**File:** `vendor/laravel/fortify/src/Actions/RedirectIfTwoFactorAuthenticatable.php`

```php
public function handle($request, $next)
{
    $user = $this->validateCredentials($request);

    if (Fortify::confirmsTwoFactorAuthentication()) {
        if (optional($user)->two_factor_secret &&
            ! is_null(optional($user)->two_factor_confirmed_at) &&
            in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user))) {
            return $this->twoFactorChallengeResponse($request, $user);
        } else {
            return $next($request);
        }
    }

    if (optional($user)->two_factor_secret &&
        in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user))) {
        return $this->twoFactorChallengeResponse($request, $user);
    }

    return $next($request);
}
```

**Validation Process:**
1. Calls `validateCredentials()` which:
   - Retrieves user by credentials (username/password)
   - Validates credentials via provider
   - Throws `ValidationException` if validation fails
   - Increments rate limiter on failure

**Early Return for 2FA:**
- If user has 2FA enabled and confirmed: **Returns 2FA challenge response** and halts pipeline
- Otherwise: Continues to next pipeline step

---

## 8. Pipeline Step 4: Authentication Attempt

**File:** `vendor/laravel/fortify/src/Actions/AttemptToAuthenticate.php`

```php
public function handle($request, $next)
{
    if (Fortify::$authenticateUsingCallback) {
        return $this->handleUsingCustomCallback($request, $next);
    }

    if ($this->guard->attempt(
        $request->only(Fortify::username(), 'password'),
        $request->boolean('remember'))
    ) {
        return $next($request);
    }

    $this->throwFailedAuthenticationException($request);
}
```

### Standard Authentication (Default Path)

```php
$this->guard->attempt(
    $request->only(Fortify::username(), 'password'),
    $request->boolean('remember')
)
```

**What `Auth::attempt()` does:**
1. Retrieves user record by credentials
2. Validates password using hashing algorithm
3. If successful:
   - Creates authenticated session
   - Returns `true`
4. If failed:
   - Returns `false`

**Early Return:**
- ✅ **Credentials valid** → Continues to next pipeline step
- ❌ **Credentials invalid** → Calls `throwFailedAuthenticationException()`

### Custom Authentication (If Configured)

```php
protected function handleUsingCustomCallback($request, $next)
{
    $user = call_user_func(Fortify::$authenticateUsingCallback, $request);

    if (! $user) {
        $this->fireFailedEvent($request);
        return $this->throwFailedAuthenticationException($request);
    }

    $this->guard->login($user, $request->boolean('remember'));

    return $next($request);
}
```

### Failed Authentication Exception

```php
protected function throwFailedAuthenticationException($request)
{
    $this->limiter->increment($request);

    throw ValidationException::withMessages([
        Fortify::username() => [trans('auth.failed')],
    ]);
}
```

**On Authentication Failure:**
1. Increments rate limiter
2. Fires `Failed` authentication event
3. Throws `ValidationException` with message "auth.failed" (generic to prevent user enumeration)
4. **Pipeline halts and returns 422 error response**

---

## 9. Pipeline Step 5: Prepare Authenticated Session

**File:** `vendor/laravel/fortify/src/Actions/PrepareAuthenticatedSession.php`

```php
public function handle($request, $next)
{
    if ($request->hasSession()) {
        $request->session()->regenerate();
    }

    $this->limiter->clear($request);

    return $next($request);
}
```

**Actions:**
1. **Regenerates session ID** (security measure to prevent session fixation)
2. **Clears rate limiter** for this user
3. Continues to final step

---

## 10. Final Response

**File:** `vendor/laravel/fortify/src/Http/Responses/LoginResponse.php`

After all pipeline steps succeed:

```php
return app(LoginResponse::class);
```

**Default LoginResponse:**
- Redirects authenticated user to `config('fortify.home')` (typically `/dashboard`)
- Sets `X-Requested-With: XMLHttpRequest` header check
- Can be overridden in `AppServiceProvider`

---

## Complete Request Flow Diagram

```
POST /login
    ↓
[Middleware Stack]
    ├─ VerifyCsrfToken ❌ → 419 Token Mismatch
    ├─ guest guard ❌ → Redirect (already authenticated)
    └─ throttle (if configured) ❌ → 429 Too Many Requests
    ↓ ✅
[LoginRequest Validation]
    ├─ username required|string ❌ → 422 Validation Error
    ├─ password required|string ❌ → 422 Validation Error
    ↓ ✅
[Authentication Pipeline]
    ├─ EnsureLoginIsNotThrottled ❌ → LockoutResponse (429)
    ├─ CanonicalizeUsername (optional)
    ├─ RedirectIfTwoFactorAuthenticatable 
    │   ├─ validateCredentials() (checks username+password)
    │   │   ❌ → ValidationException (rate limit +1)
    │   ├─ 2FA required? ✅ → Return 2FA Challenge Response
    │   ✓
    ├─ AttemptToAuthenticate
    │   ├─ Auth::attempt() ❌ → ValidationException (rate limit +1)
    │   ✓ Creates session
    ├─ PrepareAuthenticatedSession
    │   ├─ session()->regenerate()
    │   ├─ limiter->clear()
    ↓ ✅
[LoginResponse]
    └─ Redirect to dashboard (200)
```

---

## Key Exception Handlers & Early Returns

| Step | Condition | Response | HTTP Status |
|------|-----------|----------|-------------|
| CSRF Middleware | Invalid/missing CSRF token | TokenMismatchException | 419 |
| Guest Middleware | Already authenticated | Redirect away | 302 |
| Throttle (route) | Rate limit exceeded | Too Many Requests | 429 |
| LoginRequest | Validation fails | Validation errors | 422 |
| EnsureLoginIsNotThrottled | Too many attempts | LockoutResponse | 429 |
| RedirectIfTwoFactorAuthenticatable | Invalid credentials | ValidationException | 422 |
| RedirectIfTwoFactorAuthenticatable | 2FA enabled | TwoFactorChallenge | 200 (JSON/redirect) |
| AttemptToAuthenticate | Invalid credentials | ValidationException | 422 |
| **All passes** | **Valid login** | **LoginResponse** | **302/200** |

---

## Configuration Points That Affect Login Flow

**In `config/fortify.php`:**

```php
'guard' => 'web',  // Which guard to authenticate with
'limiters' => [
    'login' => null,  // '5,1' for 5 attempts per 1 minute
    'two-factor' => '5,1', // 2FA challenge rate limit
],
'home' => '/dashboard',  // Redirect after successful login
'lowercase_usernames' => false,  // Canonicalize usernames
'views' => true,  // Register login views
'middleware' => ['web'],  // Middleware for all auth routes
'auth_middleware' => 'auth',  // Auth middleware name
```

---

## Security Measures in Place

1. ✅ **CSRF Protection** - Automatic via middleware
2. ✅ **Rate Limiting** - Configurable, tracked by IP+username
3. ✅ **Session Regeneration** - After successful login
4. ✅ **Password Hashing** - Via Laravel's hash verification
5. ✅ **Two-Factor Authentication** - Optional but supported
6. ✅ **Generic Error Messages** - Prevents user enumeration ("auth.failed")
7. ✅ **Failed Event Logging** - Events fired for monitoring
8. ✅ **Remember Token** - Optional persistent authentication
9. ✅ **Guest Middleware** - Prevents authenticated users from re-logging in

---

## Common Failure Points to Debug

**If login fails with no obvious error:**
1. ✅ Check `config('fortify.guard')` matches your auth guard
2. ✅ Verify User model uses correct authentication provider
3. ✅ Check if `config('fortify.limiters.login')` is throttling
4. ✅ Verify session middleware is in 'web' group
5. ✅ Check if 2FA is interfering (inspect response for `two_factor` flag)
6. ✅ Verify CSRF token is being sent with request
7. ✅ Check `config('fortify.home')` redirect is valid
8. ✅ Verify password column matches `password` in request

