# Phase12A — Private Media Object Inventory and Integrity Migration Contract

**Status:** DESIGN SPECIFICATION COMPLETE; no real object inventory, copy, or restore has been run.  
**Date:** 2026-11-28  
**Backend baseline:** `m5ham3ds/RAVEN23-BACKEND@72cebe78fb436a6036b7144ff3b0c31e542204a0`  
**Scope:** provider-neutral data inventory and migration contract; no provider or target selection.

## 1. Purpose and proof boundary

The PostgreSQL full-schema rehearsal validates database rows and schema in a disposable CI database. It does not include the bytes stored by the `PrivateObjectStorage` adapter. RAVEN23 user media is a two-part record: a `user_media` metadata row in PostgreSQL and a private object referenced by `object_key`.

This specification defines how a future approved migration must reconcile both parts. It does not assert that any live object exists, identify the current production disk, or authorize reading or copying user files.

## 2. Source-code facts verified

At the reviewed Backend source:

- `user_media` metadata contains `id` (UUID), `user_id`, `purpose`, `object_key`, `original_name`, `mime_type`, `size_bytes`, `checksum_sha256`, and timestamps. The `object_key` is unique; `user_id` is a foreign key to users.
- Upload keys are generated server-side in the form `users/{numeric-user-id}/media/{media-uuid}.{extension}`. Allowed extensions currently are jpg, png, webp, pdf, and txt; user media uploads are limited to 5 MiB for avatars and 10 MiB for attachments.
- The upload code stores the object through `PrivateObjectStorage`, then creates the metadata row in the same database transaction. A failed request attempts compensating object deletion.
- The `PrivateObjectStorage` contract exposes `put`, `get`, `exists`, `delete`, and recursive `listObjects`. The current listing exposes only key and last-modified timestamp; it does not return object size or a trusted content checksum.
- The active adapter wraps Laravel's Filesystem disk. `RAVEN23_PRIVATE_STORAGE_DISK` chooses the disk. The default is `private_media`, whose current disk configuration is local storage rooted at `storage/app/private/media`; the `s3` disk is configured as an option, but this review does not prove that any deployment uses it.
- Access is private and owner-scoped. The authenticated download route checks the request user's metadata relation. Temporary downloads are signed, owner-bound, and short-lived (default five minutes; configured TTL is clamped to 1–15 minutes).
- Deletion removes metadata and dispatches an encrypted `DeletePrivateObjectJob` after commit. The job retries and treats a missing object as an idempotent deletion. Orphan cleanup is dry-run by default, requires explicit configuration to apply, and enforces at least a 24-hour grace period.
- The account data export includes media metadata but intentionally excludes the private object key; it is not a binary backup or object-store export.

These are code/configuration facts, not measurements of a live bucket, disk, or user's media collection.

## 3. Migration entities and invariants

Treat the following as separate but linked migration entities:
1. PostgreSQL `user_media` metadata row.
2. Source object bytes at the exact `object_key`.
3. Destination private object bytes at the mapped destination key.
4. Account identity mapping used by the numeric owner segment in the key.

Required invariants:

- **One metadata row ↔ one verified object.** Every accepted metadata row must resolve to exactly one source object and one verified destination object.
- **Byte integrity.** Recompute SHA-256 from the retrieved bytes and compare it to the row's `checksum_sha256`; also compare byte length to `size_bytes`. Do not rely on provider ETag as a SHA-256 substitute.
- **Stable identity.** Preserve the media UUID, user identity mapping, purpose, MIME type, original filename, created timestamp, and metadata fields. Preserve `object_key` when the approved target namespace permits it.
- **Owner path integrity.** The numeric owner segment embedded in `object_key` must agree with the metadata row's `user_id`. Any owner-ID remapping requires an explicit key mapping manifest and metadata update plan; never rewrite paths by string substitution alone.
- **Private visibility.** Destination objects must remain private. Public ACLs, public buckets, or unauthenticated object URLs are unacceptable.
- **Copy-before-switch.** Do not update the active disk/configuration or rewrite metadata until every destination object is copied and verified and the cutover decision is approved.
- **Source retention.** Keep source objects intact through verification, rollback window, and explicit owner sign-off. A successful copy is not permission to delete the source.
- **Idempotency.** Re-running a batch must recognize an already-copied object with the expected size/hash and avoid creating duplicate metadata or changing media identity.
- **Fail closed.** Missing source, metadata/object mismatch, permission error, invalid key, checksum mismatch, or unresolved account owner blocks the affected batch and the overall migration gate until reconciled.

## 4. Inventory and reconciliation model

A future read-only inventory should produce two reconciled sets.

### 4.1 Database metadata inventory

For every `user_media` row, compute:
- total row count and total `size_bytes` by purpose and coarse MIME family;
- count of distinct owners (aggregate only);
- invalid/malformed key count;
- duplicate-key/UUID/owner-path inconsistency count;
- rows with missing expected fields or invalid size/checksum format;
- aggregate checksum verification result after reading the corresponding source bytes.

The protected operational manifest needed to resume a real migration may include row IDs, owner IDs and object keys. It must be access-controlled, encrypted outside ordinary public CI artifacts, and never committed to Git or exposed in a public report.

### 4.2 Object-store inventory

List objects under the controlled `users` prefix and compare exact keys to metadata keys:
- referenced objects present;
- metadata rows whose objects are missing;
- unreferenced objects (orphans);
- objects with malformed or unexpected key paths;
- objects whose bytes cannot be read;
- objects whose byte length or SHA-256 does not match metadata.

Because current `listObjects` does not return size or content hash, byte-level verification requires reading each object and calculating both values. ETag and last-modified timestamp are useful diagnostics but are not integrity proof.

### 4.3 Public evidence format

A GitHub/ordinary report may contain only aggregate counts, aggregate byte totals, status enums, schema/spec versions, and a run identifier unrelated to a user or device. It must not include object keys, original filenames, owner/user IDs, media UUIDs, download URLs, raw bytes, row JSON, tokens, credentials, or per-user/per-object hashes.

Any real row-level manifest must be handled as a restricted operational artifact under a separately approved process. It must never be uploaded as a standard Actions artifact by default.

## 5. Required synthetic acceptance matrix

All tests must use temporary local storage, fake disks, or S3Mock-style test infrastructure with synthetic files only.

| ID | Scenario | Required outcome |
|---|---|---|
| OBJ-001 | Metadata row has a matching object with expected size and SHA-256 | Inventory reports one valid pair; no mutation occurs. |
| OBJ-002 | Metadata row exists but object is missing | Mark SOURCE_OBJECT_MISSING and stop; do not create an empty replacement or delete the row. |
| OBJ-003 | Object exists without a metadata row | Mark ORPHAN_CANDIDATE; retain by default and do not run cleanup as part of migration. |
| OBJ-004 | Object bytes differ from stored checksum | Mark CHECKSUM_MISMATCH; no copy/switch/cleanup for that item. |
| OBJ-005 | Object byte length differs from metadata `size_bytes` | Mark SIZE_MISMATCH even if the key exists. |
| OBJ-006 | Object list returns an unusual/malformed key or key-owner mismatch | Mark INVALID_KEY/OWNER_PATH_MISMATCH; never construct a path from untrusted row or client data. |
| OBJ-007 | Destination already has identical key/size/hash | Treat as idempotent success without rewriting metadata. |
| OBJ-008 | Destination has same key but different bytes | Stop with DESTINATION_COLLISION; preserve source and destination for explicit review. |
| OBJ-009 | Copy succeeds but destination read-back/hash fails | Do not switch active disk or update metadata; preserve the source and mark the batch failed. |
| OBJ-010 | Process stops after some objects copy | Resume using protected checkpoint; re-verify copied destination objects and continue without duplicate DB rows. |
| OBJ-011 | Owner IDs are remapped | Require explicit old-owner→new-owner mapping and deterministic old-key→new-key map; validate path against row owner before and after. |
| OBJ-012 | Private object download is checked after target switch rehearsal | Existing owner authorization remains enforced; no public URL/ACL is introduced. |
| OBJ-013 | Temporary signed URL from before the rehearsal is reused | It is not treated as portable migration state; generate a new short-lived URL after switch. |
| OBJ-014 | Metadata row is deleted and an after-commit delete job is queued during inventory | Inventory captures a stable snapshot boundary; the job/delete race is represented in a delta or the inventory is repeated under a write barrier. |
| OBJ-015 | Orphan cleanup command is invoked during migration rehearsal | Dry-run only; no delete job is dispatched. |
| OBJ-016 | Aggregate report validation | Reject object keys, filenames, owner IDs, media UUIDs, URLs, row content and secret-like fields. |

Required negative assertions: no source deletion; no public visibility; no metadata/key remap without approved owner map; no row loss; no silent orphan deletion; no acceptance of a size/hash mismatch.

## 6. Safe migration sequence

1. Obtain explicit approval for a named source and destination, permitted transfer channel, private-storage controls, operating window, and rollback owner. Provider/region/cost selection remains deferred under D-050/D-051.
2. Freeze or reliably journal media metadata and object writes/deletes, including queue jobs. If writes cannot be frozen, define a durable delta/dual-write method before starting; a one-time listing is insufficient.
3. Capture a consistent DB snapshot of `user_media` and the corresponding source-object listing. Record the snapshot epoch and the state of pending `DeletePrivateObjectJob` items.
4. Reconcile metadata-to-object sets. Resolve all missing references, orphans, malformed keys, and owner mismatches before copy. Do not use the cleanup command as a migration tool.
5. Copy source bytes to the target private disk in bounded batches. Re-read each destination object and verify length and SHA-256 against the metadata row.
6. Persist a restricted, resumable checkpoint containing item identity/status and completed verification; publish only aggregate evidence.
7. Capture and apply the approved delta for uploads, metadata changes, owner changes, and deletions that occurred after the snapshot watermark. Preserve order and idempotency.
8. Run target inventory again. Require exact accepted metadata/object parity and zero unexplained checksum/size/missing/orphan discrepancies.
9. Rehearse read authorization, signed URL generation, download, and queued deletion against the target in an isolated environment.
10. Only after separate cutover approval switch the active private disk or metadata key map. Keep source objects untouched during the approved rollback window.
11. If rollback is invoked, restore the old disk/key mapping and route reads back to the source. Do not delete destination objects while state is unresolved.
12. Source cleanup is a separate later approval after the rollback window and a final integrity report.

## 7. Configuration and cryptographic dependencies

- The current default `private_media` disk is local and private. The configured S3 disk is only an available adapter configuration; there is no approved provider, bucket, region, encryption policy or credential in this work.
- Enforce private access at the destination. At-rest encryption, transport encryption, retention, lifecycle/versioning and access policy must be approved and tested with the eventual provider; do not assume them from local disk or S3Mock tests.
- Do not migrate pre-signed temporary URLs as durable data. They are short-lived capabilities and are generated afresh after target switch. The signing secret must remain stable for the rehearsal or all links should simply expire and be regenerated.
- Preserve `APP_KEY` and the application's encrypted data contracts. Do not rotate keys as part of object copy. If a later approved key rotation is needed, it is a separate cryptographic migration with its own inventory and tests.
- Do not expose the protected row-level manifest or use per-object hashes in public artifacts; the latter can disclose information when content is guessable.

## 8. Current capabilities and implementation gaps

Already present:
- metadata table with owner FK, unique object key, size and SHA-256 fields;
- private storage abstraction with put/get/exists/delete/list;
- current local private disk plus optional S3 disk configuration;
- owner-scoped authenticated downloads and owner-bound temporary signed downloads;
- encrypted after-commit deletion job and explicit dry-run/opt-in orphan cleanup;
- SQLite Feature and PostgreSQL Integration tests for ownership, byte checksum metadata, private download, signed URL expiry, quota, and orphan/delete behavior.

Not yet present/proven:
- no migration-specific object manifest or copy/verify/rollback command;
- object listing does not expose size/checksum; byte verification must read contents;
- no real source or target disk inventory, object count, total bytes, or current deployment disk verified;
- no real bucket/provider, provider-side encryption policy, or external backup configured;
- no tested write freeze/delta capture spanning object writes, DB rows, and queued deletes;
- no production object restore/cutover or rollback evidence.

Do not describe S3Mock or local fake storage as a live object-storage integration.

## 9. Phase12A exit criteria for private objects

This workstream's design is complete. Phase12A remains IN_PROGRESS until:
- a synthetic object manifest validator passes and forbids private fields in public reports;
- synthetic local/S3Mock fixture tests cover all OBJ scenarios that are implementable without a real provider;
- a repeatable copy/read-back/checksum verifier is implemented and tested without enabling any live target;
- a DB+object+queue snapshot/delta acceptance rehearsal demonstrates no lost writes/deletes;
- source/target and any provider-dependent criteria remain deferred until the approvals required by D-050/D-051.

Passing this workstream does not complete Phase12A, authorize real copy, complete Phase12B/12C, or open Phase13B.
