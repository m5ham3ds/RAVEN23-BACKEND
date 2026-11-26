terraform {
  required_version = ">= 1.6.0"
}

locals {
  platform_contract = {
    contract_version = 1
    service           = "raven23-backend"

    runtime = {
      packaging = "OCI image"
      web       = "FrankenPHP/Caddy"
      identity  = "non-root"
    }

    data = {
      database         = "PostgreSQL"
      object_storage   = "S3-compatible private object storage"
      application_role = "restricted runtime role"
      migration_role   = "one-off migration role"
    }

    asynchronous_work = {
      cache = "Laravel Cache abstraction"
      queue = "Laravel Queue abstraction"
    }

    delivery = {
      artifact_policy    = "build once; promote immutable OCI digest"
      deployment_identity = "short-lived and separate from runtime identity"
    }

    module_boundaries = [
      "network",
      "compute",
      "database",
      "object-storage",
      "secrets",
      "monitoring",
      "registry",
    ]
  }
}
