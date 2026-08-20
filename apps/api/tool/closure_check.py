#!/usr/bin/env python3
"""Every variable used inside a closure is captured, a parameter, or local.

PHP closures capture nothing implicitly. Reading an outer variable without
naming it in `use (...)` is a runtime error, and `php -l` cannot see it —
the syntax is perfectly valid. This is what shipped a broken verification:
a `$trail` array built in verify() and read inside DB::transaction's closure,
which threw "Undefined variable $trail" on the first live run.

Run from apps/api:  python3 tool/closure_check.py
"""

import re
import sys
from pathlib import Path

SUPERGLOBALS = {
    "$this", "$_GET", "$_POST", "$_SERVER", "$_ENV",
    "$_SESSION", "$_FILES", "$_COOKIE", "$_REQUEST", "$GLOBALS",
}


def closure_body(src: str, start: int) -> str:
    """The text between the closure's braces, by depth counting."""
    depth, j = 0, start
    while j < len(src):
        if src[j] == "{":
            depth += 1
        elif src[j] == "}":
            depth -= 1
            if depth == 0:
                return src[start + 1:j]
        j += 1
    return ""


def check(path: Path) -> list[tuple[int, list[str]]]:
    src = path.read_text()
    found = []

    for m in re.finditer(r"function\s*\(([^)]*)\)(?:\s*use\s*\(([^)]*)\))?\s*\{", src):
        params = set(re.findall(r"\$\w+", m.group(1) or ""))
        captured = set(re.findall(r"\$\w+", m.group(2) or ""))
        body = closure_body(src, m.end() - 1)

        local = set(re.findall(r"(\$\w+)\s*=(?!=)", body))
        local |= set(re.findall(r"as\s+(\$\w+)", body))
        local |= set(re.findall(r"\[\s*(\$\w+)", body))

        # Nested closures bring their own parameters and captures.
        for inner in re.finditer(r"(?:function|fn)\s*\(([^)]*)\)", body):
            local |= set(re.findall(r"\$\w+", inner.group(1)))
        for inner in re.finditer(r"use\s*\(([^)]*)\)", body):
            local |= set(re.findall(r"\$\w+", inner.group(1)))

        unresolved = set(re.findall(r"\$\w+", body)) - params - captured - local - SUPERGLOBALS

        if unresolved:
            found.append((src[:m.start()].count("\n") + 1, sorted(unresolved)))

    return found


def main() -> int:
    problems = 0
    scanned = 0

    for path in sorted(Path("app").rglob("*.php")):
        for line, variables in check(path):
            print(f"  {path}:{line} uses {', '.join(variables)} without capturing them")
            problems += 1
        scanned += 1

    if problems:
        print(f"\n{problems} closure(s) read an uncaptured variable.")
        return 1

    print(f"{scanned} files checked · every closure variable is captured or local")
    return 0


if __name__ == "__main__":
    sys.exit(main())
