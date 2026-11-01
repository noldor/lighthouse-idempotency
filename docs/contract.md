# Operation and replay contract

## Field declaration

`@idempotency` is attached to one state-changing action field, including a leaf inside any number of `@namespaced` mutation containers. It cannot annotate a namespace container or a `Query`/`Subscription` root field. Calling an annotated nested type from a query is rejected at execution. GraphQL does not make all nested sibling fields serial; design each business action's boundary explicitly.

An action field cannot combine `@idempotency` with native `@guard`, in either directive order. Guards propagated to that field from an object or type extension are also forbidden. Schema building raises `DefinitionException` for these combinations.

Establish the required identity before resolving the action leaf: use native `@guard` on an outer `@namespaced` entry field, or HTTP authentication middleware with matching `lighthouse.guards` configuration so `GraphQLContext` contains the intended actor. Do not put the guard on a type that propagates it to the idempotent leaf. An already authenticated context is a prerequisite; the package neither authenticates an unguarded entry nor invokes native guards early or repeatedly. Authentication and scope do not replace the handler's current replay authorization.

| Argument | Meaning |
| --- | --- |
| `operation: String` | Stable identity; defaults to the SDL coordinate, such as `DocumentMutations.create`. Aliases, operation names and selection sets do not change it. |
| `required: Boolean` | Whether a client key is required; omitted/null uses `default_required`. |
| `ttl: Int` | Positive retention seconds; omitted/null uses `default_ttl`. |
| `handler: String` | Concrete implementation of `Contracts\IdempotencyHandler`; omitted/null uses `default_handler`. |
| `model: String` | Standard handler's persisted result model. Without it the standard handler stores a JSON-compatible value. |
| `replayAbility: String` | Required standard-handler Gate ability for current replay access. There is no default allow. |
| `scopeContext: String` | Optional trusted visible or hidden Laravel Context key, whose string/integer value joins the scope. |
| `exclude: [String!]!` | Public dot-separated argument paths excluded from significant input. No wildcard paths. |

Standard options are validated against the **effective** handler, including one configured globally. A custom handler cannot be combined with explicit standard-handler options. A field can explicitly select the standard handler even when configuration defaults to a custom one.

A well-formed client directive on a field without `@idempotency` is semantically ignored. Invalid executable directive arguments still fail GraphQL coercion. A present key must be non-null even when a nullable variable has a non-null default: a small per-operation precheck closes that native coercion gap for the selected operation and its reachable fragments, including introspection and programmatic fields. This non-null requirement also applies to annotations inside skipped or runtime-inapplicable branches; it does not coerce unrelated input variables or inspect another operation's runtime variable values. Merged active field nodes must all omit the client directive or all supply the same key; conflicting presence or values is rejected. Inactive `@skip`/`@include` nodes do not participate.

When `required` is false and the client directive is absent, the complete native resolver pipeline runs in a transaction on the configured connection without creating a handler or ledger record. A present directive still requires a non-null valid `IdempotencyKey`.

## Ordering and persistence

1. Native GraphQL parsing/coercion establishes canonical scalar values.
2. Entry authentication has already established the current context identity under the application's required guard policy. The handler establishes actor/tenant scope and significant public input from that context. The standard handler rejects a missing actor; a different ambient guard must not substitute for the entry's required authentication.
3. A transaction on the configured connection obtains a nonblocking, transaction-owned PostgreSQL advisory lock for the physical ledger table and scoped operation/key tuple.
4. A live completed record authorizes the replay before comparing significant input. Standard model authorization and restoration read the primary write PDO rather than a possibly lagging read replica. Matching input returns the stored outcome without native fresh validation, mutable locks, writes or events.
5. A fresh or expired intent claims the tuple, executes the entire native pipeline once, encodes the result and completes the ledger in the same transaction.

Only SHA-256 hashes of scope and canonical input are stored; the incoming payload is not stored. Nested associative keys are sorted, list order remains significant, and omitted/null/empty distinctions remain distinct. The standard handler normalizes date-time objects to UTC and enums to their runtime values. Changes to canonicalization or public input compatibility require a migration/retry-window plan.

Mandatory operation writes must share the configured connection. The package does not make another database or external service atomic. Native Laravel model events remain active; publish external effects only under the application's existing after-commit/outbox guarantees. Do not catch an operation failure and turn it into a successful return unless that is an intentional completed business result.

An in-progress competing invocation fails immediately. An unfinished recursive claim in the same transaction also fails. Failure rolls back the claim and all mandatory local writes, allowing a later retry. Completion means a normal synchronous return from the resolver and successful payload encoding. An exception rolls back the claim and mandatory local writes. Native buffered, nonthrowing failures are not inferred from the final GraphQL `errors` array; output-field errors may also follow a committed operation. The application or a custom handler must convert a native nonthrowing failure into an operation exception when it intends a retryable failure. Resolver results must be synchronous; Deferred, promises and thenables are rejected and rolled back.

TTL begins after successful execution and payload encoding. At or after `expires_at`, the original guarantee ends and the same key can execute anew. Existing active writes cause cleanup to skip their locked physical rows; cleanup uses one fixed cutoff for its entire invocation.

## Custom handlers

Implement `Noldors\LighthouseIdempotency\Contracts\IdempotencyHandler`:

- `scope()` verifies the required identity and identifies current actor/tenant scope from the already authenticated context.
- `input()` returns significant JSON-compatible public arguments before native spreading/renaming.
- `store()` encodes a minimal successful result identity or promised snapshot.
- `authorizeReplay()` checks current access before an input mismatch or outcome is disclosed.
- `restore()` returns the runtime result and enforces access to the exact restored data when needed.

Custom handlers are resolved from the current Laravel container per invocation. Keep them transient or scoped when their constructor graph contains request data. The package cannot make a user-registered singleton safe by resolving it again. It never stores a handler in a schema closure.

`Services\ExecuteIdempotentOperation` is also available to non-GraphQL entry points. Such callers must supply equivalent input preparation, identity and current replay authorization; using the same underlying service preserves the authoritative claim boundary.

## Public errors

| `extensions.code` | Meaning |
| --- | --- |
| `IDEMPOTENCY_KEY_REQUIRED` | The action requires a client key. |
| `IDEMPOTENCY_KEY_CONFLICT` | Merged active field nodes disagree on the key or its presence. |
| `IDEMPOTENCY_IN_PROGRESS` | An execution of this scoped intent is still active. |
| `IDEMPOTENCY_INPUT_MISMATCH` | The retained scoped key belongs to different significant input. |
| `IDEMPOTENCY_RESULT_NOT_FOUND` | A live retained result can no longer be restored. |

Malformed scalar values and native authorization/validation failures follow the native GraphQL/Laravel contracts. Definition errors and configuration mistakes are developer errors, not public business messages.

## Storage migration

`idempotency:table` renders the package migration stub to the host's migration directory with selected connection/table literals. It does not run migrations. Review and execute the generated migration normally. The package does not automatically load another copy of the migration. A published stub is available through `lighthouse-idempotency-migrations` for applications that need to manage the source directly.

The ledger stores operation/scope/key uniqueness, an input hash, minimal result JSON, and created/completed/expiration timestamps. The expiration index supports bounded cleanup. Schema-qualified names and connection table prefixes are handled through Laravel's PostgreSQL grammar.
