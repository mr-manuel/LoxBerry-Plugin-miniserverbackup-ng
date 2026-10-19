#!/usr/bin/env python3
"""Exercise installation and entry points in a disposable LoxBerry tree.

Run: python3 tests/install_smoke.py
Requires a local LoxBerry core at /opt/loxberry (override LOXBERRY_TEST_CORE),
PHP CLI, Perl, and their existing LoxBerry modules. Only libs and system
HTML templates are read through symlinks; configuration, databases, logs,
plugins, and state files are synthetic and live in TemporaryDirectory.
The fixture redirects literal /tmp paths in copied plugin sources. It never
installs a plugin, contacts a Miniserver, or invokes the system cron installer.
"""

import json
import os
from pathlib import Path
import re
import shutil
import sqlite3
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
CORE = Path(os.environ.get("LOXBERRY_TEST_CORE", "/opt/loxberry"))
FOLDER = "miniserverbackup-ng"
VERSION = re.search(r"^VERSION=(.+)$", (ROOT / "plugin.cfg").read_text(), re.M)[1]
BLOCKED_PHP = "system,exec,shell_exec,passthru,curl_exec,fsockopen,stream_socket_client"


def php_literal(value):
    return "'" + str(value).replace("\\", "\\\\").replace("'", "\\'") + "'"


@unittest.skipUnless(
    (CORE / "libs/phplib/loxberry_system.php").is_file()
    and shutil.which("php") and shutil.which("perl"),
    "A local LoxBerry core, PHP CLI, and Perl are required",
)
class InstallSmoke(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="msbackup-install-smoke-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.base = self.root / "loxberry"
        self.package = self.root / "package"
        self.state = self.root / "tmp"
        self.state.mkdir()
        self.base.mkdir()
        self.package.mkdir()
        self.html = self.base / "webfrontend/html/plugins" / FOLDER
        self.htmlauth = self.base / "webfrontend/htmlauth/plugins" / FOLDER
        self.config = self.base / "config/plugins" / FOLDER / "miniserverbackup-ng.cfg"
        for name in ("webfrontend", "templates"):
            shutil.copytree(ROOT / name, self.package / name)
        for name in ("postinstall.sh", "preinstall.sh"):
            shutil.copy2(ROOT / name, self.package / name)
        # Model the core's placeholder replacement before installation. Redirect
        # global plugin state in this COPY, so no production /tmp file is read.
        for path in self.package.rglob("*"):
            if path.is_file() and (path.suffix in {".php", ".cgi", ".sh"} or path.name == ".htaccess"):
                source = path.read_text()
                source = source.replace("/tmp/", str(self.state) + "/")
                source = source.replace("REPLACELBHOMEDIR", str(self.base))
                path.write_text(source)
        for source, destination in (
            (self.package / "webfrontend/html", self.html),
            (self.package / "webfrontend/htmlauth", self.htmlauth),
            (self.package / "templates", self.base / "templates/plugins" / FOLDER),
        ):
            destination.mkdir(parents=True)
            # The core uses cp -r source/* destination, omitting top-level
            # dotfiles while retaining hidden files inside regular directories.
            for child in source.iterdir():
                if not child.name.startswith("."):
                    target = destination / child.name
                    if child.is_dir():
                        shutil.copytree(child, target)
                    else:
                        shutil.copy2(child, target)
        (self.base / "libs").symlink_to(CORE / "libs", target_is_directory=True)
        (self.base / "templates/system").symlink_to(CORE / "templates/system", target_is_directory=True)
        for directory in (
            "config/system", "data/system", "log/system", "log/system_tmpfs",
            "log/plugins/" + FOLDER, "data/plugins/" + FOLDER,
            "system/storage/usb", "system/cron/cron.d", "sbin",
            "webfrontend/htmlauth/system/tools",
        ):
            (self.base / directory).mkdir(parents=True, exist_ok=True)
        plugin = dict(
            md5="0" * 32, author_name="Smoke Test", author_email="test@example.invalid",
            version=VERSION, name=FOLDER, folder=FOLDER, title="Miniserver Backup NG",
            interface="2.0", autoupdate=0, loglevel=6, loglevels_enabled=1,
        )
        (self.base / "data/system/plugindatabase.json").write_text(json.dumps({"plugins": {"0" * 32: plugin}}))
        self.write_general(miniservers=True)
        self.env = {key: value for key, value in os.environ.items() if not key.startswith(("LBP", "LBS"))}
        self.env.update(
            LBHOMEDIR=str(self.base), TMPDIR=str(self.state),
            PERL5LIB=str(self.base / "libs/perllib"),
            REQUEST_METHOD="GET", QUERY_STRING="", HTTP_HOST="smoke.invalid",
            SERVER_NAME="smoke.invalid", SERVER_PORT="80", SERVER_PROTOCOL="HTTP/1.1",
        )

    def write_general(self, miniservers):
        dummy = dict(
            Name="Smoke Miniserver", Ipaddress="192.0.2.123", Admin="smoke-user",
            Pass="dummy-password", Note="offline fixture", Port="80", Porthttps="443",
            Preferhttps=0, Useclouddns=0, Cloudurlftpport="21", Cloudurl="SMOKE0000001",
            Securegateway=0, Encryptresponse=0,
        )
        general = dict(
            Base=dict(Version="4.0.5.2", Lang="en", Country="de", Systemloglevel=3,
                      Clouddnsuri="https://example.invalid/", Theme="soft-rounded"),
            Timeserver=dict(Timezone="Europe/Berlin"),
            Network=dict(Friendlyname="Isolated LoxBerry"), Webserver=dict(Port=80),
            Miniserver={"1": dummy} if miniservers else {}, Mqtt={},
        )
        (self.base / "config/system/general.json").write_text(json.dumps(general))
        lines = ["[BASE]", "VERSION=4.0.5.2", "LANG=en", "COUNTRY=de",
                 "CLOUDDNS=https://example.invalid/", "MINISERVERS=" + str(int(miniservers)),
                 "", "[BINARIES]", "AWK=/usr/bin/awk"]
        if miniservers:
            lines += ["", "[MINISERVER1]"]
            lines += [key.upper() + '="' + str(value) + '"' for key, value in dummy.items()]
        (self.base / "config/system/general.cfg").write_text("\n".join(lines) + "\n")

    def run_command(self, command, *, env=None, expected=0, cwd=None):
        result = subprocess.run(
            command, env=self.env if env is None else env,
            cwd=cwd or self.root, text=True, stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, timeout=25,
        )
        self.assertEqual(result.returncode, expected, result.stdout + "\n" + result.stderr)
        self.assertNotRegex(result.stdout + result.stderr, r"(?:PHP Fatal error|Uncaught |Software error:|BEGIN failed)")
        return result

    def install(self):
        return self.run_command([
            "/bin/sh", str(self.package / "postinstall.sh"), "smoke-package", FOLDER,
            FOLDER, VERSION, str(self.base), str(self.package),
        ])

    def php(self, script=None, *, expression=None, expected=0, env=None, passthru=False):
        blocked = BLOCKED_PHP.replace("passthru,", "") if passthru else BLOCKED_PHP
        command = ["php", "-d", "disable_functions=" + blocked,
                   "-d", "allow_url_fopen=0", "-d", "log_errors=1"]
        if expression is not None:
            command += ["-r", expression]
        else:
            command.append(str(script))
        return self.run_command(command, env=env, expected=expected)

    def test_install_copies_protection_initializes_off_and_preserves_config(self):
        self.assertFalse((self.html / ".htaccess").exists(), "Core glob model unexpectedly copied root dotfile")
        self.install()
        access = (self.html / ".htaccess").read_text()
        self.assertIn("Require valid-user", access)
        self.assertIn('AuthName "Loxberry Administration"', access)
        self.assertIn("AuthUserFile " + str(self.base / "config/system/htusers.dat"), access)
        self.assertNotIn("REPLACE", access)
        default = self.config.read_text()
        self.assertIn('[MINISERVERBACKUP]', default)
        self.assertIn('MSBACKUP_USE="off"', default)
        self.assertEqual(self.config.stat().st_mode & 0o077, 0)
        existing = default + '\nKEEP_EXISTING="yes"\n'
        self.config.write_text(existing)
        self.install()
        self.assertEqual(self.config.read_text(), existing)
        self.assertFalse(list((self.base / "system/cron/cron.d").iterdir()))

    def test_preinstall_leaves_neighboring_upload_unchanged(self):
        uploads = self.state / "uploads"
        neighbor = uploads / "other-package/neighbor.txt"
        neighbor.parent.mkdir(parents=True)
        neighbor.write_bytes(b"neighbor must keep CRLF\r\nand its mtime\r\n")
        os.utime(neighbor, (1_600_000_000, 1_600_000_000))
        before = (neighbor.read_bytes(), neighbor.stat().st_mtime_ns)
        self.run_command([
            "/bin/sh", str(self.package / "preinstall.sh"), "current-package",
            FOLDER, FOLDER, VERSION, str(self.base), str(self.package),
        ])
        self.assertEqual((neighbor.read_bytes(), neighbor.stat().st_mtime_ns), before)

    def test_bootstrap_finds_actual_core_without_lbhomedir(self):
        environment = dict(self.env)
        environment.pop("LBHOMEDIR", None)
        # Require definitions only. Do not call methods that read configuration,
        # create loggers, or write through the fallback production base path.
        result = self.php(expression=(
            "require " + php_literal(self.html / "bootstrap.php") + ";"
            "if (!class_exists('LBSystem') || !class_exists('LBLog')) exit(2);"
            "echo 'bootstrap-ready';"
        ), env=environment)
        self.assertEqual(result.stdout, "bootstrap-ready")
        self.assertFalse((self.base / "log/system_tmpfs/logs_sqlite.dat").exists())

    def test_api_reads_only_synthetic_miniserver(self):
        self.install()
        result = self.php(expression=(
            "$_REQUEST=['search'=>'192.0.2.123']; require "
            + php_literal(self.html / "API.php") + ";"
        ))
        self.assertIn("never", result.stdout.lower())
        self.assertTrue((self.base / "log/system_tmpfs/logs_sqlite.dat").is_file())
        self.assertNotIn("192.168.", result.stdout)

    def test_config_handler_preserves_config_without_miniservers(self):
        self.install()
        original = self.config.read_bytes()
        self.write_general(miniservers=False)
        result = self.php(self.html / "ajax_config_handler.php")
        self.assertIn("Miniserver", result.stdout)
        self.assertEqual(self.config.read_bytes(), original)
        self.assertFalse(list((self.base / "system/cron/cron.d").iterdir()))
        self.assertFalse((self.state / ("cron_" + FOLDER)).exists())

    def test_backup_stays_disabled_with_actual_core(self):
        self.install()
        self.php(self.html / "createmsbackup.php", expected=1)
        self.assertFalse((self.base / "data/plugins" / FOLDER / "workdir").exists())
        self.assertFalse((self.state / "miniserverbackup-ng").exists())
        database = self.base / "log/system_tmpfs/logs_sqlite.dat"
        with sqlite3.connect(database) as connection:
            titles = connection.execute("SELECT value FROM logs_attr WHERE attrib='LOGSTARTMESSAGE'").fetchall()
        self.assertTrue(any("disabled" in title[0].lower() for title in titles), titles)

    def test_notifications_entrypoint_uses_staged_tool(self):
        tool = self.base / "webfrontend/htmlauth/system/tools/get_notifications.cgi"
        tool.write_text('print "smoke-notifications:$ARGV[0]";\n')
        result = self.php(self.html / "get_notifications.php", passthru=True)
        self.assertEqual(result.stdout, "smoke-notifications:" + FOLDER)

    def test_free_space_for_path_with_spaces_and_apostrophe(self):
        workdir = self.root / "workdir with spaces and 'apostrophe'"
        workdir.mkdir()
        (self.state / "msb_free_space").write_text(str(workdir))
        result = self.php(self.html / "free.php")
        self.assertRegex(result.stdout, r"^[0-9]+$")
        self.assertGreater(int(result.stdout), 0)

    def test_actual_cgi_renders_miniserver_and_settings(self):
        self.install()
        script = self.htmlauth / "index.cgi"
        environment = dict(self.env, SCRIPT_FILENAME=str(script),
                           SCRIPT_NAME="/admin/plugins/" + FOLDER + "/index.cgi")
        result = self.run_command(["perl", str(script)], env=environment)
        self.assertIn("Smoke Miniserver", result.stdout)
        self.assertIn('id="elfinder"', result.stdout)
        self.assertIn('id="msbackup_use"', result.stdout)
        self.assertIn("/plugins/" + FOLDER + "/ajax_config_handler.php", result.stdout)
        self.assertTrue((self.state / "miniserverbackup-ng_state.txt").is_file())
        self.assertEqual(self.config.read_text().count('MSBACKUP_USE="off"'), 1)


if __name__ == "__main__":
    unittest.main(verbosity=2)
