#!/usr/bin/env python3
"""Cross-layer contract checks that no single-language linter can make.

ServiceProof declares the same facts in three languages. PHP produces the
context bundle, Python validates it, TypeScript renders the result. Nothing in
any one toolchain notices when they drift, and every time they have drifted the
symptom appeared on the deployed system rather than in a test:

  * an entitlement gate enforced in the agent before Laravel populated the
    field, which refused every verification with zero API calls;
  * DEVICE_SWAP added to a Laravel enum and a Pydantic Literal at different
    times, failing with a validation error mid-run.

Both checks below exist because of a specific outage, not a hypothesis.

Run from anywhere:  python3 apps/agent/tool/contract_check.py
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

# Resolve from this file, not the working directory: a checker that only runs
# from the repository root is a checker nobody runs.
ROOT = Path(__file__).resolve().parents[3]

AGENT_SCHEMAS = ROOT / "apps/agent/app/schemas.py"
LARAVEL_APP = ROOT / "apps/api/app"
EVIDENCE_ENUM = LARAVEL_APP / "Domain/Shared/Enums/EvidenceType.php"


def _model_fields(source: str, cls: str) -> list[tuple[str, bool]]:
    """Field names of a Pydantic model, with whether each has a default."""
    block = re.search(rf"class {cls}\(BaseModel\):(.*?)(?=\nclass |\Z)", source, re.S)

    if not block:
        return []

    fields = []
    for line in block.group(1).splitlines():
        match = re.match(r"\s{4}(\w+):\s*([^=]+?)(\s*=\s*(.+))?$", line)
        if match and not match.group(1).startswith("_"):
            fields.append((match.group(1), match.group(3) is not None))

    return fields


def check_bundle_fields() -> int:
    """Every field the agent reads is emitted somewhere in Laravel."""
    agent = AGENT_SCHEMAS.read_text()
    php = "\n".join(p.read_text() for p in LARAVEL_APP.rglob("*.php"))

    print("=== Context bundle fields ===")
    problems = 0

    for cls in ("DeviceIn", "ClaimIn", "PolicyIn", "BudgetIn", "EntitlementIn"):
        for name, has_default in _model_fields(agent, cls):
            if f"'{name}'" in php:
                continue

            # A field with no default breaks the request outright. One with a
            # default is silently substituted, which is far harder to diagnose:
            # the run succeeds and quietly does the wrong thing.
            severity = "missing" if not has_default else "silently defaulted"
            print(f"  {cls}.{name} is {severity} — Laravel never emits it")
            problems += 1

    if not problems:
        print("  every field the agent expects is produced by Laravel")

    return problems


def check_evidence_vocabulary() -> int:
    """Evidence types agree between the Laravel enum and the agent schema."""
    laravel = set(re.findall(r"case (\w+) = '", EVIDENCE_ENUM.read_text()))

    literal = re.search(
        r"EvidenceTypeStr = Literal\[(.*?)\]", AGENT_SCHEMAS.read_text(), re.S
    )
    agent = set(re.findall(r'"(\w+)"', literal.group(1))) if literal else set()

    print("\n=== Evidence type vocabulary ===")
    problems = 0

    for name in sorted(laravel | agent):
        if name in laravel and name in agent:
            continue

        where = "the agent schema" if name in laravel else "the Laravel enum"
        print(f"  {name} is missing from {where}")
        problems += 1

    if not problems:
        print(f"  {len(laravel)} types, identical on both sides")

    return problems


def main() -> int:
    problems = check_bundle_fields() + check_evidence_vocabulary()

    if problems:
        print(f"\n{problems} contract mismatch(es). These fail at runtime, not at build.")
        return 1

    print("\nContracts agree across layers.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
