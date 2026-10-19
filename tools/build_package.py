#!/usr/bin/env python3
"""Build the local LoxBerry plugin ZIP from source files only."""
import configparser
import hashlib
from pathlib import Path
import stat
import zipfile

ROOT = Path(__file__).resolve().parents[1]
config = configparser.ConfigParser()
config.read(ROOT / "plugin.cfg")
version = config["PLUGIN"]["VERSION"]
name = config["PLUGIN"]["FOLDER"]
root_name = name + "-" + version
output = ROOT / "dist" / (root_name + "-loxberry4.zip")
output.parent.mkdir(exist_ok=True)
directories = ("cron", "dpkg", "icons", "templates", "uninstall", "webfrontend", "tests", "tools")
files = [ROOT / item for item in (
    "plugin.cfg", "preinstall.sh", "postinstall.sh", "preupgrade.sh", "postupgrade.sh",
    "release.cfg", "prerelease.cfg", "README.md", "CHANGELOG.md", "LICENSE",
)]
for directory in directories:
    files.extend(item for item in (ROOT / directory).rglob("*")
                 if item.is_file() and "__pycache__" not in item.parts)
with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for source in sorted(files):
        relative = source.relative_to(ROOT).as_posix()
        info = zipfile.ZipInfo(root_name + "/" + relative, date_time=(2026, 10, 17, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = (stat.S_IFREG | (source.stat().st_mode & 0o777)) << 16
        archive.writestr(info, source.read_bytes())
with zipfile.ZipFile(output) as archive:
    assert archive.testzip() is None, "Corrupt ZIP entry"
    for relative in ("plugin.cfg", "dpkg/apt13", "postinstall.sh", "webfrontend/html/.htaccess", "webfrontend/html/backups/.htaccess", "webfrontend/html/bootstrap.php"):
        assert root_name + "/" + relative in archive.namelist(), relative
checksum = hashlib.sha256(output.read_bytes()).hexdigest()
output.with_suffix(".zip.sha256").write_text(checksum + "  " + output.name + "\n")
print(str(output))
print(str(len(files)) + " files; " + str(output.stat().st_size) + " bytes")
print("SHA256 " + checksum)
