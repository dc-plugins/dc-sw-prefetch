#!/usr/bin/env bash
# scripts/update-partytown.sh
# ---------------------------------------------------------------
# Vendor the latest (or a pinned) @qwik.dev/partytown lib build
# into assets/partytown/ and update the version in package.json.
#
# Usage:
#   bash scripts/update-partytown.sh            # latest
#   bash scripts/update-partytown.sh 0.11.2     # pinned version
# ---------------------------------------------------------------
set -euo pipefail

DEST="assets/partytown"
PKG="package.json"
NPM_PKG="@qwik.dev/partytown"
NPM_SLUG="@qwik.dev/partytown/-/partytown"   # tarball path segment

# ── Resolve target version ────────────────────────────────────
if [ -n "${1:-}" ]; then
  VERSION="$1"
else
  echo "→ Fetching latest ${NPM_PKG} version from npm…"
  VERSION=$(curl -fsSL "https://registry.npmjs.org/${NPM_PKG}/latest" \
    | grep -o '"version":"[^"]*"' | head -1 | cut -d'"' -f4)
fi
echo "→ Target version: $VERSION"

# ── Compare to currently vendored version ─────────────────────
CURRENT=$(grep -o '"@qwik.dev/partytown": "[^"]*"' "$PKG" 2>/dev/null \
          | cut -d'"' -f4 || echo "none")
if [ "$CURRENT" = "$VERSION" ]; then
  echo "✓ Already at $VERSION — nothing to do."
  exit 0
fi

# Prevent accidental downgrades (e.g. when a pre-release is vendored from source
# but npm still reports an older version as "latest").
if [ "$CURRENT" != "none" ]; then
  HIGHER=$(printf '%s\n%s' "$CURRENT" "$VERSION" | sort -V | tail -1)
  if [ "$HIGHER" != "$VERSION" ]; then
    echo "⚠️  Target $VERSION is older than current $CURRENT — refusing downgrade."
    exit 0
  fi
fi

# ── Download & extract tarball ────────────────────────────────
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

echo "→ Downloading partytown-${VERSION}.tgz…"
curl -fsSL "https://registry.npmjs.org/${NPM_SLUG}-${VERSION}.tgz" \
  -o "$TMP/partytown.tgz"

echo "→ Extracting lib/ files…"
mkdir -p "$TMP/pkg"
tar -xzf "$TMP/partytown.tgz" -C "$TMP/pkg" --strip-components=1

# ── Copy the lib/ tree into assets/partytown/ ─────────────────
rm -rf "$DEST"
mkdir -p "$DEST"
cp -r "$TMP/pkg/lib/." "$DEST/"
echo "→ Copied lib/ → $DEST/"

# ── Bump version in package.json vendored block ───────────────
sed -i.bak "s|\"@qwik.dev/partytown\": \"${CURRENT}\"|\"@qwik.dev/partytown\": \"${VERSION}\"|g" "$PKG"
rm -f "$PKG.bak"
echo "→ package.json updated: $CURRENT → $VERSION"

# ── Reduce SAB from 1 GB → 256 MB in atomics bundles ─────────
# Partytown defaults to new SharedArrayBuffer(1073741824) which hits the
# heap limit on many devices. 256 MB is sufficient for Atomics messaging.
SAB_FILES=(
  "$DEST/partytown-atomics.js"
  "$DEST/debug/partytown-atomics.js"
)
for f in "${SAB_FILES[@]}"; do
  if [ -f "$f" ]; then
    sed -i 's/SharedArrayBuffer(1073741824)/SharedArrayBuffer(268435456)/g' "$f"
    echo "→ Patched SAB size in $f"
  fi
done

echo ""
echo "✅ Partytown $VERSION vendored in $DEST/"
echo "   Commit with:"
echo "     git add assets/partytown package.json"
echo "     git commit -m \"chore: vendor partytown $VERSION\""
echo "     git add assets/partytown package.json"
echo "     git commit -m \"chore: vendor partytown $VERSION\""
