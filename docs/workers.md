# Persistent-worker integration

Lighthouse creates resolver closures while materializing a schema. A retained schema can keep resolver objects and their complete constructor dependency graphs alive across requests. A nested `scoped` registration only clears its cached container instance: it cannot replace a request already captured by another retained object.

The package listens to native `StartExecution`; it does not replace Lighthouse's `FieldFactory`. It prepares authored object fields once per schema, with weak schema/field guards preventing duplicate wrappers. A separate invocation-only precheck rejects the native nullable-variable/default null-key gap on the selected operation and reachable fragments; it also covers introspection and programmatic fields without mutating their shared field definitions. Fields combining `@idempotency` and native `@guard`, including propagated guards, are rejected during schema build. Authentication must complete before the action leaf through a guarded outer namespace entry or correctly configured HTTP middleware; the package does not hydrate or invoke guard directives. It registers the listener through Laravel's application `booted` callback so general extension listeners registered during provider boot remain inside the replay boundary independently of provider discovery order.

When retaining Lighthouse's schema, warm these services together:

```php
use Noldors\LighthouseIdempotency\Execution\PrepareFieldResolvers;
use Nuwave\Lighthouse\Schema\SchemaBuilder;

'warm' => [
    ...\Laravel\Octane\Octane::defaultServicesToWarm(),
    SchemaBuilder::class,
    PrepareFieldResolvers::class,
],
```

If retaining native CRUD/policy directives, also align native mutable dependency lifetimes. With Lighthouse 6.72.5 and Octane, native mutation directives retain `Nuwave\Lighthouse\Execution\ErrorPool` and authorization directives retain `Illuminate\Contracts\Auth\Access\Gate`. Warming the shared ErrorPool ensures native GraphQL execution drains the same collector after each completed execution; warming Gate allows Octane's native application-switch listener to update its current container. Test consecutive requests, including vetoed mutations and changing policy dependencies, before enabling retention in a host.

Application resolvers must keep their entire constructor graph independent of request state. Pass invocation data as method arguments or `GraphQLContext`; resolve scoped dependencies inside invocation from the current container where framework access is necessary. Neither the package nor a `scoped` binding can repair a stale reference retained by a host resolver.

When moving an action's guard to an outer namespace entry or HTTP middleware, rebuild cached SDL and reload persistent workers so retained schemas use the new authentication boundary. Rebuild cached SDL and reload workers after changes to handler classes, directives or package configuration. The package keeps no request, actor, Context value, translator, model instance or handler in a retained resolver closure.
