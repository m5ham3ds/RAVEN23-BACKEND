#!/usr/bin/env bash
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
iac_root="$repo_root/infra/opentofu"
workflow="$repo_root/.github/workflows/iac-validation.yml"

fail() {
  echo "::error::$1"
  exit 1
}

# Provider-specific declarations and local modules may create real resources.
# They remain blocked until the owner approves the provider and scope.
if grep -REn --include='*.tf' '^[[:space:]]*(provider|resource|data|module)[[:space:]]+"' "$iac_root"; then
  fail "Provider, resource, data, and module blocks are not allowed in the provider-neutral Phase11C scaffold."
fi

# Both legacy backend blocks and HCP Terraform/OpenTofu cloud blocks can move
# state or execution outside the local runner, so both require owner approval.
if grep -REn --include='*.tf' '^[[:space:]]*(backend[[:space:]]+"[^"]+"|cloud)[[:space:]]*\{' "$iac_root"; then
  fail "A remote state backend or cloud execution block must not be selected before owner approval."
fi

# State, backup, plan, and variable-value files must never be committed.
if git -C "$repo_root" ls-files | grep -E '(^|/)(.*\.tfstate.*|.*\.tfplan|.*\.plan|.*\.tfvars|.*\.tfvars\.json)$'; then
  fail "OpenTofu/Terraform state, backup, plan, or variable-value files must not be committed."
fi

# Flatten shell continuations so a split command cannot bypass this check.
workflow_commands="$(sed ':a;N;$!ba;s/\\\n[[:space:]]*/ /g' "$workflow")"
if grep -En '^[[:space:]]*(-[[:space:]]*)?(run:[[:space:]]*)?(tofu|terraform)([[:space:]]|$).*([[:space:]]|^)apply([[:space:]]|$)' <<< "$workflow_commands"; then
  fail "Infrastructure CI must never run tofu/terraform apply."
fi

echo "PASS: provider-neutral IaC guardrails (no provider/resources/modules/backend/cloud/state/plan/tfvars/apply)."
