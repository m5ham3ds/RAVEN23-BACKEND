#!/usr/bin/env bash
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
guard_source="$repo_root/infra/opentofu/scripts/guardrail-provider-neutral.sh"
workflow_source="$repo_root/.github/workflows/iac-validation.yml"
tmp_root="$(mktemp -d)"
trap 'rm -rf "$tmp_root"' EXIT

make_fixture() {
  local fixture="$1"
  mkdir -p "$fixture/infra/opentofu/scripts" "$fixture/.github/workflows"
  cp "$guard_source" "$fixture/infra/opentofu/scripts/guardrail-provider-neutral.sh"
  cp "$workflow_source" "$fixture/.github/workflows/iac-validation.yml"
  cp "$repo_root/.gitignore" "$fixture/.gitignore"
  cat > "$fixture/infra/opentofu/main.tf" <<'HCL'
terraform {
  required_version = ">= 1.6.0"
}
HCL
  git -C "$fixture" init -q
  git -C "$fixture" config user.email "iac-guardrail-test@example.invalid"
  git -C "$fixture" config user.name "IaC Guardrail Test"
  git -C "$fixture" add .
  git -C "$fixture" commit -qm "fixture baseline"
}

assert_pass() {
  local name="$1"
  local fixture="$tmp_root/$name"
  make_fixture "$fixture"
  mkdir -p "$fixture/infra/opentofu"
  for ignored_path in "prod.tfvars" "prod.auto.tfvars" "prod.tfvars.json" "prod.auto.tfvars.json"; do
    touch "$fixture/infra/opentofu/$ignored_path"
    if ! git -C "$fixture" check-ignore -q "infra/opentofu/$ignored_path"; then
      echo "FAIL: .gitignore does not ignore $ignored_path" >&2
      exit 1
    fi
  done
  if ! (cd "$fixture" && bash infra/opentofu/scripts/guardrail-provider-neutral.sh) > "$fixture/output.log" 2>&1; then
    cat "$fixture/output.log"
    echo "FAIL: expected guardrail pass for $name" >&2
    exit 1
  fi
  echo "PASS: $name"
}

assert_reject() {
  local name="$1"
  local expected="$2"
  local fixture="$tmp_root/$name"
  make_fixture "$fixture"

  case "$name" in
    provider)
      printf '\nprovider "aws" {}\n' >> "$fixture/infra/opentofu/main.tf"
      ;;
    resource)
      printf '\nresource "aws_s3_bucket" "example" {}\n' >> "$fixture/infra/opentofu/main.tf"
      ;;
    data)
      printf '\ndata "aws_caller_identity" "current" {}\n' >> "$fixture/infra/opentofu/main.tf"
      ;;
    module)
      printf '\nmodule "network" { source = "./modules/network" }\n' >> "$fixture/infra/opentofu/main.tf"
      ;;
    backend)
      printf '\nterraform {\n  backend "s3" {}\n}\n' >> "$fixture/infra/opentofu/main.tf"
      ;;
    cloud)
      printf '\nterraform {\n  cloud { organization = "example" }\n}\n' >> "$fixture/infra/opentofu/main.tf"
      ;;
    state)
      printf '{}\n' > "$fixture/infra/opentofu/dev.tfstate"
      git -C "$fixture" add -f infra/opentofu/dev.tfstate
      ;;
    backup)
      printf '{}\n' > "$fixture/infra/opentofu/terraform.tfstate.backup"
      git -C "$fixture" add -f infra/opentofu/terraform.tfstate.backup
      ;;
    plan)
      printf 'sensitive\n' > "$fixture/infra/opentofu/review.tfplan"
      git -C "$fixture" add -f infra/opentofu/review.tfplan
      ;;
    tfvars)
      printf '{"password":"never-commit"}\n' > "$fixture/infra/opentofu/prod.auto.tfvars.json"
      git -C "$fixture" add -f infra/opentofu/prod.auto.tfvars.json
      ;;
    apply-inline)
      printf '\njobs:\n  unsafe:\n    steps:\n      - run: tofu apply -auto-approve\n' >> "$fixture/.github/workflows/iac-validation.yml"
      ;;
    apply-multiline)
      printf '\njobs:\n  unsafe:\n    steps:\n      - name: forbidden\n        run: |\n          tofu \\\n            apply -auto-approve\n' >> "$fixture/.github/workflows/iac-validation.yml"
      ;;
    apply-backtick)
      printf '\njobs:\n  unsafe:\n    steps:\n      - run: echo "\x60tofu apply -auto-approve\x60"\n' >> "$fixture/.github/workflows/iac-validation.yml"
      ;;
    apply-substitution)
      printf '\njobs:\n  unsafe:\n    steps:\n      - run: echo "$(tofu apply -auto-approve)"\n' >> "$fixture/.github/workflows/iac-validation.yml"
      ;;
    apply-chdir)
      printf '\njobs:\n  unsafe:\n    steps:\n      - run: tofu -chdir=infra/opentofu apply -auto-approve\n' >> "$fixture/.github/workflows/iac-validation.yml"
      ;;
    apply-terraform)
      printf '\njobs:\n  unsafe:\n    steps:\n      - run: terraform apply -auto-approve\n' >> "$fixture/.github/workflows/iac-validation.yml"
      ;;
    apply-other-workflow)
      printf 'name: Unapproved deploy\njobs:\n  unsafe:\n    steps:\n      - run: tofu apply -auto-approve\n' > "$fixture/.github/workflows/unapproved-deploy.yml"
      ;;
    provider-tofu)
      printf '\nprovider "aws" {}\n' > "$fixture/infra/opentofu/main.tofu"
      ;;
    resource-tofu)
      printf '\nresource "aws_s3_bucket" "example" {}\n' > "$fixture/infra/opentofu/storage.tofu"
      ;;
    backend-tofu)
      printf '\nterraform {\n  backend "s3" {}\n}\n' > "$fixture/infra/opentofu/backend.tofu"
      ;;
    required-providers-tofu)
      printf '\nterraform {\n  required_providers {\n    aws = { source = "hashicorp/aws" }\n  }\n}\n' > "$fixture/infra/opentofu/providers.tofu"
      ;;
    provider-json)
      printf '{"provider":{"aws":{"region":"us-east-1"}}}\n' > "$fixture/infra/opentofu/providers.tf.json"
      ;;
    resource-json)
      printf '{"resource":{"aws_s3_bucket":{"example":{"bucket":"example"}}}}\n' > "$fixture/infra/opentofu/storage.tf.json"
      ;;
    data-json)
      printf '{"data":{"aws_caller_identity":{"current":{}}}}\n' > "$fixture/infra/opentofu/data.tofu.json"
      ;;
    module-json)
      printf '{"module":{"network":{"source":"./modules/network"}}}\n' > "$fixture/infra/opentofu/module.tofu.json"
      ;;
    backend-json)
      printf '{"terraform":{"backend":{"s3":{}}}}\n' > "$fixture/infra/opentofu/backend.tf.json"
      ;;
    cloud-json)
      printf '{"terraform":{"cloud":{"organization":"example"}}}\n' > "$fixture/infra/opentofu/cloud.tofu.json"
      ;;
    required-providers-json)
      printf '{"terraform":{"required_providers":{"aws":{"source":"hashicorp/aws"}}}}\n' > "$fixture/infra/opentofu/required-providers.tofu.json"
      ;;
    *)
      echo "Unknown test case: $name" >&2
      exit 1
      ;;
  esac

  if (cd "$fixture" && bash infra/opentofu/scripts/guardrail-provider-neutral.sh) > "$fixture/output.log" 2>&1; then
    echo "FAIL: guardrail accepted forbidden case $name" >&2
    exit 1
  fi
  if ! grep -Fq "$expected" "$fixture/output.log"; then
    cat "$fixture/output.log"
    echo "FAIL: $name was rejected for an unexpected reason" >&2
    exit 1
  fi
  echo "PASS: rejects $name"
}

assert_pass "baseline"
assert_reject "provider" "Provider, resource, data, and module blocks"
assert_reject "resource" "Provider, resource, data, and module blocks"
assert_reject "data" "Provider, resource, data, and module blocks"
assert_reject "module" "Provider, resource, data, and module blocks"
assert_reject "backend" "remote state backend, or cloud execution block"
assert_reject "cloud" "remote state backend, or cloud execution block"
assert_reject "state" "state, backup, plan, or variable-value files"
assert_reject "backup" "state, backup, plan, or variable-value files"
assert_reject "plan" "state, backup, plan, or variable-value files"
assert_reject "tfvars" "state, backup, plan, or variable-value files"
assert_reject "provider-tofu" "Provider, resource, data, and module blocks"
assert_reject "resource-tofu" "Provider, resource, data, and module blocks"
assert_reject "backend-tofu" "provider requirement, remote state backend, or cloud execution block"
assert_reject "required-providers-tofu" "provider requirement, remote state backend, or cloud execution block"
assert_reject "provider-json" "provider-neutral JSON configuration"
assert_reject "resource-json" "provider-neutral JSON configuration"
assert_reject "data-json" "provider-neutral JSON configuration"
assert_reject "module-json" "provider-neutral JSON configuration"
assert_reject "backend-json" "provider-neutral JSON configuration"
assert_reject "cloud-json" "provider-neutral JSON configuration"
assert_reject "required-providers-json" "provider-neutral JSON configuration"
assert_reject "apply-inline" "must not contain an apply invocation"
assert_reject "apply-multiline" "must not contain an apply invocation"
assert_reject "apply-backtick" "must not contain an apply invocation"
assert_reject "apply-substitution" "must not contain an apply invocation"
assert_reject "apply-chdir" "must not contain an apply invocation"
assert_reject "apply-terraform" "must not contain an apply invocation"
assert_reject "apply-other-workflow" "must not contain an apply invocation"

echo "PASS: all 29 provider-neutral IaC guardrail regression cases."
