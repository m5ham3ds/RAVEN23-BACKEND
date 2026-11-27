#!/usr/bin/env bash
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
iac_root="$repo_root/infra/opentofu"
workflow_dir="$repo_root/.github/workflows"

fail() {
  echo "::error::$1"
  exit 1
}

# OpenTofu loads native .tf/.tofu and JSON .tf.json/.tofu.json files.
# Scan every supported configuration extension; .tofu takes precedence over
# a same-named .tf file, so checking only .tf would leave an approval bypass.
while IFS= read -r -d '' config_file; do
  case "$config_file" in
    *.tf|*.tofu)
      if grep -En '^[[:space:]]*(provider|resource|data|module)[[:space:]]+"' "$config_file"; then
        fail "Provider, resource, data, and module blocks are not allowed in the provider-neutral Phase11C scaffold: $config_file"
      fi

      if grep -En '^[[:space:]]*(backend[[:space:]]+"[^"]+"|cloud)[[:space:]]*\{|^[[:space:]]*required_providers[[:space:]]*\{' "$config_file"; then
        fail "A provider requirement, remote state backend, or cloud execution block must not be selected before owner approval: $config_file"
      fi
      ;;
    *.tf.json|*.tofu.json)
      if ! python3 - "$config_file" <<'PY'
import json
import sys

path = sys.argv[1]
try:
    with open(path, encoding="utf-8") as source:
        config = json.load(source)
except (OSError, json.JSONDecodeError) as exc:
    print(f"Invalid OpenTofu JSON configuration {path}: {exc}", file=sys.stderr)
    sys.exit(2)

blocked_top_level = {"provider", "resource", "data", "module"}
found = sorted(blocked_top_level.intersection(config) if isinstance(config, dict) else [])
terraform = config.get("terraform", {}) if isinstance(config, dict) else {}
if isinstance(terraform, dict):
    found.extend(sorted({"backend", "cloud", "required_providers"}.intersection(terraform)))

if found:
    print(f"Forbidden OpenTofu JSON blocks in {path}: {', '.join(found)}", file=sys.stderr)
    sys.exit(1)
PY
      then
        fail "Provider, resource, data, module, backend, cloud, and provider requirements are not allowed in the provider-neutral JSON configuration: $config_file"
      fi
      ;;
  esac
done < <(find "$iac_root" -type f \( -name '*.tf' -o -name '*.tofu' -o -name '*.tf.json' -o -name '*.tofu.json' \) -print0 | sort -z)

# State, backup, plan, and variable-value files must never be committed.
if git -C "$repo_root" ls-files | grep -E '(^|/)(.*\.tfstate.*|.*\.tfplan|.*\.plan|.*\.tfvars|.*\.tfvars\.json|\.terraform\.lock\.hcl)$'; then
  fail "OpenTofu/Terraform provider lock, state, backup, plan, or variable-value files must not be committed."
fi

# Inspect every GitHub Actions workflow, not only this validator. Flatten
# shell continuations and permit common CLI flags such as -chdir before apply.
while IFS= read -r -d '' workflow_file; do
  workflow_commands="$(sed ':a;N;$!ba;s/\\\n[[:space:]]*/ /g' "$workflow_file")"
  if grep -En '(tofu|terraform)([[:space:]]+[^[:space:]]+)*[[:space:]]+apply([[:space:]]|$)' <<< "$workflow_commands"; then
    fail "GitHub Actions workflows must not contain an apply invocation: $workflow_file"
  fi
done < <(find "$workflow_dir" -type f \( -name '*.yml' -o -name '*.yaml' \) -print0 | sort -z)

echo "PASS: provider-neutral IaC guardrails (no provider/resources/modules/backend/cloud/state/plan/tfvars/apply)."
