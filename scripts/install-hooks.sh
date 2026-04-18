#!/usr/bin/env bash
# Usage (repo root):  bash scripts/install-hooks.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
git config core.hooksPath scripts/git-hooks
echo "OK: core.hooksPath = scripts/git-hooks"
