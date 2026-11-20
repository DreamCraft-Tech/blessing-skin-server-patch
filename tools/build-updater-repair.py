#!/usr/bin/env python3
"""Build the small updater bootstrap for existing patch installations."""
from pathlib import Path
import argparse, zipfile, hashlib
parser = argparse.ArgumentParser()
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
files = ['app/Http/Controllers/UpdateController.php', 'app/Http/Middleware/DetectLanguagePrefer.php', 'app/Services/UpdateTasks.php', 'app/Services/UpdatePackage.php', 'app/Services/UploadLimits.php']
args.output.mkdir(parents=True, exist_ok=True)
archive = args.output / 'updater-repair_6.1.0.1.zip'
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as bundle:
    for name in files:
        bundle.write(root / name, name)
    bundle.write(root / 'docs/UPDATE-6.1.0.1.md', 'UPDATER-REPAIR.md')
checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix('.zip.sha256').write_text(checksum + '  ' + archive.name + '\n')
print(archive)
