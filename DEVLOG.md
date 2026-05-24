## 2026-05-24 — Week 1, Day 1

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

### Tomorrow
- Investigate `/search` latency spikes (likely causes: string parsing, routing overhead, or blocking logic)
- Profile full request lifecycle (from ingress → handler → response)
- Add deeper benchmarking:
  - P95 / P99 latency tracking
  - memory usage under load
  - CPU saturation points
- Compare performance differences between GET vs POST vs complex query workloads

---

### Resources / references
- https://wiki.swoole.com/
- https://github.com/wg/wrk
- https://github.com/wg/wrk/blob/master/SCRIPTING
- HTTP load testing concepts (latency percentiles, concurrency models)
- Linux networking performance basics (TCP backlog, event loops, syscall overhead)