#!/usr/bin/env python3
import subprocess,os,json,hashlib
from pathlib import Path
root=Path.cwd()
for file in root.rglob('*.php'):
    if any(p in {'.git','.host','vendor','node_modules','build-php85'} for p in file.relative_to(root).parts):continue
    subprocess.run(['php','-l',str(file)],check=True,stdout=subprocess.DEVNULL)
for file in (root/'tools').glob('*.py'):compile(file.read_text(),str(file),'exec')
for file in root.glob('resources/assets/standalone/*.js'):subprocess.run(['node','--check',str(file)],check=True)
for file in root.glob('plugins/*/assets/*.js'):subprocess.run(['node','--check',str(file)],check=True)
print('PHP, Python and standalone JavaScript syntax checks passed')

assets=root/"resources/assets/vendor/adminlte"
metadata=json.loads((assets/"UPSTREAM.json").read_text())
for name,expected in metadata["sha256"].items():
    assert hashlib.sha256((assets/name).read_bytes()).hexdigest()==expected, f"AdminLTE checksum mismatch: {name}"
