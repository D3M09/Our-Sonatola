#!/usr/bin/env bash
# check-todo.sh — read-only gate: counts _TODO locale placeholders.
# Exits 0 when none remain, 1 when any remain (production deploy BLOCKED).
# Phase A1 acceptance is NOT blocked by _TODO values existing.
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
count=0
for f in "$ROOT/public/js/locales/bn.json" "$ROOT/public/js/locales/en.json"; do
  if [ -f "$f" ]; then
    n=$(grep -o "_TODO" "$f" | wc -l)
    echo "$f: $n _TODO marker(s)"
    count=$((count + n))
  else
    echo "$f: MISSING"
  fi
done
echo "total: $count _TODO marker(s)"
if [ "$count" -gt 0 ]; then
  exit 1
fi
exit 0
