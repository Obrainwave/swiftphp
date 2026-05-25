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


## 2026-05-11 — Week 3

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