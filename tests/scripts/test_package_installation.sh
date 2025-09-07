#!/usr/bin/env bash
set -euo pipefail

echo "Testing Glueful Package Installation..."

TEST_DIR=$(mktemp -d)
trap 'rm -rf "$TEST_DIR"' EXIT
cd "$TEST_DIR"

echo "Test directory: $TEST_DIR"

echo "Creating new project from skeleton..."
if ! command -v composer >/dev/null 2>&1; then
  echo "Composer not found. Please install Composer to run this script." >&2
  exit 1
fi

# This step requires network access; keep for local CI usage
composer create-project glueful/api test-project --prefer-source --no-interaction

cd test-project

echo "Checking framework dependency..."
composer show glueful/framework >/dev/null 2>&1 || { echo "ERROR: glueful/framework not installed"; exit 1; }

echo "Checking essential files..."
required_files=(
  "glueful"
  "bootstrap/app.php"
  "public/index.php"
  "config/app.php"
  "routes/api.php"
)
for file in "${required_files[@]}"; do
  [[ -f "$file" ]] || { echo "ERROR: Required file missing: $file"; exit 1; }
done

echo "Testing CLI command..."
./glueful --version

echo "Running system check..."
./glueful system:check

echo "Starting development server..."
./glueful serve --port=8080 &
SERVER_PID=$!
sleep 3

curl -fsS http://localhost:8080/ >/dev/null
curl -fsS http://localhost:8080/health >/dev/null

kill $SERVER_PID 2>/dev/null || true

echo "Running application tests if configured..."
if composer run-script --list | grep -q "test"; then
  composer test
fi

echo "✅ All package installation tests passed!"

