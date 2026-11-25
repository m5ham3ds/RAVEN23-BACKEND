# Phase 10F closeout / Phase 10G implementation — 25 November 2026

## 10F — completed

Verified source commit: \`c01a4bad1e4007dd8c9cb46bb56d5344f188f06c\`.

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45513822710](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45513822710) | SUCCESS — SQLite Feature 150/1,704; PostgreSQL Integration 31/546 with one optional skip; S3Mock 1/8; runtime guards PASS |
| Authentication Regression | [45513822753](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45513822753) | SUCCESS — SQLite 20/172; PostgreSQL 4/99 |
| Pint Format | [45513822755](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45513822755) | SUCCESS |
| Security Scans | [45513822742](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45513822742) | SUCCESS — Gitleaks/source scans PASS; Trivy production image Clean; fixable HIGH/CRITICAL gate PASS |

Docker workflow evidence steps all completed:
- Package the exact tested image with SHA-specific tag, Docker config digest, archive SHA-256, CycloneDX SBOM and build provenance.
- Verify archive/SBOM SHA-256 from the release-evidence directory.
- Generate signed provenance attestation [#71890026](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/71890026) and SBOM attestation [#71890037](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/71890037).
- Upload artifact [#14975768921](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45513822710/artifacts/14975768921), size 276,676,355 bytes, digest \`sha256:f50b53dd43a3c9f261d5da512adf9a005a5e45553870a9447533ecc931b193ed\`, 30-day retention.

First 10F attempt on \`ef98f39\` failed because sha256sum used relative paths from repository root. Running checksum validation inside \`release-evidence\` fixed it; all checks passed on \`c01a4bad\`.

## 10G — implementation started

Rollback baseline: \`f912dd2479a5f86471e1e8999b2d36c7611d41cb\`, the previous commit with all four CI gates green.

CI additions:
1. Rebuild the pinned rollback tree with \`git archive\`, without creating a branch.
2. Verify current/rollback source identities and Docker config digests.
3. Save the previous image archive, generate rollback CycloneDX SBOM and record build inputs.
4. Run both images as disposable non-root containers. Liveness must return 200 and readiness 503 without a database.
5. Route through a loopback-only proxy. Verify current route 200, stopping it produces 502, then atomically switch to the previous image and verify 200 from the rollback backend.
6. Attest rollback archive provenance and SBOM. Build a manifest linking commits, image digests, archive/SBOM hashes, prior CI proof, attestation IDs/URLs and rehearsal.
7. Sign the release manifest, verify SHA256SUMS for every evidence file, and upload the release bundle.

10G remains IN_PROGRESS until all four CI gates, traffic switch, attestations, checksums and release artifact succeed. This isolated proxy/container test is not proof of production deployment rollback or cloud load balancer behavior.

No production DB, secrets, deployment, registry push or AWS resources were accessed.


## First 10G CI attempt — failure diagnosis and targeted repair

Tested commit: \`018edc6b7e5a17d2146982b536743152a6ae440f\`.

- Authentication #45516843920, Pint #45516844066 and Security #45516843974 passed. Trivy's production image scan was Clean.
- Docker #45516843876 passed the normal application, SQLite, PostgreSQL, S3Mock, runtime and restricted database-role checks. It also rebuilt the pinned old release from commit \`f912dd2479a5f86471e1e8999b2d36c7611d41cb\` and saved its Docker archive.
- The rollback script then stopped during \`trivy image --format cyclonedx\`. The only output after starting the scan identified Debian 13.8 and two language package files; the process exited 1 without a detailed diagnostic. Container startup and traffic switching had not begun.
- Targeted change: use Anchore Syft v1.57.1 to create the rollback CycloneDX SBOM from the exact Docker archive with the \`docker-archive:\` source; validate the JSON format and a non-empty components array before recording SHA-256.
- Rollback provenance and final release manifest now identify the SBOM generator and component count. Trivy's independent security scanning is unchanged and remains blocking.
- 10G remains IN_PROGRESS until CI validates the Syft output, 200→502→200 loopback routing, four rollback/release attestations, signed manifest, checksums and artifact upload.

No production DB/secrets, deployment, registry push or AWS resources.
