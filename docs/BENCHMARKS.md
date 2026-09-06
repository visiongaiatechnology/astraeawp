# AstraeaOS WP — Benchmark Status

## Current 0.3.0-alpha status

No end-to-end performance percentage is claimed by this build unless it has been measured in a controlled environment with the same PHP, database, web server, dataset and request path.

Previous documentation that treated primitive PHP operations as proof of complete GeDefense or WordPress request performance is not considered valid evidence.

## Required benchmark classes

### Microbenchmarks
Primitive operations such as array lookup, AEAD operation or standalone hashing. These may characterize a primitive only; they must not be presented as whole-system performance.

### Component benchmarks
Actual production component path, for example Cerberus/Aegis with real configured rules and cache/DB behavior.

### End-to-end benchmarks
Vanilla upstream base and AstraeaOS WP under identical environment and content data.

Required metrics:

- boot/request duration,
- TTFB,
- peak memory,
- SQL count/time,
- P50/P95,
- at least 30 measured post-warmup requests,
- exact environment and dataset.

## Database index claims

A new index must be supported by `EXPLAIN`/`EXPLAIN ANALYZE` evidence before a percentage improvement is documented. This alpha makes no generic `40–75% faster` claim.

## Status

`NOT MEASURED IN THIS BUILD ENVIRONMENT` for end-to-end HTTP/MySQL benchmarks.
