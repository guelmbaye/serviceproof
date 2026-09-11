#!/usr/bin/env python3
"""Every `match ($this)` on a backed enum covers every case.

PHP raises UnhandledMatchError at runtime, never at build time. Adding a case
to an enum and forgetting one of the several `match` statements elsewhere in
the same file therefore produces a 500 on the one code path that uses it — in
our case, a DEVICE_SWAP evidence type that broke Run B on the deployed system
while Run A carried on working.

`php -l` cannot see this. Neither can a reader skimming a file with three
match statements in it.

Run from anywhere:  python3 apps/api/tool/match_check.py
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
ENUMS = ROOT / "apps/api/app"


def main() -> int:
    problems = 0
    matches = 0

    for path in sorted(ENUMS.rglob("Enums/*.php")):
        source = path.read_text()
        cases = re.findall(r"case (\w+) = ", source)

        if not cases:
            continue

        for block in re.finditer(r"match \(\$this\) \{(.*?)\n\s*\};", source, re.S):
            matches += 1
            body = block.group(1)

            # A default arm is a deliberate choice to not enumerate.
            if "default" in body:
                continue

            covered = set(re.findall(r"self::(\w+)", body))
            missing = [case for case in cases if case not in covered]

            if not missing:
                continue

            line = source[: block.start()].count("\n") + 1
            functions = re.findall(r"function (\w+)\(", source[: block.start()])
            name = functions[-1] if functions else "?"

            print(f"  {path.relative_to(ROOT)}:{line}")
            print(f"    {name}() does not handle: {', '.join(missing)}")
            problems += 1

    if problems:
        print(f"\n{problems} incomplete match statement(s). These throw at runtime.")
        return 1

    print(f"{matches} match statements checked · every enum case is handled")
    return 0


if __name__ == "__main__":
    sys.exit(main())
