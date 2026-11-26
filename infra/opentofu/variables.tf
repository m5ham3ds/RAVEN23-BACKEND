variable "service_name" {
  description = "Stable logical service name; not a provider resource name."
  type        = string
  default     = "raven23-backend"

  validation {
    condition     = can(regex("^[a-z0-9][a-z0-9-]{1,48}$", var.service_name))
    error_message = "service_name must be a lowercase DNS-label-like identifier."
  }
}

variable "deployment_environment" {
  description = "Logical target environment. This contract does not create it."
  type        = string
  default     = "development"

  validation {
    condition     = contains(["development", "staging", "production"], var.deployment_environment)
    error_message = "deployment_environment must be development, staging, or production."
  }
}

variable "deployment_region" {
  description = "Provider-specific region, intentionally unset until the owner approves a provider and region."
  type        = string
  default     = null
  nullable    = true

  validation {
    condition     = var.deployment_region == null || length(trimspace(var.deployment_region)) > 0
    error_message = "deployment_region must be null or a non-empty value."
  }
}

variable "image_digest" {
  description = "Immutable OCI manifest digest. Tags and mutable image references are not accepted."
  type        = string
  default     = null
  nullable    = true

  validation {
    condition     = var.image_digest == null || can(regex("^sha256:[0-9a-f]{64}$", var.image_digest))
    error_message = "image_digest must be null or a sha256 OCI digest."
  }
}
