# Phase11C — shell apply-substitution correction

**Date:** 26 November 2026  
**Repository:** `m5ham3ds/RAVEN23-BACKEND`  
**Scope:** OpenTofu validation workflow only; no provider or infrastructure changes.

## Observed failure

During CI on `53e3d096f77aba2ab9f28ed534a52c6adffd7e34`, the guardrail regression suite passed all 13 cases, and OpenTofu `fmt`, `init -backend=false`, `validate`, and local `plan` completed successfully. The job then emitted:

`Error: No configuration files. Apply requires configuration to be present.`

Reviewing the exact workflow shell line showed the summary used backticks around the literal phrase `tofu apply` inside a double-quoted `echo`. Bash treated the backticks as command substitution and attempted to execute OpenTofu apply. OpenTofu refused because there were no configuration files in the current directory. No infrastructure resources were created, changed, or destroyed; nevertheless, the unintended apply invocation was a workflow defect and was removed.

## Correction

- Replaced the unsafe summary text with wording that contains no shell command substitution.
- Strengthened the guard to reject `tofu apply` or `terraform apply` anywhere in the IaC workflow after shell line-continuations are flattened.
- Added two fixture tests for backtick and `$()` command substitution, increasing guardrail coverage from 13 to 15 cases.
- Updated the Phase11C specification and OpenTofu README.

## Required verification

The latest CI for the correction commit must show:
- All 15 guardrail cases passing.
- OpenTofu format, backend-disabled init, validate, and provider-free plan passing.
- No apply-related output or execution.
- Docker, Security, Authentication, and Pint workflows successful on the same commit.

This report does not declare Phase11C complete. Provider-specific adapter modules, state backend/locking implementation, and cost/region decisions still require the owner's explicit hosting approval. No provisioning or deployment is authorized by this correction.
