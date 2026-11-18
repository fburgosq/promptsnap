# PromptSnap — Pixelforge AI Image Generation

A Laravel 13 + Vue 3 + Inertia v3 application for generating images via the asynchronous **Pixelforge** AI image API (`https://pixelforge.promptedit.dev/v1`), built with exact integer nanodollar accounting, idempotent submissions, and single-path transactional state settlement.

---

## 1. Architecture Overview

```text
Browser (Vue 3 + Inertia v3)
  │
  ├─► GET  /generate               (GenerationController@create)
  ├─► POST /estimate               (GenerationController@estimate -> CostCalculator + PricingService)
  ├─► POST /generations            (GenerationController@store -> inserts queued row -> dispatches SubmitGenerationJob)
  └─► GET  /generations            (GenerationController@index -> polls every 3s via usePoll while non-final)

Background / Async Layer
  ├─► SubmitGenerationJob          (ShouldQueue, ShouldBeUnique; calls POST /v1/generations with Idempotency-Key = ULID)
  ├─► POST /webhooks/pixelforge    (PixelforgeWebhookController -> PixelforgeSignatureVerifier -> WebhookEvent + GenerationSettler)
  └─► pixelforge:reconcile         (Scheduled every minute -> polls GET /v1/generations/{id} & re-dispatches stuck jobs)
                                           │
                                           ▼
                                  GenerationSettler
                        (DB::transaction + lockForUpdate + CostCalculator)
```

### Core Components

- **[`App\Support\Nano`](app/Support/Nano.php)**: Pure string-to-integer nanodollar parser, serializer, formatter, and ceiling integer division utility. Zero floating-point operations.
- **[`App\Services\Pricing\CostCalculator`](app/Services/Pricing/CostCalculator.php)**: Pure service that computes pre-submit estimates (`estimate()`), exact post-completion provider costs (`actual()`), and the single markup + ceiling rounding rule (`charge()`).
- **[`App\Services\Pricing\PricingService`](app/Services/Pricing/PricingService.php)**: Fetches `GET /v1/pricing` without authentication, caches live token tables and rates for 5 minutes (`300s`), and falls back safely to documented defaults without poisoning the cache if Pixelforge is unreachable.
- **[`App\Services\Generations\GenerationSettler`](app/Services/Generations/GenerationSettler.php)**: The **only** service allowed to advance a `Generation`'s status or write cost columns. Executes inside `DB::transaction()` with `lockForUpdate()`, enforces monotonic forward-only transitions (`queued < processing < succeeded|failed`), refuses transitions out of final states, and computes cost exactly once when transitioning into `succeeded`.
- **[`App\Jobs\SubmitGenerationJob`](app/Jobs/SubmitGenerationJob.php)**: Unique queued job (`ShouldBeUnique` per generation ULID) that submits `POST /v1/generations` asynchronously with `Idempotency-Key: <ulid>` and `metadata.generation_id = <ulid>`.
- **[`App\Http\Controllers\Webhooks\PixelforgeWebhookController`](app/Http/Controllers/Webhooks/PixelforgeWebhookController.php)** & **[`PixelforgeSignatureVerifier`](app/Services/Pixelforge/PixelforgeSignatureVerifier.php)**: Outside the `web` / session / CSRF middleware stack; verifies `Pixelforge-Signature` against raw request bytes (`$request->getContent()`) before JSON decoding, deduplicates `event_id` in `webhook_events`, and settles via `GenerationSettler` in the same transaction.

---

## 2. The Money Model (Nanodollars, Markup & Rounding Rule)

### Why Integer Nanodollars?

All monetary values are stored and calculated as **integer nanodollars** (`1 USD = 1_000_000_000 nanodollars`) in `UNSIGNED BIGINT` columns (`*_nano`):

- `estimated_charge_nano`
- `estimated_max_charge_nano`
- `provider_cost_nano`
- `charged_nano`

IEEE-754 floats cannot represent base-10 decimals like `$0.046125` or per-token micro-rates exactly. `Nano::fromDecimalString()` validates input strings with `/^\d+(?:\.\d{1,9})?$/`, splits on `.`, pads the fractional part to 9 digits, and computes `$whole * 1_000_000_000 + $fraction` in 64-bit integer math.

### Token Cost Formula

Pixelforge rates are quoted as USD per 1,000,000 tokens. Since `1 USD = 10^9 nanodollars`:
$$\text{cost\_nano} = \text{tokens} \times \text{rate\_usd\_per\_1M} \times 1000 = \frac{\text{tokens} \times \text{rate\_nano}}{1,000,000}$$

Provider cost is the exact integer sum over the four token buckets (`input_text`, `input_image`, `output`, `reasoning`) using the **`rates` snapshot stored on the generation object at creation** (never the current `/pricing` endpoint).

### Single Markup & Ceiling Rounding Rule

User-facing charged price applies the configured markup (`config('services.pixelforge.markup_percent')`, default `25%`) **once per generation** in `CostCalculator::charge()`:

$$\text{charged\_nano} = \left\lceil \frac{\text{provider\_cost\_nano} \times (100 + \text{markup\_percent})}{100} \right\rceil$$

Implemented in integer math via `Nano::ceilDiv($providerCostNano * (100 + $markup), 100)`:

```php
intdiv($providerCostNano * (100 + $markup) + 99, 100)
```

- **Why ceiling division applied once?** Rounding down would undercharge by fractions of a nanodollar; applying ceiling per token bucket would compound rounding up 4 times. Applying ceiling once on total `provider_cost_nano` guarantees we never undercharge while keeping rounding minimal (at most `0.99` nanodollars per generation).
- **Profit**: `profit_nano = charged_nano - provider_cost_nano`.
- **Failed generations**: Always `provider_cost_nano = 0` and `charged_nano = 0`.

---

## 3. Webhook, Idempotency & Retry Strategy

### Pre-Creation & Outbound Idempotency

1. When a user submits `/generate`, `GenerationController@store` creates the local `generations` row **before** any Pixelforge API call is made.
2. `SubmitGenerationJob` sends the generation's ULID (`$generation->id`) in **both**:
    - The `Idempotency-Key` HTTP header (so retries after network timeouts or 503s never create duplicate generations on Pixelforge).
    - `metadata.generation_id` in the JSON body (so if a webhook arrives _before_ the HTTP `202 Accepted` response returns to our queue worker, `PixelforgeWebhookController` matches the local row by `data.object.metadata.generation_id` and backfills `provider_id`).

### Webhook Verification & Deduplication

1. **Raw-Body HMAC Verification**: `PixelforgeSignatureVerifier` parses `Pixelforge-Signature: t=<unix>,v1=<hex>`, rejects timestamps where `|now - t| > 300s`, computes `HMAC-SHA256("{t}.{rawBody}", webhook_secret)` against `$request->getContent()` **before** `json_decode()`, and compares with `hash_equals()`.
2. **Atomic Deduplication + Settlement**: Inside a single `DB::transaction()`, the controller inserts into `webhook_events` (`UNIQUE(event_id)`) and invokes `GenerationSettler::applyProviderSnapshot()`.
    - If the `event_id` is a duplicate, the unique constraint violation is caught and returns `204 No Content`.
    - **Defence in depth**: Even if Pixelforge sends two _different_ `event_id`s for the same final state (e.g. the `[duplicate]` test keyword) or out-of-order events (`generation.succeeded` followed by a late `generation.processing`), `GenerationSettler` locks the `generations` row with `SELECT ... FOR UPDATE`, checks `GenerationStatus::canTransitionTo()`, and ignores any backward or post-final transition.

### Retry & Reconciliation Strategy

- **Transient Errors (`429 rate_limit_exceeded`, `429 concurrency_limit_exceeded`, `503 overloaded`, `ConnectionFailed`)**: `SubmitGenerationJob` releases the job back onto the queue with `delay = max(Retry-After, min(60, 2^attempt) + random_int(0, 2))` for up to 6 attempts. If all 6 attempts are exhausted, `GenerationSettler::failLocally()` marks the row `failed` (`charged_nano = 0`) with `"Pixelforge is busy, you weren't charged, please try again"`.
- **Terminal Errors (`400 content_policy_violation`, `429 daily_limit_exceeded`, `422 validation_failed`, `401 invalid_api_key`)**: Immediately settled as `failed` (`charged_nano = 0`) via `GenerationSettler::failLocally()` without retrying. `invalid_api_key` logs a `CRITICAL` alert.
- **Scheduled Reconciliation (`pixelforge:reconcile`)**: Runs every minute (`withoutOverlapping(5)`):
    - Polls `GET /v1/generations/{provider_id}` for `queued`/`processing` rows with a `provider_id` unchanged for `> 2 minutes` and settles them via `GenerationSettler`.
    - Re-dispatches `SubmitGenerationJob` for `queued` rows with `provider_id = null` unchanged for `> 5 minutes`.
    - Stops polling immediately if Pixelforge responds with `429` or `503`.

---

## 4. Running Locally

### Prerequisites

- PHP 8.3+ (8.5 supported), Composer, Node.js 22+
- Two Pixelforge API keys (recommended): one configured for your **local tunnel webhook URL** and one for **production/staging**, since each Pixelforge API key is bound to a single webhook URL and shares a 3-in-flight concurrency limit.

### Step-by-Step Setup

```bash
# 1. Install dependencies, create .env, generate app key, migrate, and build assets
composer run setup

# 2. Configure your local Pixelforge credentials in .env
PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=pf_live_your_local_key
PIXELFORGE_WEBHOOK_SECRET=whsec_your_local_signing_secret
PIXELFORGE_MARKUP_PERCENT=25
PIXELFORGE_WEBHOOK_TOLERANCE_SECONDS=300
```

### Running the Processes

1. **Web Server, Queue Worker & Vite Dev Server** (all-in-one via `composer run dev`, or separately):
    ```bash
    composer run dev
    ```
    Or in separate terminals:
    ```bash
    php artisan serve
    php artisan queue:work --tries=6
    php artisan schedule:work
    ```
2. **Webhook Tunnel** (expose local port 8000 so Pixelforge can deliver webhooks):
    ```bash
    # Using cloudflared or ngrok:
    cloudflared tunnel --url http://localhost:8000
    # or
    ngrok http 8000
    ```
    Set your local API key's webhook URL in the Pixelforge dashboard to:
    `https://<your-tunnel-subdomain>/webhooks/pixelforge`

---

## 5. Production Deployment Checklist (Laravel Cloud / Any Host)

1. **Database**: Provision a production PostgreSQL or MySQL database (do **not** use SQLite in production so `SELECT ... FOR UPDATE` row-level locking operates across concurrent workers).
2. **Environment Variables**:
    ```ini
    APP_ENV=production
    APP_DEBUG=false
    APP_KEY=base64:...
    APP_URL=https://your-production-domain.com

    DB_CONNECTION=pgsql # or mysql
    QUEUE_CONNECTION=database # or redis
    CACHE_STORE=database # or redis

    PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
    PIXELFORGE_API_KEY=pf_live_your_production_key
    PIXELFORGE_WEBHOOK_SECRET=whsec_your_production_signing_secret
    PIXELFORGE_MARKUP_PERCENT=25
    PIXELFORGE_WEBHOOK_TOLERANCE_SECONDS=300
    ```
3. **Build & Deploy Commands**:
    - Build: `composer install --no-dev --optimize-autoloader && npm ci && npm run build`
    - Deploy: `php artisan migrate --force`
4. **Background Processes**:
    - **Queue Worker**: Enable a managed queue worker running `php artisan queue:work` so `SubmitGenerationJob` executes immediately.
    - **Scheduler**: Enable the Laravel scheduler (`php artisan schedule:run` every minute) so `pixelforge:reconcile` heals any missed webhooks.
5. **Pixelforge Dashboard Webhook Registration**:
    - Use a **separate production API key** from your local development key.
    - Set its Webhook URL to: `https://<your-production-domain>/webhooks/pixelforge`
    - Copy the corresponding Signing Secret into `PIXELFORGE_WEBHOOK_SECRET`.

---

## 6. Manual Verification Script (Prompt Keywords)

Log in, navigate to `/generate`, and test each scenario below:

| Scenario                                 | Prompt / Inputs                                                                           | Expected UI Behavior                                                                                                                                                                                                                                           | Expected DB State (`generations`)                                                                                                                                                                             |
| :--------------------------------------- | :---------------------------------------------------------------------------------------- | :------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | :------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **1. Standard Success**                  | `A minimalist concrete pavilion by a lake [succeed]` (`1024x1024`, `medium`)              | Redirects to `/generations`; card shows _In Pixelforge Queue_ → _Generating image..._ → renders image + exact Actual Charge. Live polling badge stops.                                                                                                         | `status = 'succeeded'`, `provider_id = 'gen_...'`, `rates` & `usage` populated, `provider_cost_nano > 0`, `charged_nano = ceil(provider_cost_nano * 125 / 100)`, `image_url_expires_at = completed_at + 24h`. |
| **2. Simulated Failure**                 | `An abstract sculpture [fail]`                                                            | Card transitions from `queued` → `processing` → `failed`. Shows red **Generation Failed** banner and **Actual Charge: Free**.                                                                                                                                  | `status = 'failed'`, `provider_cost_nano = 0`, `charged_nano = 0`, `error_code` & `error_message` populated, `completed_at` set.                                                                              |
| **3. Slow Generation**                   | `A detailed isometric city [slow]`                                                        | Card stays in `queued` / `processing` while `/generations` polls every 3s (`usePoll`). If webhook is delayed >2m, `php artisan pixelforge:reconcile` settles it.                                                                                               | Transitions monotonically `queued` → `processing` (`started_at` set) → `succeeded` (`completed_at` set).                                                                                                      |
| **4. Duplicate / Out-of-Order Webhooks** | `A neon cyber alley [duplicate]`                                                          | Settles cleanly to `succeeded` once; price never changes or double-charges.                                                                                                                                                                                    | `webhook_events` records each unique `event_id`; `generations.charged_nano` is computed and written **once**.                                                                                                 |
| **5. Content Policy Block**              | `Unsafe test prompt [blocked]`                                                            | Job catches `400 content_policy_violation` immediately (no retries). Card shows `"Your prompt was blocked by the content safety policy. You were not charged."` and **Free**.                                                                                  | `status = 'failed'`, `provider_id = null`, `error_code = 'content_policy_violation'`, `provider_cost_nano = 0`, `charged_nano = 0`.                                                                           |
| **6. Service Overloaded (503)**          | `A misty redwood forest [overloaded]`                                                     | `SubmitGenerationJob` catches `503 overloaded`, honours `Retry-After: 2` + backoff/jitter with the **same `Idempotency-Key`**, and either succeeds on retry or fails free after 6 attempts with `"Pixelforge is busy, you weren't charged, please try again"`. | Never stuck in `queued` forever; either settles with 1 `provider_id` or `status = 'failed'` with `charged_nano = 0`.                                                                                          |
| **7. Four Reference Images**             | Any prompt + click **Add URL (4/4)** and enter 4 `https://...` URLs (`1536x1024`, `high`) | Estimate panel shows `4 images (3060 exact tokens)` in breakdown; Add URL button disables at `4/4`.                                                                                                                                                            | `reference_images` JSON array contains all 4 URLs; `usage.input_image = 3060` (`4 × 765`).                                                                                                                    |

---

## 7. Trade-offs & What I'd Do With More Time

- **Polling (`usePoll`) vs. WebSockets (Laravel Reverb)**: Polling `only: ['generations']` every 3s while in-flight items are visible is simple, stateless, and requires zero extra infrastructure, and automatically stops when all visible items are final. With more time, broadcasting a `GenerationUpdated` event over Laravel Reverb/Echo would push instant transitions without 3s polling intervals.
- **Ephemeral Provider Image URLs (24h Expiry)**: Currently we detect expired image URLs both server-side (`image_url_expires_at <= now()`) and client-side (`<img @error>`) and render a clean `"Image expired"` placeholder. In production, a post-settlement job could copy the rendered image into an S3 / Cloudflare R2 bucket (`Storage::disk('public')`) so users retain permanent access past the 24-hour provider window.
- **Reconciliation vs. `/v1/usage` Audit Command**: Adding a daily scheduled command that compares `SUM(provider_cost_nano)` of local `succeeded` generations grouped by `DATE(completed_at)` against Pixelforge's `GET /v1/usage?start_date=...&end_date=...` endpoint would provide automated financial reconciliation alerts.
