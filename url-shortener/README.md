# URL Shortener API

A RESTful URL shortening service built with Laravel. Create short links, track clicks, manage expiration.

## Tech Stack

- PHP 8.3+ / Laravel 13
- SQLite or MySQL
- Redis (caching — optional future improvement)

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/urls` | Create a short URL |
| GET | `/{code}` | Redirect to original URL |
| GET | `/api/urls` | List active URLs |
| GET | `/api/urls/{code}` | Get URL info |
| PUT | `/api/urls/{code}` | Update original URL |
| DELETE | `/api/urls/{code}` | Delete short URL |
| GET | `/api/urls/{code}/stats` | Get click analytics |

## Usage

**Create a short URL:**
```bash
curl -X POST http://localhost:8000/api/urls \
  -H "Content-Type: application/json" \
  -d '{"long_url": "https://example.com"}'
```

Response:
```json
{
  "message": "URL created successfully",
  "data": {
    "code": "yl8qFQ",
    "long_url": "https://example.com",
    "expires_at": null
  }
}
```

`expires_at` is optional. If it is omitted or set to `null`, the short URL does not expire. Expired URLs return `404` from the redirect endpoint, while their metadata and statistics remain available through the API.

**Delete a short URL:**

```text
DELETE /api/urls/yl8qFQ → 204 No Content
```

Successful redirects create a detailed click record containing the timestamp, IP address, and user-agent. The aggregate `click_count` is maintained for quick statistics.

**Redirect:**
```
GET http://localhost:8000/yl8qFQ → 302 redirect to https://example.com
```

## Features

- [x] Short URL creation with random 6-char code
- [x] Redirect with 302
- [x] Click count tracking
- [x] Link expiration
- [x] Input validation via FormRequest
- [x] URL management endpoints
- [x] Detailed click analytics (IP, timestamp, user-agent)
- [ ] Custom slugs
- [ ] Referrer tracking
- [ ] Redis caching on redirect
- [ ] Rate limiting
- [x] Feature tests

## Setup

```bash
git clone https://github.com/kruzimatov/Backend-roadmap.sh.git
cd Backend-roadmap.sh/url-shortener
composer install
cp .env.example .env
php artisan key:generate
# Configure DB in .env
php artisan migrate
php artisan serve
```

## Project Context

Part of my backend learning roadmap — built by hand, no AI-generated code. Following [roadmap.sh/backend](https://roadmap.sh/backend) project track.
