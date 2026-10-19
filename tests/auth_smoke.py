#!/usr/bin/env python3
"""Check the real .htaccess rules with a separate local Apache and dummy files."""
import base64
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
APACHE = shutil.which("apache2") or "/usr/sbin/apache2"

@unittest.skipUnless(Path(APACHE).is_file() and shutil.which("htpasswd"), "Apache and htpasswd required")
class AuthenticationSmoke(unittest.TestCase):
    def test_plugin_access_requires_login(self):
        with tempfile.TemporaryDirectory(prefix="miniserverbackup-auth-") as temporary:
            base = Path(temporary)
            html = base / "html"
            html.mkdir()
            system = base / "config/system"
            system.mkdir(parents=True)
            subprocess.run(["htpasswd", "-bc", str(system / "htusers.dat"), "fixture", "fixture-password"], check=True, capture_output=True)
            for relative in [".htaccess", "backups/.htaccess"]:
                target = html / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text((ROOT / "webfrontend/html" / relative).read_text().replace("REPLACELBHOMEDIR", str(base)))
            paths = ["ajax_config_handler.php", "API.php", "php/connector.minimal.php", "backups/sample.7z", "miniserverbackup-ng_state.txt"]
            for relative in paths:
                target = html / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text("authorized fixture")
            with socket.socket() as listener:
                listener.bind(("127.0.0.1", 0))
                port = listener.getsockname()[1]
            modules = ["mpm_prefork", "authn_core", "authn_file", "authz_core", "authz_user", "auth_basic", "dir"]
            lines = [
                'ServerRoot "/etc/apache2"', 'ServerName localhost',
                f'PidFile "{base}/httpd.pid"', f'DefaultRuntimeDir "{base}"',
                f'ErrorLog "{base}/error.log"', f'Listen 127.0.0.1:{port}',
                f'DocumentRoot "{html}"',
            ]
            lines += [f'LoadModule {module}_module /usr/lib/apache2/modules/mod_{module}.so' for module in modules]
            lines += [f'<Directory "{html}">', 'AllowOverride All', 'Require all granted', '</Directory>']
            config = base / "httpd.conf"
            config.write_text("\n".join(lines) + "\n")
            process = subprocess.Popen([APACHE, "-f", str(config), "-DFOREGROUND"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
            try:
                for attempt in range(100):
                    if process.poll() is not None:
                        self.fail("Test Apache failed: " + process.communicate()[1].decode())
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                            break
                    except OSError:
                        time.sleep(0.05)
                else:
                    self.fail("Test Apache did not start")
                token = base64.b64encode(b"fixture:fixture-password").decode()
                for relative in paths:
                    with self.subTest(endpoint=relative):
                        url = f"http://127.0.0.1:{port}/{relative}"
                        with self.assertRaises(urllib.error.HTTPError) as caught:
                            opener.open(url, timeout=5)
                        self.assertEqual(caught.exception.code, 401)
                        self.assertIn('Loxberry Administration', caught.exception.headers['WWW-Authenticate'])
                        request = urllib.request.Request(url, headers={"Authorization": "Basic " + token})
                        with opener.open(request, timeout=5) as response:
                            self.assertEqual(response.status, 200)
                            self.assertEqual(response.read(), b"authorized fixture")
            finally:
                process.terminate()
                process.communicate(timeout=10)

if __name__ == "__main__":
    unittest.main(verbosity=2)
