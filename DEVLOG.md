## 2026-04-27 — Week 1

### What I built today
- Implemented and tested multiple HTTP endpoints:
  - `GET /users` endpoint (data retrieval simulation)
  - `GET /search` endpoint (query parameter handling: `q`, `page`)
  - `POST /users` endpoint (JSON body handling via wrk Lua script)
- Set up load testing using `wrk` with different scenarios:
  - Pure GET load test
  - Query-string heavy GET test
  - POST JSON payload test using Lua scripting

---

### What worked
- Server handled high concurrency efficiently under load (`-t4`, `-c200`)
- Stable throughput across endpoints (~40k requests/sec range)
- `/users` endpoint remained consistent under both GET and POST load
- Query parameter routing worked correctly (`/search?q=test&page=1`)
- JSON POST testing pipeline with `wrk + Lua script` worked as expected
- Low average latency for most requests (~5–6ms baseline endpoints)

---

### What did not work / surprises
- `/search` endpoint showed significant latency spikes:
  - Avg: ~30.49ms  
  - Max: ~738.87ms  
  - Indicates uneven performance under query parsing or internal processing
- POST workload reduced throughput (~37k req/sec vs ~40k GET)
- High variance in latency (`stdev` very large in search test)
- Real-world endpoints behave very differently from `/ping` microbenchmark
- Complexity (query parsing / logic) directly impacts stability under load

---

### Decision made
- Microbenchmarks like `/ping` are not sufficient for performance evaluation → real endpoints must always be tested
- Will treat GET, POST, and query-heavy routes as separate performance profiles
- Will prioritize:
  - stabilizing `/search` latency distribution (reduce spikes)
  - ensuring POST routes maintain consistent throughput under concurrency
- Standardized benchmarking approach:
  - 200 connections baseline
  - 10-second sustained load
  - comparison across endpoint types (not single endpoint testing)

---

### Benchmark numbers (if applicable)

| Metric         | Value (req/sec) |
|----------------|-----------------|
| GET /users     | 40,558          |
| GET /search    | 40,628          |
| POST /users    | 37,215          |

**Latency breakdown:**

| Endpoint    | Avg Latency | Max Latency |
|------------|-------------|-------------|
| /users     | 5.81ms      | 85.94ms     |
| /search    | 30.49ms     | 738.87ms    |
| POST /users| 6.31ms      | 133.66ms    |

**vs last microbenchmark (/ping):**
- ~48,431 req/sec (pure synthetic endpoint)
- Real endpoints: ~37k–40k req/sec → ~10–23% drop under realistic workloads

---

### Benchmark numbers — Week 1, Concurrent Test

| Metric              | Value          |
|---------------------|----------------|
| /ping alone         | ~48,431 req/s  |
| /ping + /slow concurrent | 41,189 req/s |
| /ping degradation   | ~15% (expected)|
| /slow avg latency   | 1.08s (correct — 1s sleep) |
| /slow throughput    | 44.26 req/s (88.5% of theoretical max) |

PROVEN: Coroutine::sleep() on /slow does NOT block /ping.
Event loop is functioning correctly.
This is the foundation the entire framework is built on.

---

### Resources / references
- https://wiki.swoole.com/
- https://github.com/wg/wrk
- https://github.com/wg/wrk/blob/master/SCRIPTING
- HTTP load testing concepts (latency percentiles, concurrency models)
- Linux networking performance basics (TCP backlog, event loops, syscall overhead)

---

## 2026-05-04 — Week 2

### Coroutine Context Lifecycle Notes
Each incoming HTTP request executes inside its own Swoole coroutine.

Lifecycle:

1. Request arrives
2. Swoole creates coroutine
3. Coroutine receives unique CID
4. SwiftPHP Context storage binds to CID
5. Request-scoped data stored in Context
6. Child coroutines inherit parent context by reference
7. Response completes
8. Coroutine exits
9. Context destroyed automatically
10. CID becomes reusable by Swoole scheduler

Important observation:
CID values are NOT globally unique forever.
They are only unique during active coroutine lifetime.

Therefore:
- Context MUST be destroyed immediately on coroutine completion
- Otherwise memory leaks and context bleed will occur
- Request isolation depends entirely on strict CID cleanup

Verified through concurrent wrk stress testing under mixed coroutine workloads.

---

### Isolation Pass Record
| Metric    | Pass    | Fail    |  
|-----------|---------|---------|
| Isolation |   500   |    0    |

---

### What I Have Now
Context::set()     — writes to own CID only
Context::get()     — live traversal up parent chain
Context::has()     — live traversal up parent chain
Context::all()     — merged view, local overrides win
Context::destroy() — wipes entire coroutine subtree from root
Context::inherit() — empty child + parent link + root tree registration
bootstrap()        — auto-detects parent via getPcid(), auto-inherits

---


## 2026-05-11 — Week 3, Day 1

### What I built today
- Implemented full DI container core for SwiftPHP:
  - `src/Core/Container/Container.php` (PSR-11 compliant container)
  - `src/Core/Container/Resolver.php` (reflection-based auto-wiring system)
  - `src/Core/Container/ServiceProvider.php` (base service provider contract)
- Introduced three dependency lifecycles:
  - `bind()` → transient dependencies (new instance per resolution)
  - `singleton()` → worker-global shared instances
  - `scoped()` → coroutine/request-isolated instances
- Built coroutine-scoped dependency resolution using `Swiftphp\Framework\Core\Coroutine\Context`
- Created a stress test for scoped DI isolation using 200 concurrent Swoole coroutines

---

### What worked
- Coroutine-scoped dependency isolation worked correctly under high concurrency
- 200 concurrent coroutines each received unique scoped instances (`Unique: 200`)
- Scoped lifecycle correctly prevented cross-coroutine state leakage
- Reflection-based auto-wiring successfully resolved nested dependencies recursively
- Container correctly handled mixed resolution modes (bind, singleton, scoped, auto-wire)
- Swoole coroutine scheduling did not interfere with DI correctness under load

---

### What did not work / surprises
- Initial failure due to missing `psr/container` dependency (`ContainerInterface` not found)
- Reinforced that framework-level contracts must be explicitly installed via Composer even when correctly referenced in code
- Confirmed that coroutine-scoped storage must rely on `Coroutine Context`, not static/global state, to ensure isolation in long-lived Swoole workers

---

### Decision made
- Adopted PSR-11 (`ContainerInterface`) as a strict contract for SwiftPHP container implementation
- Separated responsibilities:
  - `Container` → lifecycle + binding registry
  - `Resolver` → reflection + dependency graph construction
- Chose coroutine-scoped DI as a foundational feature for request isolation (FastAPI-style behavior in PHP + Swoole)
- Standardized scoped lifecycle storage via `Coroutine Context` as the isolation boundary

---

### Benchmark numbers
Metric: Scoped DI Isolation Test (200 coroutines)
Value:
- Coroutines: 200
- Unique scoped instances: 200
- Pass rate: 100%

vs previous week:
- N/A (new subsystem introduced)

---

### Resources / references
- https://www.php-fig.org/psr/psr-11/
- https://wiki.swoole.com/
- Swoole Coroutine documentation (context + lifecycle behavior)
- Internal stress test: coroutine-scoped dependency isolation (200 concurrent coroutines)


## 2026-05-18 — Week 4, Day 1

### What I built today

- Built `src/Http/Router/Router.php`: register routes with `get/post/put/patch/delete/options` methods.
- Added support for route parameters: `/users/{id}` and type-constrained parameters like `/users/{id:int}`.
- Implemented route groups with prefixing and shared middleware inheritance.
- Built `src/Http/Middleware/Pipeline.php`: executes middleware stack using an iterative onion model.
- Built `src/Http/Request/Request.php`: Swoole request wrapper with clean, unified accessors.
- Built `src/Http/Response/Response.php`: static helpers `ok()`, `created()`, `notFound()`, `unprocessable()`, `stream()`.
- Introduced `ResponsePayload` to replace raw arrays for stricter response contracts and cleaner dispatching.
- Refactored middleware execution to avoid `array_reverse()` and reduce allocation overhead in hot paths.

---

### What worked

- Route registration and matching system is stable and correctly handles both static and parameterized routes.
- Type-constrained routing (`{id:int}`) correctly enforces integer casting and prevents invalid matches from reaching handlers.
- Route grouping with prefix + middleware composition works and preserves hierarchical structure.
- Middleware pipeline executes in correct order using an iterative stack builder (no `array_reverse`, no functional overhead).
- Request wrapper cleanly abstracts Swoole internals and supports query, JSON, POST, headers, and unified input access.
- Response layer cleanly separates payload creation (`ResponsePayload`) from execution (`send()`), improving type safety and clarity.
- Coroutine context isolation correctly prevents cross-request state leakage under concurrent load.
- Full request lifecycle (Request → Router → Middleware → Controller → Response) executes reliably under Swoole concurrency.
- High-concurrency benchmarks show stable throughput (~18k req/sec on low-spec hardware) without crashes or memory corruption.

---

### What was improved today (architectural wins)

- Replaced array-based response contracts with strongly typed `ResponsePayload`.
- Replaced recursive middleware patterns with explicit iterative stack construction.
- Introduced coroutine-scoped Request/Response lifecycle using Context isolation.
- Improved route normalization to handle malformed or inconsistent URIs safely.
- Strengthened exception handling to prevent silent coroutine failure and request hangs.
- Ensured middleware merging behaves deterministically across global and route-scoped layers.

---

### What is now stable at kernel level

- Routing engine (regex + parameter binding)
- Middleware pipeline (onion execution model)
- Request abstraction layer (Swoole-safe input normalization)
- Response dispatch system (payload-driven output)
- Dependency Injection container (singleton + scoped lifecycle)
- Coroutine context isolation (request-safe execution model)
- Application lifecycle (full request → response cycle under concurrency)

---

## 2026-05-20 — Week 4, Day 3

### What I built today
-	Wire everything together in the Application class.

### Tests Performed Today

#### 1. Basic Route Execution Test
- Endpoint: `GET /ping`
- Verified:
  - Router matching works
  - Request/Response lifecycle executes correctly
  - Coroutine ID retrieval works
- Result:
  - Stable response returned (`pong`)
  - No memory or runtime errors

---

#### 2. Route Parameter & Type Constraint Test
- Endpoint: `GET /users/{id:int}`
- Verified:
  - Dynamic route matching
  - Regex-based parameter extraction
  - Type casting (`int` enforcement)
- Result:
  - `/users/55` → success (`user_id: 55`, type: integer)
  - `/users/john` → correctly rejected (404 Route not found)

---

#### 3. Middleware Group Test
- Endpoint: `GET /api/status`
- Verified:
  - Route group prefix handling (`/api`)
  - Middleware injection into grouped routes
  - Container-based middleware resolution
- Result:
  - Middleware executed successfully
  - Response returned `middleware: true`
  - No pipeline or DI failures

---

#### 4. High-Concurrency Load Test (wrk)

##### Test A — Simple endpoint
```bash
wrk -t4 -c200 -d20s http://127.0.0.1:8080/ping
```
- ~13,000–15,000 req/sec observed
- Stable latency under load
- No crashes or coroutine leaks

##### Test B — Parameterized route
```bash
wrk -t4 -c200 -d20s http://127.0.0.1:8080/users/123
```
- ~12,000–13,000 req/sec
- Slight overhead due to regex + casting
- Still stable under concurrency

##### Test C — Heavy route group load
```bash
wrk -t8 -c500 -d20s http://127.0.0.1:8080/isolation/999
```
- ~8,000–15,000 req/sec (varied depending on workload state)
- No memory corruption or request bleeding
- 500 concurrent connections handled successfully
- Only non-critical timeout noise under extreme load

##### Test D — Benchmark payload stress test
```bash
wrk -t8 -c500 -d30s http://127.0.0.1:8080/benchmark/55
```
- ~18,000–19,000 req/sec peak throughput
- ~71 MB/sec transfer rate
- ~600,000+ requests processed
- 1 timeout in entire run (statistically negligible)
- Stable memory behavior under sustained load
- The benchmark route transferred 2.38GB in 30 seconds. That is 75MB/s sustained JSON serialisation throughput.

### Key Observations from Testing
- Router performance is stable and not a bottleneck
- Middleware pipeline executes correctly under concurrency
- Coroutine context isolation is functioning correctly (no cross-request leakage)
- Response system handles high throughput without crashes
- Container resolution works correctly under load
- System scales linearly within hardware limits (2-core CPU saturation observed)

### Where SwiftPHP Stands as of Today
✅ Swoole server — 44k+ req/s
✅ Coroutine context isolation — 500/500 pass
✅ DI Container — 200/200 scoped isolation pass
✅ Router — groups, params, type constraints, middleware stack
✅ Request — unified input(), all(), route params, JSON caching
✅ Response — ResponsePayload, all static helpers, isWritable guard
✅ Pipeline — manual onion build, MiddlewareInterface enforcement
✅ Application — full request lifecycle wired end to end
✅ ConfigureRuntime::boot() in Application::serve()

---


## 2026-05-25 — Week 5, Day 1

### What I built today
- Improved and stabilized ConnectionPool for coroutine-safe database handling
- Implemented and refined DB façade with:
- coroutine-scoped transaction binding
- nested transactions via SAVEPOINT
- post-commit hooks system
- DDL guard against implicit transaction commits
* **`MysqlPool` Integration**: Migrated the load-testing harness (`tests/performance/pool_stress_test.php`) to evaluate the specialized framework driver rather than a raw, generic connection pool.
* **`RedisPool` Hardening**: Implemented rigid type-safe validation rules via `validateConfig()` executed immediately prior to normalization to protect the factory from corrupted array values.
* **Stall Guardrails**: Injected open-gateway option proxies into the `RedisPool` factory, enforcing an explicit `Redis::OPT_READ_TIMEOUT` configuration to shield against infinite coroutine read stalls.

### What worked
- Pool correctly scales up to max capacity without leaks
- No deadlocks observed under 1000 concurrent coroutines
- Connection reuse behaves correctly under saturation
- Coroutine context binding for transactions works reliably
- Health checks successfully filter broken connections
- Benchmark system produces stable QPS across multiple tiers
* **Native Hooking**: Swoole's transparent stream splitting via `SWOOLE_HOOK_ALL` proved highly performant when multiplexing native `phpredis` extension instances, avoiding the deprecated custom coroutine components completely.
* **Symmetric Architecture**: Replicating the configuration normalization flow across both `MysqlPool` and `RedisPool` kept the driver layer predictable and developer-friendly.

### What did not work / surprises
- Pool saturation behavior revealed that QPS increases even when latency increases significantly (not linear scaling)
* **Inheritance Blockers**: Hit an immediate runtime crash when spinning up the script: `PHP Fatal error: Class Swiftphp\Framework\Database\Pool\MysqlPool cannot extend final class ConnectionPool`. 
* **The OOP Paradox**: Caught myself backtracking on an old design trap. The base `ConnectionPool` had been strictly marked as `final` during a previous composition experiment, causing the new inheritance-based specialized pools to break.

### Decision made
- Standardized config normalization + validation BEFORE factory execution. Reason: fail-fast prevents runtime pool corruption
* **Dropping `final` from the Base Pool**: Chose to strip the `final` constraint from `ConnectionPool` rather than writing complex composition proxy patterns for every single driver method (`acquire()`, `release()`, metric tracking counters). Inheritance keeps the framework core significantly cleaner and less boilerplate-heavy.
* **Proactive Fail-Fast Validation**: Positioned the configuration validation step *before* array normalization to guarantee malformed configuration options (e.g., negative ports or string indices) throw clean `InvalidArgumentException` errors instead of masking bugs under silent default value falls.

### Benchmark numbers (if applicable)
Metric: QPS / Latency / Saturation behavior

10 workers: ~1395 QPS / ~3–5ms latency / LOW saturation
25 workers: ~985 QPS / ~20ms latency / MID saturation
50 workers: ~1597 QPS / ~20ms latency / MAXED
100 workers: ~1678 QPS / ~35ms latency / MAXED
200 workers: ~1617 QPS / ~76ms latency / MAXED
500 workers: ~3416 QPS / ~78ms latency / MAXED
1000 workers: ~3676 QPS / ~159ms latency / MAXED

Observation: Pool caps at 50 connections correctly; higher concurrency shifts pressure to queueing rather than scaling.

---

### Resources / references
- https://www.swoole.co.uk/docs/get-started/coroutine
- https://www.php.net/manual/en/book.pdo.php
- https://dev.mysql.com/doc/
- https://github.com/swoole/swoole-src
- https://redis.io/docs/latest/

---

## 2026-06-02 — Week 6, Day 2
 
### What I built today
* `Swiftphp\Framework\Database\QueryBuilder\Grammar`: Universal SQL grammar compilation layer for MySQL and PostgreSQL driven by an extensible method-dispatch layout.
* `tests/database/named_connections_test.php`: High-concurrency async benchmarking harness to evaluate named pool routing behavior inside Swoole.
 
### What worked
* Zero Context Contamination: The Swoole connection registry maintained complete container isolation across concurrent execution rings. 1,000 parallel coroutines executed transactional pairs flawlessly with exactly 0 errors.
* Granular Query Breakdown: The lookup map approach successfully decoupled the parser from monolithic structural loops, delegating sub-AST evaluation strictly to dedicated, isolated where-clause methods.
 
### What did not work /
surprises
* Modern MySQL Upsert Warning Trap: Discovered that the traditional `VALUES(col)` macro inside `ON DUPLICATE KEY UPDATE` strings is completely deprecated in modern MySQL 8.0.20+ setups, causing syntax blocks to trigger errors.
* PostgreSQL Strict Identifier Quoting: Double-quoting table and field names inside Postgres triggers a rigid case-sensitive string match requirement. This breaks interoperability with default implicit lowercased database schemas when input strings use mixed-case text.
 
### Decision made
* Implicit Driver-Side Case Folding: Added a `$foldIdentifierCase` property to the compiler constructor that defaults to `true`. When generating PostgreSQL strings, token segments automatically run through `strtolower()` prior to quote wrapping to ensure driver-agnostic portability.
* Immutability of the Binding Sequence: Codified a mandatory `BINDING ORDER CONTRACT` into the framework architecture. The upstream query builder engine is bound to merge and flatten its local state variables in a fixed chronology: `Join -> Where -> Having -> Order` to completely neutralize structural parameters shifting out of alignment.
 
### Benchmark numbers
Metric:       1,251.5 queries/sec (1,000 Coroutines, Transactional P95: 2,199.15ms, Analytics P95: 95.04ms)
vs last week: [Baseline established for modern multi-pool architecture]
 
### Tomorrow
* Construct the main fluent query `Builder` engine to assemble and manage individual clause states, and safely flatten parameter sequences according to the Grammar’s structural contract.
 
### Resources / references
* MySQL Reference Manual: Section 15.2.7.2 - INSERT ... ON DUPLICATE KEY UPDATE Row Aliasing Syntax
* PostgreSQL Documentation: Chapter 4.1.1 - SQL Identifiers and Case-Folding Rules
* Swoole Extension Manual: Advanced Connection Pools & Concurrent Database Routine Management