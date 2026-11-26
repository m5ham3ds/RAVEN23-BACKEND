# RAVEN23 OpenTofu state, locking, encryption and recovery design

**Contract version:** 1  
**Phase:** 11C — design only  
**Provider/backend/KMS selection:** Not approved  
**Status:** Normative pre-provisioning policy; no state backend has been created

## 1. Boundary: three different kinds of data

Do not treat these as one backup problem:

1. **Infrastructure state:** OpenTofu's resource-to-real-object mapping and
   resource attributes. It can contain sensitive values even where outputs are
   marked sensitive.
2. **Application data:** PostgreSQL records and private user media. These need
   their own database backup/export and object-storage recovery procedures.
3. **Release evidence:** immutable OCI image archives/digests, SBOM,
   provenance, and release manifests. They must be retained independently of
   infrastructure state.

A copy of OpenTofu state is not a PostgreSQL backup and does not back up user
media. A database snapshot is not an IaC state backup.

## 2. Logical state isolation

When a backend is approved, create a separate state identity for each
environment and independently authorized infrastructure stack.

The canonical logical identity is:

- Project: raven23-backend
- Environment: development, staging, or production
- Stack: a reviewed infrastructure responsibility boundary
- State identity: project/environment/stack

Rules:

- Staging and production must never share the same state file, state lock,
  encryption key, or write identity.
- Access control must be enforceable per environment and, where appropriate,
  per stack. A workspace name alone is not an authorization boundary unless
  the selected backend demonstrably enforces independent access controls.
- Separate network/foundation, data services, application runtime, and
  observability state only where the dependency graph justifies it. Avoid
  excessive splitting that requires unsafe cross-state reads.
- Do not read another stack's state file directly. Pass only reviewed,
  non-secret outputs through a controlled interface.
- Never store application records, media payloads, database exports, user
  identifiers, or runtime secret values in IaC state intentionally.
- Do not expose raw state, pulled state, saved plans, or state diffs in public
  GitHub artifacts, job summaries, logs, or the repository.

No backend type, bucket, account, region, workspace layout, or state object
prefix is selected in this design. These are provider/backend decisions.

## 3. Backend and locking requirements

The selected backend must provide all of the following:

- Remote state storage with authenticated TLS transport.
- Atomic state writes and native state locking for every operation that can
  write state.
- Per-environment and per-stack authorization, audit events, and the ability
  to deny public/anonymous access.
- Encryption at rest and versioned or recoverable state backups.
- A documented recovery path if a state write fails and OpenTofu leaves a
  recovery copy on the runner filesystem.

Locking is a release gate, not an optional optimization. OpenTofu documents
that state locking is backend-dependent and optional; when supported, it
prevents simultaneous writers. The selected backend must support locking, and
normal runs must not use the -lock=false override. A manual unlock is allowed
only after proving that the process holding the lock is no longer running and
the lock ID matches the failed operation.

Do not run state-changing OpenTofu commands in untrusted pull-request jobs.
The present provider-neutral CI job has no provider, credentials, or remote
backend and must remain so until a separately approved workflow is designed.

## 4. Encryption and key custody

When the backend and key-management design are approved, require layered
protection:

- Backend-side encryption at rest.
- OpenTofu state and saved-plan encryption, enforced so a missing key cannot
  silently produce plaintext state or plans.
- A distinct encryption key for each independent state identity.
- Key permissions separated from general state read access.
- Rotation and recovery procedures, including a tested old-key fallback
  period and documented retirement process.
- Encrypted, access-controlled backups of the encryption keys stored
  separately from the state backend and from the repository.

Important OpenTofu encryption limitations:

- The state and plan become unreadable without their correct key. Loss of the
  key can make the state unrecoverable.
- Encryption does not prevent state corruption or replay of an older state.
  Versioning, backups, serial/lineage checks, and restore tests are still
  required.
- Whoever runs OpenTofu can access values in memory. Keep the trusted operator
  set small and avoid broad state access.
- Key-provider and method identifiers are part of encrypted metadata;
  renaming them requires a documented fallback/rollover procedure.

No passphrase, KMS, HSM, key provider, encryption method, key rotation period,
or secret is configured in this provider-neutral stage.

## 5. Backup and recovery policy

Before the first real state is written:

- Enable versioned, recoverable backend backups.
- Maintain at least one encrypted recovery copy outside the primary state
  failure domain. The copy must have separate access controls.
- Set retention only after the owner approves operational and legal
  requirements; do not invent a retention period or assume free-tier defaults.
- Record checksums, state identity, timestamp, backend version, encryption
  key identifier/version, lineage, and serial for each recovery point.
- Verify recovery of both the state and its encryption keys in an isolated
  location before first production use and after any backend/key migration.
- Keep an auditable record of operator, source recovery point, target state,
  approvals, validation result, and rollback decision.

Recovery runbook:

1. Pause all infrastructure writers and deployment operations for the
   affected environment.
2. Identify the failed operation and prove whether its process is still
   running. Do not force-unlock a live operation.
3. Preserve the current backend version and any local recovery file. Do not
   overwrite or delete either.
4. Select a backup with matching environment/stack identity; verify checksum,
   lineage/serial, encryption key availability, and timestamp.
5. Restore the backup to an isolated recovery target first. Validate that
   OpenTofu can read it and that the configuration and state agree.
6. Produce and review a refresh/plan result without apply. Reconcile drift and
   confirm that the restored state points to the intended infrastructure.
7. Any write-back to the authoritative state requires separate, explicit
   approval, two-person review, and a fresh backup. Never use force to bypass
   lineage/serial safeguards as a normal recovery method.
8. Resume one writer at a time and preserve the recovery evidence.

OpenTofu warns that state push can overwrite remote state and that force-unlock
can permit multiple simultaneous writers if used against an active process.
Those are break-glass operations, not routine automation.

## 6. State migration and provider exit

Before changing backend or provider:

- Freeze writers; capture an encrypted backup and key recovery copy.
- Record state lineage/serial and current resource inventory.
- Confirm destination backend locking, encryption, versioning, access
  separation, and recovery capabilities before moving anything.
- Migrate a non-production state first. Compare resource bindings and produce
  a plan without applying it.
- Do not re-create already-managed resources merely because a provider or
  state location changes. Decide explicitly between state migration and
  import/reconciliation for each resource class.
- Preserve source state read-only until the destination is verified and the
  rollback path is accepted.
- Database and object-storage data migration remains a separate Phase12
  concern; state migration does not move application data.

No state migration, import, remote-state read, apply, provider switch, or
production operation is part of this document.

## 7. Current CI and repository controls

The Phase11C workflow must continue to satisfy:

- Contents read-only permission.
- No provider plugin, credentials, remote backend, state access, saved plan,
  registry publication, or deployment.
- Provider-neutral format, initialization with backend disabled, validation,
  and local output-only plan.
- Guardrail scans all GitHub Actions workflow files for apply invocations
  and rejects provider/resource/module/backend/cloud declarations in the
  current provider-neutral scaffold.
- State, backup, plan, and variable-value files are ignored and rejected if
  tracked by Git.
- Pull-request workflows never receive infrastructure write credentials.

A future backend-specific workflow must be separate, approved, environment
gated, and tested independently. Do not weaken this validation workflow to
make a provider adapter pass.

## 8. Approval gates still open

Explicit owner decisions are required for:

- Provider, account/project, and region.
- Workload, network topology, capacity, availability, RPO/RTO, and budget.
- State backend and locking implementation.
- Key-management product, per-state key layout, rotation and recovery.
- Backup destination, off-provider copy, and retention period.
- Which trusted CI/environment identity may access each state.

Until those decisions are approved, this design remains documentation only.
It does not close Phase11C provider-adapter work or authorize Phase11D staging.
