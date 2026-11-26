# Phase11C — approval guardrail regression tests

**Date:** 26 November 2026  
**Parent SHA:** `e0fbb2a24fce29f2e5cbeb464af63c38910b0d05`  
**Status:** Implementation and CI validation pending for this commit.

## Why this increment

The initial provider-neutral OpenTofu scaffold passed its full CI matrix. The approval boundary was enforced by a shell guard, but the guard itself had no negative regression tests. This increment makes its refusal behavior executable and repeatable before any provider-specific adapter is introduced.

## Added coverage

A fixture-based Bash test creates temporary Git repositories and checks both the allowed baseline and forbidden changes:

1. Baseline provider-free HCL is accepted.
2. Provider, resource, data, and module blocks are rejected.
3. Remote `backend` and `cloud` blocks are rejected.
4. Tracked state, state backup, saved plan, and `tfvars.json` files are rejected.
5. Inline and line-continuation `tofu apply` commands in CI workflow are rejected.

All fixtures are synthetic and temporary. No credentials, state backend, cloud account, real resources, or deploy actions are involved.

## CI

The new regression suite runs before HCL format/validate/plan in the existing read-only OpenTofu workflow. Results must be read from the CI run for the implementation SHA before claiming this increment is accepted.
