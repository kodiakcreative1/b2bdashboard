#!/usr/bin/env python3
"""Deterministic release packaging; runtime ZIP never includes tests or QA data."""
from pathlib import Path
import hashlib
import shutil
import stat
import zipfile

ROOT = Path(__file__).resolve().parent.parent
VERSION = '0.1.1'
PLUGIN = ROOT / 'chamber-b2b-dashboard'
DIST = ROOT / 'dist'
STAMP = (2026, 11, 3, 12, 0, 0)

def archive(target, files, prefix=''):
    with zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for path in sorted(files):
            if path.is_symlink():
                raise ValueError(f'Refusing symlink: {path}')
            name = prefix + path.relative_to(ROOT).as_posix()
            if '..' in Path(name).parts:
                raise ValueError('Unsafe archive path')
            info = zipfile.ZipInfo(name, STAMP)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = ((stat.S_IFREG | (0o755 if path.suffix == '.sh' else 0o644)) << 16)
            z.writestr(info, path.read_bytes())
    with zipfile.ZipFile(target) as z:
        assert z.testzip() is None

if __name__ == '__main__':
    DIST.mkdir(exist_ok=True)
    for name in ('README.md', 'QA-SUMMARY.md'):
        shutil.copyfile(ROOT / name, PLUGIN / name)
    plugin_files = [p for p in PLUGIN.rglob('*') if p.is_file()]
    assert all('tests' not in p.relative_to(PLUGIN).parts for p in plugin_files)
    runtime = DIST / f'chamber-b2b-dashboard-v{VERSION}.zip'
    archive(runtime, plugin_files)
    files = plugin_files + [ROOT/'README.md', ROOT/'QA-SUMMARY.md']
    for folder in ('tests', 'tools', 'artifacts'):
        files += [p for p in (ROOT/folder).rglob('*') if p.is_file() and '__pycache__' not in p.parts]
    source = DIST / f'chamber-b2b-dashboard-source-v{VERSION}.zip'
    archive(source, files, f'chamber-b2b-dashboard-source-v{VERSION}/')
    lines = [f'{hashlib.sha256(p.read_bytes()).hexdigest()}  {p.name}' for p in (runtime, source)]
    (DIST/'SHA256SUMS').write_text('\n'.join(lines)+'\n')
    for p in (runtime, source):
        print(f'{p.name}: {p.stat().st_size:,} bytes')
