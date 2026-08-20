"""Structural checker for the Flutter app.

No Dart SDK is reachable from this environment, so this catches the classes
of error a compiler would: unbalanced delimiters, imports that point at
nothing, and identifiers used but never declared anywhere in the project.
It is not a type checker and does not pretend to be.
"""
import pathlib, re, sys

ROOT = pathlib.Path('lib')
FILES = sorted(list(ROOT.rglob('*.dart')) + list(pathlib.Path('test').rglob('*.dart')))
problems = []

def strip(src: str) -> str:
    """Remove comments and string literals so delimiters can be counted."""
    out, i, n = [], 0, len(src)
    while i < n:
        c = src[i]
        if c == '/' and i + 1 < n and src[i+1] == '/':
            while i < n and src[i] != '\n':
                i += 1
            continue
        if c == '/' and i + 1 < n and src[i+1] == '*':
            i += 2
            while i + 1 < n and not (src[i] == '*' and src[i+1] == '/'):
                i += 1
            i += 2
            continue
        if c in '"\'':
            triple = src[i:i+3] in ('"""', "'''")
            quote = src[i:i+3] if triple else c
            i += len(quote)
            while i < n:
                if src[i] == '\\':
                    i += 2
                    continue
                if src[i:i+len(quote)] == quote:
                    i += len(quote)
                    break
                # Keep interpolation bodies: they contain real delimiters.
                if src[i] == '$' and i + 1 < n and src[i+1] == '{':
                    depth, i = 0, i + 1
                    while i < n:
                        if src[i] == '{': depth += 1
                        elif src[i] == '}':
                            depth -= 1
                            if depth == 0:
                                i += 1
                                break
                        i += 1
                    continue
                i += 1
            out.append('""')
            continue
        out.append(c)
        i += 1
    return ''.join(out)

# 1 ── balanced delimiters
for f in FILES:
    code = strip(f.read_text())
    stack, pairs = [], {')': '(', ']': '[', '}': '{'}
    line = 1
    for ch in code:
        if ch == '\n':
            line += 1
        elif ch in '([{':
            stack.append((ch, line))
        elif ch in ')]}':
            if not stack or stack[-1][0] != pairs[ch]:
                problems.append(f'{f}: unbalanced {ch!r} at line {line}')
                break
            stack.pop()
    else:
        if stack:
            problems.append(f'{f}: {len(stack)} unclosed {stack[-1][0]!r} (line {stack[-1][1]})')

# 2 ── relative imports resolve
for f in FILES:
    for m in re.finditer(r"import\s+'([^']+)'", f.read_text()):
        target = m.group(1)
        if target.startswith(('dart:', 'package:flutter/', 'package:http/',
                              'package:shared_preferences/', 'package:flutter_test/')):
            continue
        if target.startswith('package:serviceproof_field/'):
            resolved = ROOT / target.split('package:serviceproof_field/')[1]
        else:
            resolved = (f.parent / target).resolve()
        if not pathlib.Path(resolved).exists():
            problems.append(f'{f}: import does not resolve -> {target}')

# 3 ── declared vs used project types
declared = set()
for f in FILES:
    for m in re.finditer(r'^(?:abstract\s+)?(?:class|enum|mixin)\s+(\w+)', f.read_text(), re.M):
        declared.add(m.group(1))

PROJECT = {'ApiClient','ApiException','SessionExpired','Offline','Config','Store',
           'SessionUser','Site','WorkOrder','Claim','DecisionState','PendingClaim',
           'AuthRepository','WorkOrderRepository','ClaimRepository','SessionController',
           'SessionStatus','OutboxController','JobsController','SpColors','SpPanel',
           'Eyebrow','StateBadge','InfoRow','EmptyState','OutboxBanner','ConsentNotice',
           'SignInScreen','JobsScreen','JobDetailScreen','SubmitClaimScreen',
           'ClaimOutcomeScreen','ServiceProofFieldApp'}

missing = PROJECT - declared
if missing:
    problems.append(f'declared nowhere: {sorted(missing)}')

# 4 ── every project type used in a file is imported there
for f in FILES:
    src = f.read_text()
    body = strip(src)
    imports = set(re.findall(r"import\s+'([^']+)'", src))
    reachable = set()
    for target in imports:
        if target.startswith('package:serviceproof_field/'):
            path = ROOT / target.split('package:serviceproof_field/')[1]
        elif target.startswith(('dart:', 'package:')):
            continue
        else:
            path = (f.parent / target)
        if path.exists():
            for m in re.finditer(r'^(?:abstract\s+)?(?:class|enum|mixin)\s+(\w+)', path.read_text(), re.M):
                reachable.add(m.group(1))
    own = set(re.findall(r'^(?:abstract\s+)?(?:class|enum|mixin)\s+(\w+)', src, re.M))
    used = set(re.findall(r'\b([A-Z]\w+)\b', body)) & PROJECT
    orphan = used - reachable - own
    if orphan:
        problems.append(f'{f}: used without an import -> {sorted(orphan)}')

print(f'{len(FILES)} Dart files checked')
if problems:
    print('\nPROBLEMS:')
    for p in problems:
        print(' -', p)
    sys.exit(1)
print('balanced delimiters ok · imports resolve ok · no orphan project types')
