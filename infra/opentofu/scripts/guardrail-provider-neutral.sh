#!/usr/bin/env bash
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
iac_root="$repo_root/infra/opentofu"
workflow="$repo_root/.github/workflows/iac-validation.yml"

fail() {
  echo "::error::$1"
  exit 1
}

# Provider-specific declarations and remote backends are forbidden until
# the owner approves the hosting provider and state architecture.
if grep -REn --include='*.tf' '^[[:space:]]*(provider|resource|data)[[:space:]]+"' "$iac_root"; then
  fail "Provider, resource, and data blocks are not allowed in the provider-neutral Phase11C scaffold."
fi

if grep -REn --include='*.tf' '^[[:space:]]*backend[[:space:]]+"' "$iac_root"; then
  fail "A remote state backend must not be selected before owner approval."
fi

# State, plan, and variable files must never be committed.
if git -C "$repo_root" ls-files | grep -E '(^|/)(terraform\.tfstate|.*\.tfstate(\.backup)?|.*\.tfplan|.*\.plan|.*\.tfvars)$'; then
  fail "Terraform/OpenTofu state, plan, or variable files must not be committed."
fi

# CI must remain a read-only validator; reject an accidental apply command.
if grep -En '(^|[[:space:]])(tofu|terraform)[[:space:]]+apply([[:space:]]|$)' "$workflow"; then
  fail "Infrastructure CI must never run tofu/terraform apply."
fi

echo "PASS: provider-neutral IaC guardrails (no provider/resources/backend/state/plan/secrets/apply)."
