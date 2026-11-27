# RAVEN23 provider-neutral OpenTofu contract

## Current boundary

This directory is the first Phase11C IaC slice. OpenTofu CLI is pinned in
`.opentofu-version`; the checked-in configuration defines only the platform
contract and logical deployment inputs.

**No cloud provider has been selected.** Provider plugins, provider-specific
resources, remote state backends, credentials, environments, and deployment
workflows are intentionally absent. Do not add them until the owner approves
the provider, account/project, region, and cost envelope.

The contract mirrors the application boundary already present in the repository:

- Compute: the existing OCI image, FrankenPHP/Caddy runtime, and non-root process.
- Database: PostgreSQL with separate restricted application and one-off migration roles.
- Objects: private S3-compatible storage behind the application storage contract.
- Cache and queue: Laravel abstractions; provider-specific services stay optional.
- Delivery: build once and promote the same immutable OCI image digest.
- Identity: short-lived deployment identity, distinct from runtime and migration identities.

The declared module boundaries for a future provider adapter are
`network`, `compute`, `database`, `object-storage`, `secrets`,
`monitoring`, and `registry`. They are design boundaries, not deployable
modules yet. Provider-specific modules must implement these contracts without
leaking provider SDKs or resource names into Laravel application/domain code.

## Tool decision

OpenTofu is selected for the provider-neutral scaffold and CI validation
because it provides a separately versioned CLI, provider plugins, and
provider-registry protocol while keeping provider choice outside this
repository baseline. The project pins the tested CLI version rather than
tracking `latest`.

This is not a claim that every Terraform provider or module is interchangeable.
Before adding a provider adapter, verify that the exact provider release,
resource/data-source behavior, required CLI version, and licensing/support
terms work with the approved hosting choice. Preserve the lock file once
provider dependencies are introduced.

## Safe local validation

From the repository root:

```sh
tofu -chdir=infra/opentofu fmt -check -recursive
tofu -chdir=infra/opentofu init -backend=false -input=false
tofu -chdir=infra/opentofu validate -no-color
tofu -chdir=infra/opentofu plan -refresh=false -input=false -lock=false -no-color
```

The plan is deliberately provider-free and contains no cloud resource changes.
The CI workflow uses read-only repository permissions and performs no
`apply`, backend initialization, registry publication, or deployment.

## State, locking, recovery, and secrets

- No remote backend or state location is selected in this slice.
- Never commit `*.tfstate`, backups, saved plans, `*.tfvars`, provider
  credentials, or state-encryption keys. The root `.gitignore` excludes these.
- When a backend is approved, isolate state by environment and security
  boundary; enable backend locking and versioned/recoverable backups.
- Require encryption at rest for state and saved plans. OpenTofu client-side
  state/plan encryption may be evaluated with the selected key-management
  system; keep keys outside the repository and state backend, use distinct
  keys per state, and rehearse recovery before enabling encryption.
- State and plan files can contain sensitive values. Restrict readers and CI
  artifact access; do not expose raw state or plan output as public artifacts.
- Encryption does not replace backups or protect against loss/replay; a lost
  encryption key can make encrypted state unrecoverable.

## Approval gates not satisfied by this scaffold

The following remain blocked pending explicit owner decisions: provider,
account/project, region, monthly budget, workload and capacity assumptions,
remote-state backend, key-management system, network topology, resource sizes,
backup retention/RPO/RTO, registry, and any paid or production resource.
This scaffold does not close Phase11A cost scoring, 11B2 trust enforcement,
11D staging provisioning, 11E operational acceptance, or 11F production handoff.

## Provider-neutral module and state contracts

The architecture contracts are maintained separately from deployable HCL:

- [Platform module contracts](contracts/PLATFORM-MODULE-CONTRACTS.md) define
  inputs, outputs, invariants, and acceptance evidence for network, compute,
  PostgreSQL, private object storage, secrets, monitoring, and registry.
- [State and recovery design](contracts/STATE-AND-RECOVERY-DESIGN.md) defines
  environment/stack isolation, backend locking, encryption/key custody,
  versioned backups, restore verification, and provider-exit rules.

These are design documents only. The provider, region, remote state backend,
key manager, retention period, and paid resources are not selected. Do not
create modules that declare provider resources until approval is recorded.

Official OpenTofu references used for the state design:
- https://opentofu.org/docs/language/state/backends/
- https://opentofu.org/docs/language/state/locking/
- https://opentofu.org/docs/language/state/encryption/
- https://opentofu.org/docs/language/state/sensitive-data/

## Approval-guard regression tests

Run from the repository root:

```sh
bash infra/opentofu/tests/test-guardrail-provider-neutral.sh
```

The fixture-based test suite proves the guard accepts the provider-free baseline
and rejects provider/resource/data/module/required-provider declarations,
remote backend/cloud blocks, tracked state/backups/plans/tfvars, and inline,
continued, backtick, `$()`, CLI-flag, Terraform, or separate-workflow apply
invocations. The guard scans all native `.tf` and `.tofu` files plus JSON
`.tf.json` and `.tofu.json` configurations, and every `.yml`/`.yaml` workflow.
It uses temporary Git repositories and synthetic files only; it does
not load credentials, contact a provider, or create resources.
