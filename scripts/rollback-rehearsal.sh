#!/usr/bin/env bash
set -Eeuo pipefail
: "$GITHUB_SHA" "$GITHUB_WORKSPACE" "$GITHUB_RUN_ID" "$RUNNER_TEMP"

target="$GITHUB_WORKSPACE/phases/release/rollback-target.json"
evidence="$GITHUB_WORKSPACE/release-evidence"
rollback_sha="$(jq -er '.rollbackTarget.commit' "$target")"
[[ "$rollback_sha" =~ ^[0-9a-f]{40}$ ]]
test "$rollback_sha" != "$GITHUB_SHA"
git cat-file -e "$rollback_sha^{commit}"

context="$(mktemp -d "$RUNNER_TEMP/rollback-context.XXXXXX")"
current_image="raven23-backend:sha-$GITHUB_SHA"
rollback_image="raven23-backend:rollback-$rollback_sha"
current_name="raven23-rollback-current-$GITHUB_RUN_ID"
rollback_name="raven23-rollback-old-$GITHUB_RUN_ID"
active_file="$RUNNER_TEMP/rollback-active-$GITHUB_RUN_ID.txt"
proxy_log="$RUNNER_TEMP/rollback-proxy-$GITHUB_RUN_ID.log"
proxy_pid=""
cleanup() {
  if [ -n "$proxy_pid" ]; then kill "$proxy_pid" >/dev/null 2>&1 || true; wait "$proxy_pid" >/dev/null 2>&1 || true; fi
  docker rm -f "$current_name" "$rollback_name" >/dev/null 2>&1 || true
  rm -f "$active_file" "$active_file.next" "$proxy_log"
  rm -rf "$context"
}
trap cleanup EXIT
mkdir -p "$evidence"

docker tag raven23-backend:ci "$current_image"
git archive "$rollback_sha" | tar -x -C "$context"
docker build --pull --build-arg "RAVEN23_SOURCE_COMMIT=$rollback_sha" -t "$rollback_image" "$context"

image_sha() {
  docker image inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$1" | sed -n 's/^RAVEN23_SOURCE_COMMIT=//p' | tail -n 1
}
test "$(image_sha "$current_image")" = "$GITHUB_SHA"
test "$(image_sha "$rollback_image")" = "$rollback_sha"
current_digest="$(docker image inspect --format '{{.Id}}' "$current_image")"
rollback_digest="$(docker image inspect --format '{{.Id}}' "$rollback_image")"
[[ "$current_digest" =~ ^sha256:[0-9a-f]{64}$ ]]
[[ "$rollback_digest" =~ ^sha256:[0-9a-f]{64}$ ]]
current_platform="$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$current_image")"
rollback_platform="$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$rollback_image")"
test "$current_platform" = "$rollback_platform"
test "$(docker image inspect --format '{{.Config.User}}' "$current_image")" = "raven:raven"
test "$(docker image inspect --format '{{.Config.User}}' "$rollback_image")" = "raven:raven"

rollback_archive="$evidence/raven23-backend-rollback-$rollback_sha.docker.tar"
rollback_sbom="$evidence/raven23-backend-rollback.cdx.json"
docker save --output "$rollback_archive" "$rollback_image"
rollback_archive_sha="$(sha256sum "$rollback_archive" | awk '{print $1}')"
docker pull aquasec/trivy:0.76.2
docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v "$evidence:/out" aquasec/trivy:0.76.2 \
  image --format cyclonedx --output /out/raven23-backend-rollback.cdx.json "$rollback_image"
test -s "$rollback_sbom"
rollback_sbom_sha="$(sha256sum "$rollback_sbom" | awk '{print $1}')"

base_ref="$(awk '$1 == "FROM" && $0 ~ / AS runtime$/ { print $2; exit }' "$context/Dockerfile")"
jq -n \
  --arg repo "$GITHUB_REPOSITORY" --arg commit "$rollback_sha" --arg workflow_commit "$GITHUB_SHA" \
  --arg run "$GITHUB_RUN_ID" --arg image "$rollback_image" --arg digest "$rollback_digest" \
  --arg platform "$rollback_platform" --arg base "$base_ref" \
  --arg dockerfile "$(sha256sum "$context/Dockerfile" | awk '{print $1}')" \
  --arg composer "$(sha256sum "$context/composer.json" | awk '{print $1}')" \
  --arg lock "$(sha256sum "$context/composer.lock" | awk '{print $1}')" \
  --arg archive "$(basename "$rollback_archive")" --arg archive_sha "$rollback_archive_sha" \
  --arg sbom "$(basename "$rollback_sbom")" --arg sbom_sha "$rollback_sbom_sha" \
  '{
    schemaVersion:1,kind:"raven23.rollback-container-build-provenance",
    source:{repository:$repo,commit:$commit},
    builder:{workflowCommit:$workflow_commit,runId:$run},
    image:{tag:$image,configDigest:$digest,platform:$platform},
    materials:{runtimeBase:$base,dockerfileSha256:$dockerfile,composerJsonSha256:$composer,composerLockSha256:$lock},
    artifacts:{dockerArchive:{file:$archive,sha256:$archive_sha},sbom:{file:$sbom,format:"CycloneDX JSON",sha256:$sbom_sha}}
  }' > "$evidence/raven23-backend-rollback.provenance.json"

port() { python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()'; }
current_port="$(port)"
rollback_port="$(port)"
proxy_port="$(port)"
while [ "$current_port" = "$rollback_port" ] || [ "$current_port" = "$proxy_port" ] || [ "$rollback_port" = "$proxy_port" ]; do proxy_port="$(port)"; done

run_image() {
  docker run -d --name "$1" -p "127.0.0.1:$2:10000" \
    -e APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' -e APP_ENV=production -e APP_DEBUG=false \
    -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=1 -e DB_DATABASE=unavailable \
    -e DB_USERNAME=unavailable -e DB_PASSWORD=unavailable -e DB_URL= \
    -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync "$3" >/dev/null
}
wait_up() {
  for attempt in $(seq 1 45); do
    if curl --silent --show-error --fail --max-time 3 "http://127.0.0.1:$2/up" >/dev/null; then return 0; fi
    sleep 2
  done
  docker logs "$1" || true
  return 1
}
run_image "$current_name" "$current_port" "$current_image"
run_image "$rollback_name" "$rollback_port" "$rollback_image"
wait_up "$current_name" "$current_port"
wait_up "$rollback_name" "$rollback_port"
for port_value in "$current_port" "$rollback_port"; do
  ready="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 4 "http://127.0.0.1:$port_value/ready" || true)"
  test "$ready" = "503"
done

printf '%s\n' "$current_port" > "$active_file"
python3 "$GITHUB_WORKSPACE/scripts/rollback-traffic-proxy.py" --backend-file "$active_file" --port "$proxy_port" > "$proxy_log" 2>&1 &
proxy_pid=$!
probe() {
  headers="$(mktemp)"
  body="$(mktemp)"
  status="$(curl -sS -o "$body" -D "$headers" -w '%{http_code}' --max-time 5 "http://127.0.0.1:$proxy_port/up" || true)"
  ok=false
  if [ "$status" = "200" ] && grep -Eiq "^X-RAVEN23-Backend-Port:[[:space:]]*$1\r?$" "$headers"; then ok=true; fi
  if [ "$ok" != "true" ]; then cat "$headers" "$body" "$proxy_log" >&2 || true; fi
  rm -f "$headers" "$body"
  test "$ok" = "true"
}
proxy_ready=false
for attempt in $(seq 1 30); do
  if probe "$current_port" >/dev/null 2>&1; then proxy_ready=true; break; fi
  sleep 1
done
test "$proxy_ready" = "true"
probe "$current_port"

docker stop "$current_name" >/dev/null
failed_status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 4 "http://127.0.0.1:$proxy_port/up" || true)"
test "$failed_status" = "502"
printf '%s\n' "$rollback_port" > "$active_file.next"
mv "$active_file.next" "$active_file"
probe "$rollback_port"

jq -n \
  --arg current "$GITHUB_SHA" --arg current_image "$current_image" --arg current_digest "$current_digest" \
  --arg old "$rollback_sha" --arg old_image "$rollback_image" --arg old_digest "$rollback_digest" \
  --arg before "$current_port" --arg failed "$failed_status" --arg after "$rollback_port" \
  --arg timestamp "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  '{
    schemaVersion:1,kind:"raven23.isolated-rollback-rehearsal",result:"passed",timestampUtc:$timestamp,
    trigger:"stopped current candidate container to simulate release failure",
    currentRelease:{commit:$current,image:$current_image,configDigest:$current_digest,runtimeUser:"raven:raven",readinessWithoutDatabase:503},
    rollbackTarget:{commit:$old,image:$old_image,configDigest:$old_digest,runtimeUser:"raven:raven",readinessWithoutDatabase:503},
    trafficSwitch:{implementation:"loopback-only HTTP proxy with atomic backend-port file",beforeFailureBackendPort:$before,simulatedFailureStatus:($failed|tonumber),afterRollbackBackendPort:$after,postRollbackHttpStatus:200,outcome:"PASS"},
    safety:{productionDatabaseUsed:false,productionSecretsUsed:false,deploymentPerformed:false,registryPushPerformed:false}
  }' > "$evidence/rollback-rehearsal.json"

printf 'rollback_sha=%s\n' "$rollback_sha" >> "$GITHUB_OUTPUT"
printf 'rollback_image_tag=%s\n' "$rollback_image" >> "$GITHUB_OUTPUT"
printf 'rollback_config_digest=%s\n' "$rollback_digest" >> "$GITHUB_OUTPUT"
printf 'rollback_platform=%s\n' "$rollback_platform" >> "$GITHUB_OUTPUT"
printf 'rollback_archive_file=%s\n' "$(basename "$rollback_archive")" >> "$GITHUB_OUTPUT"
printf 'rollback_archive_sha256=%s\n' "$rollback_archive_sha" >> "$GITHUB_OUTPUT"
printf 'rollback_sbom_sha256=%s\n' "$rollback_sbom_sha" >> "$GITHUB_OUTPUT"
printf 'current_image_tag=%s\n' "$current_image" >> "$GITHUB_OUTPUT"
printf 'current_config_digest=%s\n' "$current_digest" >> "$GITHUB_OUTPUT"
printf 'current_platform=%s\n' "$current_platform" >> "$GITHUB_OUTPUT"
{
  echo "## Isolated rollback rehearsal"
  echo "- Current commit $GITHUB_SHA: config digest $current_digest"
  echo "- Previous commit $rollback_sha: config digest $rollback_digest"
  echo "- HTTP route: current 200 → simulated failure 502 → rollback 200"
  echo "- Both containers ran non-root; readiness returned 503 without a database."
  echo "- No production database/secrets, deployment, registry push or AWS resource."
} >> "$GITHUB_STEP_SUMMARY"
