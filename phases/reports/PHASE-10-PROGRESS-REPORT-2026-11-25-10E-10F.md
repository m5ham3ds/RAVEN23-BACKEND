# Phase10E closeout and Phase10F start — 25 November 2026

## 10E — synthetic PostgreSQL load baseline COMPLETE

**Verified SHA:** `f912dd2479a5f86471e1e8999b2d36c7611d41cb`

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45504724906](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45504724906) | SUCCESS — SQLite Feature 150/1,704; PostgreSQL Integration 31/546 with one optional skip; S3Mock 1/8; runtime guards PASS |
| Authentication Regression | [45504724789](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45504724789) | SUCCESS |
| Pint Format | [45504724822](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45504724822) | SUCCESS |
| Security Scans | [45504724860](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45504724860) | SUCCESS — Trivy image Clean and fixable HIGH/CRITICAL gate PASS |

### Final synthetic concurrency metrics

The PostgreSQL test launches eight independent PHP workers with separate database connections and a shared start barrier.

| Metric | Result |
|---|---:|
| Concurrent workers | 8 |
| Successful requests | 8 / 8 |
| Wall-clock concurrency window | 188.248 ms |
| Throughput | 42.497 requests/sec |
| Aggregate request bytes | 4,191 |
| Aggregate response bytes | 546 |
| P50 request latency | 143.150 ms |
| P95 request latency | 177.715 ms |
| Average request latency | 143.856 ms |
| Maximum request latency | 177.715 ms |
| Stale conflict writes | 5 / 8 (62.5%) |
| Winning timestamp | 5000 |

- P50/P95 use nearest-rank.
- PostgreSQL suite: 31 passed / 546 assertions, one optional skip.
- All four CI gates completed successfully on the same SHA.
- Small synthetic CI sample only; not production capacity, traffic distribution or SLO.

## 10F — immutable tested-image evidence, SBOM and provenance IN_PROGRESS

The Docker workflow now packages evidence for the exact tested local image without publishing to a registry.

- Assign a local SHA-specific tag and verify embedded source commit.
- Record Docker config digest and SHA-256 of the exported tested Docker archive.
- Generate CycloneDX SBOM using Trivy against that same local image.
- Generate provenance linking source/run, Dockerfile and Composer input hashes, pinned runtime base digest, image config digest, archive checksum and SBOM checksum.
- Use `actions/attest@v4` for signed SLSA provenance and SBOM attestations; preserve Sigstore bundles.
- Upload image archive, SBOM, provenance, checksums and bundles as a SHA-specific artifact.
- Permissions: contents read, id-token write, attestations write and artifact-metadata write; no packages:write and no registry push.

### Digest semantics and limitations

- Docker image config digest from local `docker image inspect .Id` is not an OCI registry manifest digest.
- Docker archive SHA-256 identifies the exact exported artifact bound to the attestations.
- No registry manifest digest is claimed because this workflow does not push an image.
- 10F acceptance awaits all four gates, artifact upload, checksum verification and both attestation outputs.

## Remaining 10B governance dependency

- `main` remains unprotected with no rulesets observed.
- Required-check enforcement requires repository Admin settings access not exposed by the current connection.