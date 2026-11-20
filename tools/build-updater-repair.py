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
        if name == 'app/Http/Controllers/UpdateController.php':
            source = (root / name).read_text()
            source = source.replace("        try {\n            $packages->install($input['id']);", "        $success = redirect()->route('admin.update.view');\n        $failure = redirect()->route('admin.update.view');\n        try {\n            Artisan::call('view:clear');\n            Artisan::call('twig:clean');\n            $filesystem->delete(base_path('bootstrap/cache/packages.php'));\n            $filesystem->delete(base_path('bootstrap/cache/services.php'));\n            $packages->install($input['id']);")
            source = source.replace("            Artisan::call('view:clear');\n            Artisan::call('twig:clean');\n            $request->session()->forget", "            $request->session()->forget")
            source = source.replace("return redirect()->route('admin.update.view')->with('update_notice', '系统程序已更新", "return $success->with('update_notice', '系统程序已更新")
            source = source.replace("return redirect()->route('admin.update.view')->with('update_error', $e instanceof \\RuntimeException ? $e->getMessage() : '安装失败", "return $failure->with('update_error', $e instanceof \\RuntimeException ? $e->getMessage() : '安装失败")
            assert '$success = ' in source and 'return $success->with' in source and 'return $failure->with' in source
            bundle.writestr(name, source)
        else:
            bundle.write(root / name, name)
    bundle.write(root / 'docs/UPDATE-6.1.0.1.md', 'UPDATER-REPAIR.md')
checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix('.zip.sha256').write_text(checksum + '  ' + archive.name + '\n')
print(archive)
