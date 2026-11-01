"""Verify that each dist archive contains the complete public package only."""

import subprocess
import sys
from pathlib import Path
from zipfile import ZipFile

root = Path(__file__).resolve().parents[2]
expected = {'composer.json', 'LICENSE', 'README.md'} | {
    path for path in subprocess.check_output(
        ['git', 'ls-files', '-z', '--', 'src', 'config', 'lang', 'resources', 'database', 'docs'], cwd=root,
    ).decode().split('\0') if path
}

for filename in sys.argv[1:]:
    with ZipFile(filename) as archive:
        actual = {name for name in archive.namelist() if not name.endswith('/')}
    if actual != expected:
        raise SystemExit(
            f'{filename}: extra={sorted(actual - expected)}, missing={sorted(expected - actual)}'
        )
    print(f'{filename}: exact distribution allowlist verified.')
