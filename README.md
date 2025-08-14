# MonkeysLegion Skeleton
[![PHP Version](https://img.shields.io/badge/php-8.4%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

**A production-ready starter for building web apps & APIs with the MonkeysLegion framework.**

Includes:

* **PSR-11 DI Container** with config-first definitions
* **PSR-7/15 HTTP stack** (Request, Response, Middleware, Emitter)
* **Attribute-based Router v2** with auto-discovery
* **Live OpenAPI 3.1 & Swagger UI** (`/openapi.json`, `/docs`)
* **Validation layer** (DTO binding + attribute constraints)
* **Sliding-window Rate-Limiter** (IP + User buckets)
* **MLView** component templating
* **CLI toolbox** (migrations, cache, key-gen, scaffolding)
* **Entity → Migration** SQL diff generator
* **Schema auto-update** (`schema:update`)
* **Fixtures & Seeds** commands
* **Dev-server** with hot reload

---

## 🚀 Quick-start

```bash
composer create-project monkeyscloud/monkeyslegion-skeleton my-app
cd my-app

cp .env.example .env       # configure DB, secrets
composer install
php vendor/bin/ml key:generate

composer serve             # or php vendor/bin/dev-server
open http://127.0.0.1:8000 # your first MonkeysLegion page
```

---

## 📁 Project layout

```text
my-app/
├─ app/
│  ├─ Controller/     # HTTP controllers (auto-scanned)
│  ├─ Dto/            # Request DTOs with validation attributes
│  └─ Entity/         # DB entities
├─ config/
│  ├─ app.php         # DI definitions (services & middleware)
│  ├─ database.php    # DSN + creds
│  └─ *.mlc           # key-value config (CORS, cache, auth,…)
├─ public/            # Web root (index.php, assets)
├─ resources/
│  └─ views/          # MLView templates & components
├─ var/
│  ├─ cache/          # compiled templates, rate-limit buckets
│  └─ migrations/     # auto-generated SQL
├─ database/
│  └─ seeders/        # generated seeder stubs
├─ tests/             # PHPUnit integration/unit tests
│  └─ IntegrationTestCase.php
├─ vendor/            # Composer deps
├─ bin/               # Dev helpers (ml, dev-server)
├─ phpunit.xml        # PHPUnit config
└─ README.md
```

---

## 🔨 Configuration & DI

All services are wired in **`config/app.php`**. Customize:

* Database DSN & credentials (`config/database.php`)
* CORS, cache, auth (`.mlc` files)
* Middleware order, validation, rate-limit thresholds
* CLI commands registered in `CliKernel`

---

## ⚙️ Routing & Controllers

### Attribute syntax v2

```php
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ResponseInterface;

final class UserController
{
    #[Route('GET', '/users', summary: 'List users', tags: ['User'])]
    public function index(): ResponseInterface { /* … */ }

    #[Route('POST', '/login', name: 'user_login', tags: ['Auth'])]
    public function login(): ResponseInterface { /* … */ }

    #[Route(['PUT','PATCH'], '/users/{id}', summary: 'Update user')]
    public function update(string \$id): ResponseInterface { /* … */ }
}
```

* Controllers under `app/Controller` auto-registered.
* Imperative routes via `$router->add()` still available.

### Live API docs

| Endpoint            | Description                  |
| ------------------- | ---------------------------- |
| `GET /openapi.json` | OpenAPI 3.1 spec (generated) |
| `GET /docs`         | Swagger UI                   |

---

## 🔒 Validation Layer

```php
namespace App\Dto;

use MonkeysLegion\Validation\Attributes as Assert;

final readonly class SignupRequest
{
    public function __construct(
        #[Assert\NotBlank, Assert\Email]
        public string \$email,

        #[Assert\Length(min: 8, max: 64)]
        public string \$password,
    ) {}
}
```

* Binds JSON & query params into DTOs.
* On validation failure returns **400** with `{ "errors": […] }`.

---

## 🚦 Rate Limiting

* Hybrid buckets: per-user (`uid`) or per-IP.
* Defaults: **200 req / 60 s**. Configurable in `config/app.php`.
* Headers:

  ```
  X-RateLimit-Limit: 200
  X-RateLimit-Remaining: 123
  X-RateLimit-Reset: 1716509930
  ```
* 429 responses include `Retry-After`.

---

## 🖼 MLView Templating

Place templates in `resources/views/` with `.ml.php` extension. MLView supports:

- **Escaped output**: `{{ $var }}`
- **Raw output**: `{!! $html !!}`
- **Components**: `<x-foo>` includes `views/components/foo.ml.php`
- **Slots**: `<x-layout>…</x-layout>` with `@slot('name')…@endslot`
- **Layout inheritance**:
    - Child views start with `@extends('layouts.app')`
    - Define blocks in the child with `@section('name')…@endsection`
    - In the parent layout, use `@yield('name')` to inject each block
- **Control structures**: `@if … @endif`, `@foreach … @endforeach`

---

## 💾 Entities & Migrations

```php
use MonkeysLegion\Entity\Attributes\Field;

class User
{
    #[Field(type: 'string', length: 255)]
    private string \$email;
}
```

```bash
php vendor/bin/ml make:migration   # diff → var/migrations/
php vendor/bin/ml migrate          # apply migrations
php vendor/bin/ml rollback         # revert last migration
```

---

## 🌱 Fixtures & Seeds

```bash
php vendor/bin/ml make:seeder UsersTable  # create App/Database/Seeders/UsersTableSeeder.php
php vendor/bin/ml db:seed                 # run all seeders
php vendor/bin/ml db:seed UsersTable      # run only UsersTableSeeder
```

---

## 🛠 CLI Cheatsheet

```bash
php vendor/bin/ml key:generate
php vendor/bin/ml db:create
php vendor/bin/ml cache:clear
php vendor/bin/ml make:entity User
php vendor/bin/ml make:controller User
php vendor/bin/ml make:middleware Auth
php vendor/bin/ml make:policy User
php vendor/bin/ml make:migration
php vendor/bin/ml migrate
php vendor/bin/ml rollback
php vendor/bin/ml route:list
php vendor/bin/ml openapi:export
php vendor/bin/ml schema:update --dump
php vendor/bin/ml schema:update --force
php vendor/bin/ml make:seeder UsersTable
php vendor/bin/ml db:seed
```

---

## ✅ Testing & Build

### Test Harness

A base PHPUnit class **`tests/IntegrationTestCase.php`** provides:

* **DI bootstrapping** from `config/app.php`
* **PSR-15 pipeline** via `MiddlewareDispatcher`
* `createRequest($method, $uri, $headers, $body)` to craft HTTP requests
* `dispatch($request)` to get a `ResponseInterface`
* **Assertions**:

    * `assertStatus(Response, int)`
    * `assertJsonResponse(Response, array)`

**Example**:

```php
namespace Tests\Controller;

use Tests\IntegrationTestCase;

final class HomeControllerTest extends IntegrationTestCase
{
    public function testIndexReturnsHtml(): void
    {
        \$request  = \$this->createRequest('GET', '/');
        \$response = \$this->dispatch(\$request);

        \$this->assertStatus(\$response, 200);
        \$this->assertStringContainsString('<h1>', (string)\$response->getBody());
    }

    public function testApiReturnsJson(): void
    {
        \$request  = \$this->createRequest(
            'GET', '/api/users', ['Accept'=>'application/json']
        );
        \$response = \$this->dispatch(\$request);

        \$this->assertStatus(\$response, 200);
        \$this->assertJsonResponse(\$response, [ /* expected data */ ]);
    }
}
```

### Setup

1. Add test autoload in `composer.json`:

   ```jsonc
   "autoload-dev": { "psr-4": { "Tests\\": "tests/" } }
   ```
2. Create `phpunit.xml` pointing to `tests/` with bootstrap `vendor/autoload.php`.
3. Run:

   ```bash
   composer dump-autoload
   ./vendor/bin/phpunit
   ```

---

## 🤝 Contributing

1. Fork 🍴
2. Create a feature branch 🌱
3. Submit a PR 🚀

Happy hacking with **MonkeysLegion**! 🎉

## Contributors
<table>
  <tr>
    <td>
      <a href="https://github.com/yorchperaza">
        <img src="https://github.com/yorchperaza.png" width="100px;" alt="Jorge Peraza"/><br />
        <sub><b>Jorge Peraza</b></sub>
      </a>
    </td>
    <td>
      <a href="https://github.com/Amanar-Marouane">
        <img src="https://github.com/Amanar-Marouane.png" width="100px;" alt="Amanar Marouane"/><br />
        <sub><b>Amanar Marouane</b></sub>
      </a>
    </td>
  </tr>
</table>