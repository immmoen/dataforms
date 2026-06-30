#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Per-touched-file coverage gate (the "gate B" of docs/DECISIONS.md).
#
# Every PRODUCTION source file *touched* in a PR must be 100% covered — the whole
# file, not just the changed lines. This is stricter than the per-line diff-cover
# and is what drives the codebase to 100% as files get edited.
#
# Single source of truth for scope: the phpunit `<source>` and vitest
# `coverage.include/exclude`. A changed file that is OUT of scope simply never
# appears in the coverage reports, so it is skipped here — no second exclude list
# to maintain. A file with no executable lines (interface, constants) trivially
# passes (0/0). Defensive dead lines marked `@codeCoverageIgnore` / `c8 ignore`
# are already absent from the reports, so they don't count against 100%.
#
# PHP coverage is the UNION of the unit clover and the integration clover (mappers
# are measured only by the integration suite), so a touched mapper is judged on
# its real, integration-tested coverage.
#
# Usage:
#   touched_files_coverage.py --base <ref> \
#       [--clover unit.xml] [--clover integration.xml] [--lcov coverage/lcov.info]
#
# Exits non-zero (and prints a table) if any in-scope touched file is < 100%.
import argparse
import subprocess
import sys
import xml.etree.ElementTree as ET


def changed_files(base):
    """Production-relevant files changed vs the merge-base, excluding deletions."""
    merge_base = subprocess.run(
        ["git", "merge-base", base, "HEAD"], capture_output=True, text=True
    ).stdout.strip() or base
    out = subprocess.run(
        ["git", "diff", "--name-only", "--diff-filter=d", f"{merge_base}...HEAD"],
        capture_output=True, text=True, check=True,
    ).stdout.splitlines()
    return [f.strip() for f in out if f.strip()]


def parse_clover(path, cov):
    """Merge a clover report into cov: { relpath_endswith_key: {line: covered} }.

    Stored under the absolute path from the report; matched later by suffix. Union
    semantics: a line is covered if hit (count>0) in ANY merged report.
    """
    try:
        root = ET.parse(path).getroot()
    except (OSError, ET.ParseError):
        return
    for fileel in root.iter("file"):
        name = fileel.get("name")
        if not name:
            continue
        lines = cov.setdefault(name, {})
        for line in fileel.findall("line"):
            if line.get("type") != "stmt":
                continue
            n = int(line.get("num"))
            hit = int(line.get("count", "0")) > 0
            lines[n] = lines.get(n, False) or hit


def parse_lcov(path, cov):
    """Merge an lcov report into cov keyed by its SF path (relative or absolute)."""
    try:
        text = open(path, encoding="utf-8").read()
    except OSError:
        return
    cur = None
    for raw in text.splitlines():
        if raw.startswith("SF:"):
            cur = cov.setdefault(raw[3:].strip(), {})  # cur IS the stored dict
        elif raw.startswith("DA:") and cur is not None:
            num, _, count = raw[3:].partition(",")
            n = int(num)
            cur[n] = cur.get(n, False) or int(count) > 0
        elif raw == "end_of_record":
            cur = None


def pct_for(relpath, cov):
    """Return (covered, total) unioning every report entry that ends with relpath.

    The unit and integration clovers reference the same file by DIFFERENT absolute
    paths (e.g. /app/... vs /var/www/.../custom_apps/...), so a mapper is 0% in the
    unit clover and ~100% in the integration one. Merge them: a line counts as
    covered if covered in ANY matching entry.
    """
    union = {}
    matched = False
    for path, lines in cov.items():
        norm = path.replace("\\", "/")
        if norm == relpath or norm.endswith("/" + relpath):
            matched = True
            for n, hit in lines.items():
                union[n] = union.get(n, False) or hit
    if not matched:
        return None  # out of scope / not measured → skip
    total = len(union)
    covered = sum(1 for hit in union.values() if hit)
    return covered, total


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", required=True)
    ap.add_argument("--clover", action="append", default=[])
    ap.add_argument("--lcov", action="append", default=[])
    args = ap.parse_args()

    php_cov, js_cov = {}, {}
    for c in args.clover:
        parse_clover(c, php_cov)
    for lv in args.lcov:
        parse_lcov(lv, js_cov)

    rows, failures = [], []
    for f in changed_files(args.base):
        if f.endswith(".php"):
            res = pct_for(f, php_cov)
        elif f.endswith((".js", ".vue")):
            res = pct_for(f, js_cov)
        else:
            continue
        if res is None:
            continue  # out of scope (excluded / no executable lines)
        covered, total = res
        pct = 100.0 if total == 0 else 100.0 * covered / total
        ok = covered == total
        rows.append((f, covered, total, pct, ok))
        if not ok:
            failures.append(f)

    if not rows:
        print("No in-scope production files touched — gate B passes.")
        return 0

    print("## Touched-file coverage (gate B — every touched file must be 100%)\n")
    print("| File | Covered | Total | % | |")
    print("|---|---:|---:|---:|:--:|")
    for f, c, t, p, ok in sorted(rows, key=lambda r: (r[4], r[0])):
        print(f"| `{f}` | {c} | {t} | {p:.1f}% | {'✅' if ok else '❌'} |")

    if failures:
        print(f"\n**{len(failures)} touched file(s) below 100%:** " + ", ".join(f"`{x}`" for x in failures))
        print("\nCover the whole file, or mark provably-dead defensive lines "
              "`@codeCoverageIgnore` / `/* c8 ignore */` (see docs/DECISIONS.md).")
        return 1
    print(f"\nAll {len(rows)} touched production file(s) at 100%. ✅")
    return 0


if __name__ == "__main__":
    sys.exit(main())
