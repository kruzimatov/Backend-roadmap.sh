# URL Shortener API

A RESTful URL shortening service built with Laravel. Create short links, track clicks, manage expiration.

## Tech Stack

- PHP 8.2+ / Laravel 12
- MySQL
- Redis (caching — coming soon)

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/urls` | Create a short URL |
| GET | `/{code}` | Redirect to original URL |
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
  "code": "yl8qFQ",
  "long_url": "https://example.com"
}
```

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
- [ ] Custom slugs
- [ ] Detailed click analytics (IP, referrer, user-agent)
- [ ] Redis caching on redirect
- [ ] Rate limiting
- [ ] Feature tests

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
