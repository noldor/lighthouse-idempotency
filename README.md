# Lighthouse Idempotency

Reusable PostgreSQL-backed idempotency for synchronous Laravel Lighthouse mutation fields.

## Requirements

- PHP 8.5 or later.
- Laravel 13 and Lighthouse 6.72.5 or later within version 6.
- PostgreSQL. The ledger and every mandatory operation write must use the **same Laravel connection and transaction**.

No application models, custom scalar package, Carbon macros, or application service providers are required.

## Installation

```shell
composer require noldors/lighthouse-idempotency
php artisan vendor:publish --tag=lighthouse-idempotency-config
php artisan vendor:publish --tag=lighthouse-idempotency-schema
php artisan idempotency:table
php artisan migrate
```

Laravel discovers `Noldors\LighthouseIdempotency\LighthouseIdempotencyServiceProvider`. Select the connection and table in `config/lighthouse-idempotency.php` **before generating the migration**. The generated migration freezes its connection and table, including rollback, so changing configuration later cannot redirect it. Moving or renaming an existing ledger requires a separate migration and a coordinated configuration change.

Import the published declarations exactly once from the application's root SDL:

```graphql
#import idempotency.graphql
```

They declare the package-owned UUID scalar `IdempotencyKey` and the executable client directive. Nothing is automatically injected into the public schema.

## Basic model operation

```graphql
extend type Mutation {
    documents: DocumentMutations! @guard(with: ["api"]) @namespaced
}

type DocumentMutations {
    createDocument(input: CreateDocumentInput! @spread): Document!
        @create(model: "Document")
        @canModel(ability: "create", model: "Document")
        @idempotency(model: "Document", replayAbility: "view")
}
```

```graphql
mutation CreateDocument($key: IdempotencyKey!, $input: CreateDocumentInput!) {
    documents {
        createDocument(input: $input) @idempotent(key: $key) {
            id
            title
        }
    }
}
```

Authenticate before the action leaf resolves. The example uses native `@guard` on the outer `@namespaced` field; replace `api` with the required application guard. HTTP authentication middleware is another option when `lighthouse.guards` is configured to expose that authenticated actor in `GraphQLContext`. The package requires an already authenticated context and does not authenticate an unguarded entry.

`@idempotency` and `@guard` on the same action field are rejected with `DefinitionException` during schema build, regardless of directive order. This includes guards propagated from an object or type extension: keep the guard on the namespace entry field rather than a type that propagates it to the action leaf.

Create one UUID per business intent. Reuse it after an unknown outcome; create a different UUID for a different intent. UUID versions are not restricted and case is normalized. The standard handler scopes the key by the authenticated actor's class and identifier. An optional trusted Laravel Context value can add a tenant boundary.

A successful repeat uses the authenticated context to establish actor scope, checks current replay authorization through the handler's `authorizeReplay()` and input equality, reloads the current model from the primary connection, authorizes that exact returned instance, and skips fresh validation and writes. It is not an exact GraphQL response snapshot: selection sets and current field policies still apply. Missing results produce an error while their ledger remains valid; they do not restart execution.

A normal synchronous resolver return is recorded as a completed outcome; an exception rolls back the operation and its claim. GraphQL `errors` can also come from buffered native failures or output-field resolution after that return. If a native nonthrowing failure should remain retryable, the application or its custom handler must convert that failure into an operation exception. The package does not infer transaction outcomes from the final GraphQL response.

## Configuration

```php
return [
    'connection' => null,
    'table' => 'idempotency_records',
    'default_ttl' => 259_200,
    'default_handler' => \Noldors\LighthouseIdempotency\Handlers\StandardIdempotencyHandler::class,
    'default_required' => true,
    'prune_batch_size' => 1_000,
];
```

`connection: null` selects Laravel's default connection. This package never changes that default. Configure operation models and other writes to use the selected connection explicitly when it differs from the application's default.

The field overrides `ttl`, `required`, and `handler`; omitted or explicit `null` values use configuration. These SDL arguments have no schema default. TTL must be a positive integer number of seconds. A changed TTL only applies to newly completed executions; existing expiration timestamps never slide on replay. Field options are resolved when the executable schema is prepared: clear the schema cache and reload persistent workers after configuration changes.

See [the operation contract](docs/contract.md) for all directive options, failure codes, transaction boundaries, composition and supported limitations.

## Cleanup

```shell
php artisan idempotency:prune
php artisan idempotency:prune --batch=500
```

The application owns scheduling:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('idempotency:prune')->hourly()->withoutOverlapping();
```

The command deletes completed expired records in bounded PostgreSQL batches with `FOR UPDATE SKIP LOCKED`. It does not iterate through individual records or obtain per-record advisory locks. After expiration the same scoped key is a **new execution**, even before physical cleanup. Choose retention to cover the application's promised retry window.

## Translation

All package-owned public messages are English and use namespaced keys. Publish them to provide application translations:

```shell
php artisan vendor:publish --tag=lighthouse-idempotency-translations
```

Add your locale at `lang/vendor/lighthouse-idempotency/<locale>/messages.php`. Keep the keys and replace the values. Stable GraphQL `extensions.code` values do not depend on the locale. Messages are translated when the error occurs, so a retained schema does not capture the first request's language. Native GraphQL and Laravel messages retain their own translation/error contracts.

## Persistent workers

Warm the native `Nuwave\Lighthouse\Schema\SchemaBuilder` and package `Noldors\LighthouseIdempotency\Execution\PrepareFieldResolvers` together when retaining schemas across Octane requests. Native Lighthouse CRUD directives also retain dependencies; consult [worker integration](docs/workers.md) before enabling schema retention. Package resolver closures retain configuration only and construct handlers through the current container during each invocation.

The package can be installed alone. If `noldors/lighthouse-extensions` is also installed, its lock wrapper is prepared first and idempotency remains the outer wrapper. The package does not import or require that package.

## Development

```shell
docker compose run --rm app composer install
docker compose run --rm app composer test
```

Tests use their own PostgreSQL database and synthetic models. See the root Composer scripts for available quality checks.

See [the development and release workflow](https://github.com/noldor/lighthouse-idempotency/blob/master/.github/RELEASING.md) for verification, versioning and Packagist setup.
