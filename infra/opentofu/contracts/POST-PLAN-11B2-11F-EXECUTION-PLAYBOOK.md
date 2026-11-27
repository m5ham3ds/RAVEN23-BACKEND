# RAVEN23 Phase11 post-plan execution playbook (11B2–11F)

**Status:** provider-neutral readiness document only  
**Activation:** only after the complete Backend development plan reaches the D-051 decision point  
**Approval:** every provider/resource/deployment action remains separately gated  
**Scope:** 11B2 trust enforcement, 11C provider adapter acceptance, 11D staging provisioning, 11E staging acceptance, and 11F production handoff.

This playbook converts the Phase11 design into one ordered execution sequence. It intentionally contains no vendor name, SKU, region, credential, account ID, resource size, price, DNS name or environment secret. Those values must be supplied and approved after D-051 is reopened.

## 0. Global no-go conditions

Stop before creating or changing infrastructure whenever any of the following is true:

- D-051 has not been reopened after the full Backend development plan, or the owner has not approved the provider, product/SKU, account/project, region and cost envelope.
- Phase10B manual main-branch protection and required checks have not been applied and verified by the repository owner.
- The exact workload, data growth, retention, availability, RPO/RTO and monthly budget assumptions are absent.
- The provider adapter has not passed format, validation, policy tests and reviewed non-applied plan.
- Staging data or secrets would be copied from production without a separately approved data handling plan.
- A requested action would touch production database, user data, DNS, paid capacity or production deployment without its own explicit approval.

No step in this document authorizes apply, registry publication, paid resources, DNS changes, production writes or cutover by itself.

## 1. Entry packet — owner-approved decision record

Before 11B2–11D, create one decision record containing every item below. Unknown values remain OPEN; do not substitute guesses or Free Tier assumptions.

| Input | Required evidence |
|---|---|
| Provider and product | Exact provider, service/product/SKU, service limits and support terms |
| Account/project | Owner-confirmed account/project and ownership boundary |
| Region | Approved region and user/data residency constraints |
| Cost | Monthly recurring estimate, post-credit price, egress, backup, registry and observability charges; owner-approved cap |
| Workload | Requests/day, peak RPS, concurrent users, replica count and CPU/RAM profile |
| Database | Current size, growth, connections, throughput/IOPS, HA target and extension compatibility |
| Object storage | Object count/bytes, request classes, multipart, metadata, lifecycle, versioning and egress assumptions |
| Recovery | Retention, off-provider copy, RPO/RTO and restore rehearsal target |
| Operators | Named approvers, incident owner, support window and break-glass procedure |
| Change approval | Exact scope, cost cap, environment, intended action, rollback and expiry of approval |

Only the owner may approve the provider and cost choices. The final comparison must use the same workload and recovery assumptions for each candidate.

## 2. 11B2 — enforce repository and deployment trust

### 2.1 GitHub repository controls (owner/admin action)

- Keep main as the only permanent branch. Do not create long-lived development branches.
- Configure required checks for Docker Validation, Authentication Regression and Security Scans on the exact expected workflow/job identities. Pint report remains advisory when no PHP files changed; strict Pint inside Docker is the formatting gate.
- Require the repository's approved merge policy, block force pushes and branch deletion, and restrict bypass permissions to explicitly approved administrators.
- Re-run a harmless validation change and prove the checks are required before merge. Record screenshots/API evidence, actor, time and effective ruleset.
- This step is manual and remains deferred under Phase10B until the user-authorized time.

### 2.2 Environment and identity controls

- Create distinct staging and production environments only after the owner approves the provider and deployment model.
- Require staging approval where appropriate and independent production reviewers; restrict each environment to the approved source ref and workflow.
- Separate CI builder, attestor, registry publisher, staging deployer, production deployer, migration runner and runtime application identities.
- Use short-lived OIDC/workload identity where supported. Pin trust to the repository identity, owner identity, workflow identity, protected main ref, event type, environment, audience and source SHA.
- Staging and production must use different audiences/subjects and cannot reuse each other's credentials. No pull_request event or untrusted fork may obtain deployment identity.
- Test both positive and negative claims: the approved main workflow is accepted; pull request, fork, wrong repository, wrong workflow, wrong ref, wrong environment and wrong audience are denied.
- Store no static provider access key in source control or workflow output. Secret values must never enter logs or artifacts.

### 2.3 Trust evidence

Attach the active repository ruleset/branch protection evidence, environment restrictions, trust policy, negative-claim test results, identity permission matrix and an audit showing that each identity can perform only its declared action.

## 3. 11C — provider adapter acceptance

After the entry packet is approved:

1. Verify the exact provider release, product feature set, CLI compatibility, support lifecycle and licence. Pin provider versions and preserve the reviewed .terraform.lock.hcl; do not regenerate it silently.
2. Implement adapter modules behind the existing provider-neutral contracts: network, compute, database, object-storage, secrets, monitoring and registry.
3. Keep provider resource names, SDKs and identifiers out of Laravel domain/API code. Application configuration must continue to use the established runtime environment contract.
4. Separate states by environment and stack. Enforce backend locking, authenticated TLS, encryption, versioned backups, per-state key custody and recovery procedures.
5. Make databases and private object storage non-public. Use distinct restricted application and one-off migration roles. Never inject DB_MIGRATION_URL into the web or queue runtime.
6. Keep the exact image digest immutable. A provider adapter must not rebuild the tested release or replace digest identity with an archive checksum.
7. Run fmt, init, validate, policy tests and a reviewed plan with no apply. Review public exposure, ingress/egress, encryption, identity, audit, backup, cost and destructive replacements.
8. Attach the adapter-to-contract mapping, unsupported capabilities, pinned lock file, plan review, security evidence and exit/migration exercise.

Provider-specific configuration remains forbidden before this point. A passing local provider-free plan does not approve provider resources or an apply.

## 4. 11D — isolated staging provisioning

Before provisioning, obtain a separate explicit staging change approval that names the approved account/project, region, resource profile, monthly cap, expiry and rollback owner.

Deployment order:

1. Create only the approved staging network and ingress boundary; expose only the API endpoint and keep database and private storage non-public.
2. Provision the approved staging PostgreSQL service and private object storage with encryption, access separation and audit enabled.
3. Create separate application and migration database roles; store their credentials in the approved secret manager.
4. Create queue/cache and mail/push integrations only when their Phase04/08 contracts and approvals permit them. Keep disabled capabilities disabled.
5. Publish the already-tested image to the approved OCI registry and verify the registry manifest digest and attestations. Do not rebuild.
6. Deploy the same digest to staging using the short-lived staging identity. Run schema migrations only through the one-off migration identity.
7. Verify runtime health, readiness, TLS, private ingress, secret injection and log redaction before accepting traffic.
8. Record resource inventory, costs, service limits, image digest, deployment identity and rollback target.

Never connect staging to the production database or copy production personal data as a shortcut.

## 5. 11E — staging acceptance matrix

Every row must have an executable test, stored evidence and an explicit PASS/FAIL. A mock or local loopback rehearsal does not satisfy a real provider acceptance row.

| Area | Required staging acceptance | Evidence |
|---|---|---|
| Source and image | Repository/SHA/workflow claims match signed provenance, SBOM and registry manifest digest | Attestation verification output and digest record |
| Network | API ingress only; database and object storage private; TLS and trusted proxy boundary correct | Network policy and external/internal probes |
| Identity | Staging identity short-lived and staging-only; migration/runtime identities separate | Positive and negative OIDC tests; DB permission probes |
| Database | Migration succeeds once; application role cannot administer DB; connection/TLS limits as approved | Migration log, grants report and connection tests |
| Objects | Private put/get/list/delete, metadata, multipart, presigned policy, lifecycle/versioning and large-object copy as supported | Real bucket acceptance results and object policy report |
| Queue/cache | Retry, idempotency, dead-letter, drain/replay and lock semantics meet the application contract | Worker/outbox evidence and queue metrics |
| Email/Push | Only approved Phase04/08 provider tests; default disabled behavior remains intact | Staging delivery receipt, bounce/token behavior and kill-switch test |
| API/auth/sync | Auth lifecycle, contract smoke, device isolation and sync workflows pass against staging services | Redacted test report and API contract results |
| Observability | Structured logs, redaction, metrics, alerts, retention and delivery route work | Alert drill and sample dashboards/log evidence |
| Backup/restore | Encrypted backup restored into a separate isolated target; checksums and schema/data verification pass | Restore report, checksums and measured duration |
| Resilience | Restart, health/readiness, dependency failure and recovery behavior meet agreed SLO/RPO/RTO | Fault-injection and recovery report |
| Rollback | Traffic switches to a known-good digest; database compatibility and object changes are accounted for | Before/after digest, route test and rollback record |
| Cost | Actual staging spend remains within approved cap and alert threshold | Billing snapshot and budget alert test |

11E may close only after every required row is PASS or an explicitly owner-accepted exception with scope and expiry is recorded. Do not call a skipped provider capability a pass.

## 6. 11F — production handoff

11F is a handoff decision, not permission to migrate user data. Before any production deployment:

- Phase10B required checks and environment approvals are active and verified.
- 11D staging and 11E staging acceptance are closed with evidence on the exact digest proposed for production.
- Phase12 source/destination, encrypted backup/restore, write freeze/dual-write policy, data reconciliation, cutover window and rollback criteria are approved separately.
- Phase13 monitoring, backup schedule, incident response, on-call ownership, capacity limits, cost alerts, DR and RPO/RTO procedures are ready.
- The owner signs a production change approval specifying exact account/project, region, digest, migration scope, cost cap, start/end time, rollback authority and user impact.
- Production identity is distinct, short-lived, protected by production environment approval and cannot be obtained by staging workflows.
- The production image is the exact staging-accepted digest. No rebuild, mutable tag substitution or unreviewed configuration drift.
- DNS or traffic cutover occurs only under its own approval and with a tested rollback path.

After release, verify health, readiness, auth/API smoke, database migration status, storage access, error rates, latency, queue lag, alert delivery and cost telemetry. Record the handoff owner and rollback decision.

## 7. Evidence bundle required for each gate

Use one immutable evidence folder or release artifact keyed by repository, commit SHA, environment and deployment attempt. Include:

- Owner decision and change approval records.
- Ruleset/branch protection and environment evidence.
- Provider and adapter versions, reviewed .terraform.lock.hcl, policy results and non-applied plan.
- Identity/trust policy and positive/negative claim test outputs.
- Image digest, registry manifest, provenance, SBOM and signature verification.
- Redacted migration, database grants, object policy, health, smoke, backup/restore, resilience and rollback results.
- Cost snapshot, spend alerts, RPO/RTO measurements, operator and incident handoff.

Never attach plaintext secrets, raw state, production data exports, unencrypted plans or personal user data to a public artifact.

## 8. Current execution boundary

This playbook is preparation only. It does not change D-050/D-051, does not choose a provider or region, does not create a GitHub Environment or cloud identity, and does not provision staging or production. The provider-neutral CI evidence at 74f57a0 covers the local contract and test scaffolding only.

Once the complete Backend plan reaches the D-051 decision point and the owner explicitly approves the provider/cost packet, execute Sections 2–6 in sequence without marking skipped external acceptance as complete.