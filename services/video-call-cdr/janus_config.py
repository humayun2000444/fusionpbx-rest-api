#!/usr/bin/env python3
"""
Set keys inside named blocks of Janus .jcfg (libconfig) files, in place.

    janus_config.py <file> <block> <key> <value> [<block> <key> <value> ...]

<value> is written verbatim, so quote strings yourself:  admin_ip '"127.0.0.1"'

Only the named keys change. An active line is rewritten, else a commented-out
one ("#key = ...") is uncommented and rewritten, else the key is added as the
first line of the block. Everything else - comments, order, spacing - is kept,
so a diff against the backup shows exactly what was changed.
"""
import re
import sys


def strip_comment(line):
    out, quoted = [], False
    for ch in line:
        if ch == '"':
            quoted = not quoted
        if ch == '#' and not quoted:
            break
        out.append(ch)
    return ''.join(out)


def block_span(lines, block):
    """(open_index, close_index) of 'block: {' ... '}' at the top level."""
    depth, start = 0, None
    for i, line in enumerate(lines):
        code = strip_comment(line)
        if start is None and depth == 0 and re.match(r'^\s*' + re.escape(block) + r'\s*:\s*\{', code):
            start = i
        depth += code.count('{') - code.count('}')
        if start is not None and depth == 0:
            return start, i
    raise SystemExit(f'block "{block}" not found')


def set_key(lines, block, key, value):
    start, end = block_span(lines, block)
    active = re.compile(r'^(\s*)' + re.escape(key) + r'\s*=\s*')
    commented = re.compile(r'^(\s*)#\s*' + re.escape(key) + r'\s*=\s*')
    for pattern in (active, commented):
        for i in range(start + 1, end):
            m = pattern.match(lines[i])
            if m:
                lines[i] = f'{m.group(1)}{key} = {value}\n'
                return
    indent = re.match(r'^(\s*)', lines[start]).group(1) + '\t'
    lines.insert(start + 1, f'{indent}{key} = {value}\n')


def main(argv):
    if len(argv) < 5 or (len(argv) - 2) % 3:
        raise SystemExit(__doc__)
    path = argv[1]
    with open(path) as f:
        lines = f.readlines()
    for i in range(2, len(argv), 3):
        set_key(lines, argv[i], argv[i + 1], argv[i + 2])
    with open(path, 'w') as f:
        f.writelines(lines)


if __name__ == '__main__':
    main(sys.argv)
