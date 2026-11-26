output "platform_contract" {
  description = "Provider-neutral RAVEN23 platform contract. No infrastructure resources are managed."
  value       = local.platform_contract
}

output "deployment_descriptor" {
  description = "Logical deployment inputs; region and image digest remain unset until separately approved."
  value = {
    service_name                 = var.service_name
    deployment_environment       = var.deployment_environment
    deployment_region            = var.deployment_region
    image_digest                 = var.image_digest
    image_reference_policy       = "sha256-digest-only"
    deployment_creates_resources = false
  }
}
