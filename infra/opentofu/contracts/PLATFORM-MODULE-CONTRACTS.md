# RAVEN23 provider-neutral infrastructure module contracts

**Contract version:** 1  
**Phase:** 11C — design and validation only  
**Provider selection:** Not approved  
**Status:** Normative interface design; not deployable modules

This document defines the stable interface between future infrastructure
modules and the RAVEN23 application runtime. It is deliberately independent of
AWS, Render, Neon, or another provider. It does not create resources or approve
a hosting decision.

## Application facts this contract must preserve

The current repository establishes these runtime boundaries:

- The production image is OCI-compatible and runs FrankenPHP/Caddy as a
  non-root user, exposing port 10000.
- /up is the liveness endpoint; /ready reports database/schema readiness.
  Web startup and liveness do not run migrations or require a successful DB
  connection.
- PostgreSQL is the relational contract. The web process receives the
  restricted application role through DB_URL; a one-off release migration
  task receives DB_MIGRATION_URL. The latter must never be injected into the
  web or queue runtime.
- Private account files use Laravel's filesystem abstraction and
  RAVEN23_PRIVATE_STORAGE_DISK. S3-compatible settings are passed through
  RAVEN23_OBJECT_STORAGE_*; the current local private disk remains the
  non-cloud default.
- Database is the default cache and queue baseline. Redis or provider-specific
  queue/cache services are optional capabilities, not mandatory dependencies.
- Mail defaults to the log transport and authenticated mail is disabled.
  Push uses a disabled transport until a provider adapter and staging
  credentials are explicitly approved.
- Runtime configuration and secrets are injected at runtime; they are not
  baked into the image or the OCI digest.

These facts are reflected in Dockerfile, docker/entrypoint.sh,
config/database.php, config/filesystems.php, .env.example, and the
Phase08/11 constraints.

## Interface rules common to every module

1. Inputs describe intent and capability, not vendor resource names. A module
   adapter may translate them to provider-specific constructs internally.
2. Outputs may expose opaque resource references and connection/secret
   references to trusted infrastructure code. They must never expose
   passwords, private keys, API tokens, or secret values.
3. Application configuration is emitted through the established environment
   contract. Do not add provider SDKs or resource identifiers to Laravel
   domain/services or public API responses.
4. Every module declares its version, required capabilities, destructive
   operations, backup/recovery behavior, and provider dependencies.
5. Modules must be independently reviewable and have explicit inputs/outputs.
   Cross-module coupling is through declared outputs only; never read another
   module's state file directly.
6. Provider-specific implementations live behind these contracts. Switching
   provider should replace adapter modules and runtime values, not rewrite
   API/domain contracts.
7. A provider adapter must be tested against the exact pinned provider release
   and selected CLI compatibility before it is considered supported.

## Module interfaces

### 1. network

**Purpose:** provide the minimum private networking and ingress boundary
required by the selected compute/database/object-storage products.

**Inputs**
- Logical environment name.
- Network capability profile: private workload connectivity, outbound
  egress policy, ingress exposure model, and availability-zone requirement.
- Approved ingress sources and required service-to-service paths.
- DNS/TLS termination ownership and trusted proxy boundary.

**Outputs**
- Opaque network attachment reference(s).
- Private service connectivity references for database and object storage
  where the provider requires explicit attachment.
- Ingress policy reference and stable application hostname handoff.
- Capability report describing private ingress, egress restriction, and
  availability behavior actually provided.

**Invariants**
- Database and private object storage must not be publicly writable.
- Public exposure is limited to the approved API ingress.
- Do not assume every provider has VPC/subnet/security-group primitives;
  expose the capability contract and record any limitation.

### 2. compute

**Purpose:** run the already-built RAVEN23 OCI image without rebuilding it.

**Inputs**
- Immutable image reference in sha256:<64 lowercase hex> form.
- Logical environment and resource profile (CPU/memory/replica intent).
- Network attachment and ingress policy references.
- Port 10000, liveness path /up, readiness path /ready.
- Non-secret runtime environment values and secret-reference names.
- Rollout, graceful shutdown, and restart policy.

**Outputs**
- Opaque service and deployed-revision references.
- Internal service endpoint and stable external hostname handoff.
- Observed running image digest, replica count, and health summary.
- Runtime identity reference used to fetch only the required secrets.

**Invariants**
- Deploy the digest that passed CI and has verified provenance/SBOM.
- Never rebuild the application as part of deployment.
- Run non-root; keep APP_DEBUG=false in production.
- Do not run migrations in web startup.
- Do not expose DB_MIGRATION_URL to the web or queue process.

### 3. database

**Purpose:** provide managed or self-managed PostgreSQL behind the standard
PostgreSQL connection contract.

**Inputs**
- Supported PostgreSQL major version and maintenance policy.
- Storage/performance/availability profile, to be selected from measured
  workload and approved cost.
- Private network attachment, encryption requirement, backup/retention
  profile, and TLS policy.
- Separate application and one-off migration role policies.

**Outputs**
- Database endpoint metadata and TLS CA/certificate reference.
- Secret-manager reference for the restricted application DB_URL.
- Separate secret-manager reference for DB_MIGRATION_URL.
- Database instance reference and backup/restore capability report.

**Invariants**
- Application and migration roles are distinct, non-superuser roles.
- The app role cannot create roles, databases, or administer schema.
- The migration role is available only to an explicit one-off migration
  workload with short-lived access.
- Remote production PostgreSQL requires TLS using the application's accepted
  require, verify-ca, or verify-full policy.
- A snapshot is not accepted as proof of recovery; a restore drill must pass.

### 4. object-storage

**Purpose:** store private user media behind Laravel's filesystem adapter.

**Inputs**
- Private bucket/container intent and logical environment.
- S3-compatible endpoint/API capability requirements.
- Region, encryption, versioning, lifecycle, retention, and access policy.
- Private network attachment where supported.
- Access policy for application runtime and separate backup process.

**Outputs**
- Opaque bucket/container reference.
- Endpoint, region, bucket name, and path-style capability metadata.
- Secret-manager reference for runtime object-storage credentials or
  workload identity binding.
- Report of tested S3 API capabilities and limits.

**Invariants**
- No public-read bucket or anonymous write access.
- The Laravel private disk remains the only application-facing abstraction.
- Test PUT/GET/LIST/DELETE, metadata/content type, private visibility,
  presigned URL behavior, multipart, versioning, lifecycle, and large-object
  migration against the exact selected product before calling it compatible.
- Keep user-media recovery/export separate from IaC state backups.

### 5. secrets

**Purpose:** bind runtime identities to selected secret-manager references.

**Inputs**
- Logical environment and secret names/rotation classes.
- Allowed workload identity references and least-privilege access policy.
- Key-management and audit requirements.

**Outputs**
- Secret references only (never secret values).
- Rotation policy metadata and audit/event destination reference.
- Identity-to-secret access map.

**Invariants**
- No values in Git, Terraform/OpenTofu state outputs, build args, Docker image,
  CI artifacts, or logs.
- Runtime, migration, and deployment identities are separate.
- A deployment identity is short-lived and does not inherit database runtime
  privileges.
- APP_KEY, DB_URL, DB_MIGRATION_URL, OAuth, mail, push, and object
  storage secrets are injected only into the specific workload that requires
  them.

### 6. monitoring

**Purpose:** collect operational telemetry without creating a provider lock.

**Inputs**
- Structured stdout/stderr log contract and redaction rules.
- Metrics, alert, retention, and availability objectives.
- Approved notification destination and access policy.

**Outputs**
- Log/metric/trace destination references.
- Alert-policy references and retention/cost capability report.
- Health signal mapping for liveness/readiness and background worker status.

**Invariants**
- Redact credentials, tokens, database URLs, private media URLs, and user
  identifiers from logs/metrics.
- Never treat log delivery as proof of alert delivery; test notifications
  separately in staging.
- Avoid creating a telemetry service before ingest volume/retention/cost is
  estimated and approved.

### 7. registry

**Purpose:** retain and distribute signed immutable OCI artifacts.

**Inputs**
- OCI image digest already built and tested by CI.
- Provenance/SBOM verification requirements.
- Retention, replication, access, and pull identity policies.

**Outputs**
- Immutable registry digest reference.
- Registry repository reference and retention/replication capability report.
- Verification result tying source SHA, workflow identity, provenance, SBOM,
  and image digest together.

**Invariants**
- No mutable tag is accepted as deployment identity.
- No rebuild between CI, staging, and production.
- Registry publication is a separately approved operation, not part of the
  current provider-neutral validation workflow.

## Optional capabilities — not base modules

Cache and queue providers remain optional. The current Laravel database
drivers are the baseline. Redis, SQS, DynamoDB, or another provider-specific
service may be proposed only with workload evidence, compatibility tests,
cost comparison, and a reversible adapter boundary.

Email and push transports also remain outside the infrastructure base
contract until Phase04/08 real staging acceptance and user approval.

## Required adapter acceptance evidence

Before any provider adapter is accepted, attach:

- Exact provider name, product/SKU, version, region, and date.
- A provider-to-contract mapping for every input/output above, including
  unsupported capabilities.
- Provider plugin version pinned in tofu.lock.hcl after initialization.
- Format, validate, policy tests, and a reviewed non-applied plan.
- Security review for public exposure, encryption, IAM/workload identity,
  secret injection, audit logs, and backups.
- Exit/migration exercise covering database export/restore, private objects,
  registry digest, DNS/TLS handoff, and rollback.

This contract is design-only. It does not authorize provider selection,
resources, credentials, remote state, registry publication, apply, or
deployment.
