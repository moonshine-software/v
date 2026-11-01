#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cleanup() {
  bash "$ROOT_DIR/examples/stop-apis.sh" >/dev/null 2>&1 || true
}

trap cleanup EXIT

echo "[1/6] Starting example APIs..."
bash "$ROOT_DIR/examples/start-apis.sh"

echo "[2/6] Waiting for API health checks..."
wait_health() {
  local url="$1"
  local attempts=30
  local i
  for ((i=1; i<=attempts; i++)); do
    if curl -s "$url" >/dev/null; then
      return 0
    fi
    sleep 1
  done
  echo "Health check failed: $url"
  return 1
}

wait_health "http://127.0.0.1:9101/health"
wait_health "http://127.0.0.1:9102/health"

echo "[3/6] Running MCP scenario (register services + generate resources)..."
php "$ROOT_DIR/examples/run-mcp-scenario.php"

echo "[4/6] Validating generated config..."
grep -q "'blog'" "$ROOT_DIR/storage/api-admin/generated/services.php"
grep -q "'shop'" "$ROOT_DIR/storage/api-admin/generated/services.php"

echo "[5/6] Validating generated resources..."
POST_RESOURCE="$ROOT_DIR/app/MoonShine/ApiAdmin/Resources/Blog/Post/PostResource.php"
POST_INDEX_PAGE="$ROOT_DIR/app/MoonShine/ApiAdmin/Resources/Blog/Post/Pages/PostIndexPage.php"
PRODUCT_RESOURCE="$ROOT_DIR/app/MoonShine/ApiAdmin/Resources/Shop/Product/ProductResource.php"

grep -q "class PostResource" "$POST_RESOURCE"
grep -q "RestBelongsTo::make" "$POST_INDEX_PAGE"
grep -q "RestHasMany::make" "$POST_INDEX_PAGE"
grep -q "RestHasOne::make" "$POST_INDEX_PAGE"
grep -q "RestBelongsToMany::make" "$POST_INDEX_PAGE"
grep -q "class ProductResource" "$PRODUCT_RESOURCE"

echo "[6/6] Validating MoonShine resource registration..."
RESOURCES_JSON="$(php "$ROOT_DIR/artisan" moonshine:resources --json)"
echo "$RESOURCES_JSON" | grep -q 'App\\\\MoonShine\\\\ApiAdmin\\\\Resources\\\\Blog\\\\Post\\\\PostResource'
echo "$RESOURCES_JSON" | grep -q 'App\\\\MoonShine\\\\ApiAdmin\\\\Resources\\\\Shop\\\\Product\\\\ProductResource'

echo
echo "QA SMOKE: PASS"
echo "- APIs: blog-api(:9101), shop-api(:9102)"
echo "- Services generated: blog, shop"
echo "- Resources generated and registered in MoonShine: PostResource, ProductResource"
