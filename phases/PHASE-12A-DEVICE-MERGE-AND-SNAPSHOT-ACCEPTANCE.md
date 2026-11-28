# Phase12A — Synthetic Device Merge and Snapshot Acceptance Specification

**Status:** SPECIFICATION COMPLETE; implementation and execution NOT RUN.  
**Date:** 2026-11-28  
**Backend repository:** `m5ham3ds/RAVEN23-BACKEND`  
**Flutter source reviewed read-only:** `m5ham3ds/RAVEN23` at `387feafb4865d991223897088b82eacb7a42061a`  
**Purpose:** define falsifiable, synthetic-only acceptance criteria for local guest merge, legacy Firebase owner re-key, and device snapshot/delta evidence before any real migration is considered.

## 1. Scope and non-goals

This specification closes a documentation/design task inside Phase12A. It does not claim that the client behavior has been repaired or tested.

In scope:
- preservation of confirmed playlist rows/items as well as pending playlist operations;
- preservation and correct owner scoping of bookmark known/deletion journals stored outside SQLite;
- safe re-key of legacy `user:<firebase uid>` local data to the authenticated Laravel owner;
- aggregate-only device preflight evidence;
- a consistent snapshot boundary and a lossless delta/replay contract.

Out of scope:
- editing the Flutter application repository;
- adding Firebase exchange routes, Laravel migrations, playlist-sharing endpoints, or new Backend API contracts;
- connecting to Firebase/Firestore, user devices, production PostgreSQL, Neon, Render, or any live storage;
- reading secrets, collecting telemetry, uploading device databases, choosing a provider/source/target, or running cutover.

The current Flutter source review is evidence of code shape only. It does not prove which source currently owns a user's live data or whether a legacy cohort exists.

## 2. Mandatory data-preservation invariants

1. **Copy before clear.** A source database, preference journal, or owner namespace must not be deleted, cleared, or repurposed until the destination copy is durable, its owner mapping is verified, all required remote operations are acknowledged, and the source snapshot is still the version that was copied.
2. **No silent omission.** Confirmed playlists/items, source/share metadata, bookmark journals, tombstones, pending operations, and stable IDs must be explicitly classified as transferred, retained locally, intentionally excluded by an approved contract, or blocked. Unknown classification is a hard stop.
3. **ACK is not enqueue.** A pending operation is not complete merely because it was copied to another local queue or sent over the network. Preserve its original operation ID and payload until a durable server acknowledgement or a terminal, reviewed disposition exists.
4. **Idempotent retry.** Re-running a merge after process death, timeout, duplicate callback, or partial acknowledgement must not duplicate rows, change operation identity, or erase the only surviving copy.
5. **Owner isolation.** Guest, legacy Firebase UID, and Laravel account namespaces must never be merged across accounts based on display name, email guess, or local row content. The authenticated identity exchange must provide an explicit, validated source-to-target owner mapping.
6. **Conflict is fail-closed.** If the same stable ID exists on both sides with incompatible content and there is no approved merge rule, retain both snapshots and stop for explicit conflict resolution. Do not use timestamp-only overwrite as an implicit policy.
7. **Derived data is rebuilt.** Wellness daily summaries are derived from sessions; after owner re-key, rebuild them from the final session set and compare totals. Do not treat a copied aggregate as an authoritative source.
8. **Cursors are not portable by default.** After owner, source, or target changes, invalidate old server cursors/checkpoints unless the contract proves they belong to the same owner and revision space. Require a full snapshot/reconciliation before incremental sync resumes.
9. **Preserve deletions.** Tombstones and bookmark deletion journals are first-class changes. They must survive retries and must not be converted into absence that allows a stale record to resurrect.

## 3. Synthetic test fixture model

All fixtures must be generated inside isolated test temporary directories/databases. Use fixed synthetic owners such as:
- `guest:fixture-a`;
- `user:firebase-fixture-uid`;
- `user:laravel-fixture-id`.

Use fake movie/episode IDs and non-personal titles. Never copy real user IDs, tokens, URLs, media rows, or preference values into test artifacts.

The fixture should contain, at minimum:
- one confirmed playlist with multiple items and stable playlist/item IDs;
- one source-backed playlist with synthetic `source_provider`, `source_id`, `share_code`, and `share_url` values;
- one pending playlist operation with a stable operation ID and payload;
- one bookmark known-ID entry and one pending deletion journal entry;
- one movie bookmark and one TV bookmark;
- one movie progress row and one episode progress row, including a tombstone case;
- one Wellness session plus a derived daily summary;
- owner-scoped cursors/checkpoints that can be deliberately stale;
- a second synthetic account with overlapping-looking data to detect cross-owner leakage.

Preference keys must be generated through the same namespaced key-building code as the application, rather than hand-written approximations.

## 4. Acceptance test matrix

| ID | Scenario | Required result |
|---|---|---|
| PL-001 | Guest has confirmed playlists/items and no pending operations | Confirmed rows and items remain available under the intended destination owner; source/share metadata is retained locally until its Backend contract is resolved; source is not cleared merely because the pending queue is empty. |
| PL-002 | Guest has confirmed rows plus pending operations | Both confirmed state and pending operations are preserved. Pending operation IDs and payload hashes remain unchanged through transfer and replay. |
| PL-003 | Guest has pending operations only | Operations are copied without changing IDs/order; source queue remains until durable ACK and unchanged-snapshot verification. |
| PL-004 | Same playlist/item ID exists in source and destination with identical content | Merge is repeatable and does not create duplicates. |
| PL-005 | Same stable ID exists with different content | Merge stops without deleting either copy; a deterministic conflict record is produced for explicit resolution. |
| PL-006 | Network failure, partial ACK, process kill, or restart during merge | Re-run resumes idempotently; unacknowledged rows/operations remain in the source; acknowledged source rows are removed only when unchanged since the copied snapshot. |
| PL-007 | Playlist changes after the merge snapshot but before ACK cleanup | The newer local version is retained and is not cleared by cleanup of the older snapshot. |
| BM-001 | Guest has bookmark known IDs and pending deletion journal entries | Both journal classes are transferred or explicitly retained under the correct destination scope; they are not conflated; keys for another account remain untouched. |
| BM-002 | Deletion replay is offline or receives a transient failure | Tombstone/journal entry remains pending; retry uses the same idempotency identity; stale server state cannot resurrect the bookmark. |
| BM-003 | Destination already contains the same bookmark/deletion operation | Replay is idempotent and the acknowledged state is reconciled without losing a newer deletion. |
| OWN-001 | Legacy Firebase owner has rows in all six SQLite stores and namespaced preference journals | Every supported store and journal is mapped to the authenticated Laravel owner; counts and stable IDs reconcile; old owner files remain intact until acceptance passes. |
| OWN-002 | Firebase-to-Laravel exchange is missing, rejected, or returns mismatched identity | No owner re-key or source cleanup occurs; local data remains accessible to the original owner. |
| OWN-003 | Legacy owner re-key succeeds | Wellness owner IDs are remapped, daily summaries are rebuilt, other owner-suffixed DBs/journals are verified, and old cursors/checkpoints are invalidated before full pull. |
| OWN-004 | Two accounts are signed in sequentially on the same installation | No bookmark, playlist, recent, Wellness, or journal row leaks between owners after switch, logout, token revocation, or retry. |
| SNAP-001 | Manifest generated while writes are quiesced | Each store is counted against one declared snapshot epoch; SQLite integrity status and schema versions are recorded; no raw rows or identifiers appear in the manifest. |
| SNAP-002 | A store is missing, corrupt, or has an unsupported schema version | Manifest marks it unavailable/failed/unsupported; it never reports a clean zero-row store as a substitute. |
| DELTA-001 | Changes occur after the initial snapshot boundary | Every post-boundary insert/update/delete is represented by a durable change record or the process takes a new full snapshot under a write barrier. |
| DELTA-002 | Old cursor/checkpoint is from another owner/source/revision space | Cursor is rejected and full reconciliation runs; incremental pull does not silently continue from the stale checkpoint. |

A passing implementation must include negative assertions: no early source clear, no lost pending operation, no tombstone loss, no cross-owner read/write, no source/share metadata erasure, and no stale-cursor reuse.

## 5. Device snapshot manifest — aggregate-only contract

The preflight manifest is evidence about the shape and health of local stores, not a backup and not a data export. It must use a versioned schema and fixed store names.

Required fields:
- `manifest_schema_version`;
- application source SHA and app build/version;
- manifest creation time in UTC and a random, non-identifying snapshot run ID;
- for each of the six stores: availability, schema version, integrity-check status, table-level row counts, pending/confirmed/tombstone counts where applicable;
- tombstone age buckets: 0–7, 8–30, 31–90, and over 90 days;
- playlist counts for confirmed lists/items, pending operations, and presence/count of source/share metadata fields;
- bookmark journal counts for known IDs and pending deletions;
- Wellness session counts by coarse owner class, deleted/pending counts, daily-summary count, and rebuild-validation status;
- cursor/checkpoint presence flags and coarse last-success/full-sync age buckets;
- snapshot epoch/watermark metadata and explicit warnings for unknown or unsupported fields.

The manifest must not contain raw owner IDs, Firebase UIDs, Laravel IDs, device IDs, media IDs, titles, playlist names, operation UUIDs, bookmark IDs, share codes/URLs, tokens, secrets, row JSON, database contents, or preference values. Do not publish row-level hashes or hashes of low-entropy personal fields; such hashes can still disclose information by guessing.

A manifest with a missing store, failed SQLite integrity check, unsupported schema, unresolved owner scope, or incomplete table inventory is **NOT_READY**, not a successful zero-data result.

## 6. Consistent snapshot and delta rules

SQLite files are separate transactional stores; independent read transactions do not automatically create one atomic cross-file snapshot.

Before claiming a device-wide consistent snapshot:
1. the application must enter a coordinated local-write barrier covering all six stores and the relevant preference journals;
2. it must capture a single snapshot epoch and each store's schema/revision/watermark while writes remain paused;
3. each database is read in a consistent SQLite transaction and checked with its integrity command;
4. writes resume only after the required snapshot metadata is captured;
5. any edit that occurs after the barrier is represented in a durable delta journal or triggers a new full snapshot.

The current source review does not establish a global write barrier or a durable cross-store change log. Do not claim either exists until implemented and tested.

Delta contract:
- represent creates, updates, and deletes as ordered, durable changes with stable operation identity;
- preserve tombstones and original operation IDs;
- define a per-store high-water mark at the snapshot boundary;
- replay only changes strictly after that mark, in deterministic order;
- require idempotent ACK/replay and retain unacknowledged changes;
- if any store cannot produce a complete delta from its watermark, repeat a full snapshot under the barrier rather than infer changes from timestamps alone.

## 7. Known contract gaps and implementation ownership

- `PlaylistStore.moveInto` currently transfers pending operations only; confirmed playlists/items and source/share metadata are not covered by that transfer. This specification requires tests for this case, not an assertion that the current implementation already passes.
- Bookmark known/deletion journals are stored separately from the SQLite stores; an explicit scoped transfer/reconciliation test is required.
- The inspected Firebase bridge callback moves Wellness ownership only; the other owner-scoped stores and journals are not proven to be re-keyed.
- Backend currently has no `POST /api/v1/auth/firebase/exchange` route. The exchange contract must be reviewed before any legacy Firebase cohort can pass end-to-end acceptance.
- Playlist import/sharing fields and routes remain a separate unresolved Phase06 contract item. This spec requires preservation while the contract is unresolved; it does not authorize adding endpoints or columns.

Implementation responsibility for these client-local fixes and tests belongs in `m5ham3ds/RAVEN23`. This Backend repository records the contract only. No Flutter source has been changed in this task.

## 8. Phase12A exit criteria for this workstream

This specification is complete as a design artifact. The implementation workstream remains open until:
- the synthetic fixture and tests run in the Flutter repository's CI;
- all test IDs in section 4 pass, or a case is explicitly blocked by an approved product/API decision;
- a manifest schema validator test confirms aggregate-only output and rejects forbidden fields;
- snapshot/delta behavior is implemented or the project explicitly chooses a safe repeated-full-snapshot strategy;
- the legacy owner exchange contract and its owner mapping are demonstrated end-to-end.

Passing this workstream still does not complete Phase12A overall, Phase12B, Phase12C, or Phase13B.
