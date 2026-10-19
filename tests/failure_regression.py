#!/usr/bin/env python3
"""Run the real backup worker against a local Miniserver fixture, never production."""
import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import threading
import unittest
import install_smoke as fixture_module
FOLDER = fixture_module.FOLDER


class WorkerFailureRegression(unittest.TestCase):
    def setUp(self):
        self.fixture = fixture_module.InstallSmoke(methodName='runTest')
        self.fixture.setUp()
        self.addCleanup(self.fixture.doCleanups)
        f = self.fixture
        f.install()
        self.scenario = 'success'
        self.requests = []
        self.download_counts = {}
        self.folder = '001_Smoke Miniserver'
        self.content = {'/keep.bin': b'unchanged file\n', '/replace.bin': b'new replacement contents\n', '/config/app.bin': b'configuration fixture\n'}
        outer = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_GET(self):
                path = self.path
                outer.requests.append(path)
                code = 200
                partial = False
                if path == '/dev/cfg/ip':
                    body = b'<LL control="dev/cfg/ip" value="127.0.0.1" Code="200"/>'
                elif path == '/dev/cfg/version':
                    body = b'<LL control="dev/cfg/version" value="15.5.1.1" Code="200"/>'
                elif path == '/dev/fslist/':
                    lines = ['d 0 Jan 01 12:00 config', 'd 0 Jan 01 12:00 empty', 'd 0 Jan 01 12:00 sys']
                    for name in [name for name in outer.content if name.count('/') == 1]:
                        date = 'Feb 30' if outer.scenario == 'invalid_date' and name == '/replace.bin' else 'Jan 01'
                        if outer.scenario == 'leap_day' and name == '/replace.bin':
                            date = 'Feb 29'
                        size = 300 if outer.scenario == 'error_body' and name == '/replace.bin' else len(outer.content[name])
                        lines.append(f'- {size} {date} 12:00 {name[1:]}')
                    if outer.scenario == 'malformed':
                        lines.append('unrecognized listing entry')
                    body = ('\n'.join(lines) + '\n').encode()
                    if outer.scenario == 'empty_root':
                        body = b'Directory empty'
                elif path == '/dev/fslist/config/':
                    body = f'- {len(outer.content["/config/app.bin"])} Jan 01 12:00 app.bin\n'.encode()
                    if outer.scenario == 'subtree_404':
                        code, body = 404, b'not found'
                    elif outer.scenario == 'subtree_401':
                        code, body = 401, b'unauthorized'
                    elif outer.scenario == 'listing_transport':
                        partial = True
                elif path == '/dev/fslist/empty/':
                    body = b'Directory empty'
                elif path == '/dev/fslist/sys/':
                    body = b'd 0 Jan 01 12:00 internal\n- 12 Jan 01 12:00 tokens.xml\n'
                elif path.startswith('/dev/fsget'):
                    name = path[len('/dev/fsget'):]
                    outer.download_counts[name] = outer.download_counts.get(name, 0) + 1
                    body = outer.content.get(name, b'unknown')
                    if name == '/replace.bin':
                        if outer.scenario == 'download_404':
                            code, body = 404, b'not found'
                        elif outer.scenario == 'download_transport':
                            partial = True
                        elif outer.scenario == 'short_download':
                            body = b'short'
                        elif outer.scenario == '122_bytes':
                            body = b'x' * 122
                        elif outer.scenario == 'error_body':
                            prefix = b'<html><head><title>error</title></head><body>Forbidden'
                            body = prefix + b'x' * (300 - len(prefix))
                        elif outer.scenario == 'retry_success' and outer.download_counts[name] == 1:
                            body = b'partial'
                            partial = True
                else:
                    code, body = 404, b'unexpected fixture request'
                self.send_response(code)
                self.send_header('Content-Length', str(len(body) + 20 if partial else len(body)))
                self.end_headers()
                self.wfile.write(body)
                self.wfile.flush()
                self.close_connection = True

        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        def close_server():
            server.shutdown()
            server.server_close()
            thread.join(timeout=5)
        self.addCleanup(close_server)
        general_path = f.base / 'config/system/general.json'
        general = json.loads(general_path.read_text())
        general['Miniserver']['1']['Ipaddress'] = '127.0.0.1'
        general['Miniserver']['1']['Port'] = str(server.server_port)
        general_path.write_text(json.dumps(general))
        legacy = f.base / 'config/system/general.cfg'
        legacy.write_text(legacy.read_text().replace('192.0.2.123', '127.0.0.1').replace('PORT="80"', 'PORT="' + str(server.server_port) + '"'))
        self.cache = f.base / 'data/plugins' / FOLDER / '.currentbackup' / self.folder
        self.cache.mkdir(parents=True)
        for name, content in {'keep.bin': self.content['/keep.bin'], 'replace.bin': b'old bytes', 'removed.bin': b'retained until successful listing/downloads', 'config/app.bin': b'old config'}.items():
            target = self.cache / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(content)
        self.storage = f.base / 'data/plugins' / FOLDER / 'final' / self.folder
        self.storage.mkdir(parents=True)
        self.old_archive = self.storage / 'Backup_127.0.0.1_20000101_000000.7z'
        subprocess.run(['7za', 'a', str(self.old_archive), str(self.cache / '*'), '-t7z'], check=True, capture_output=True)
        # Old archive's filesystem date must reflect its historical backup date.
        # Its entries deliberately have newer timestamps than the server fixture:
        # validated server contents must still replace them.
        os.utime(self.old_archive, (946684800, 946684800))
        (f.html / 'backups' / self.folder).symlink_to(self.storage)
        self.last_save = '1600000000'
        f.config.write_text('[MINISERVERBACKUP]\n' + '\n'.join(key + '="' + value + '"' for key, value in {
            'VERSION': 'fixture', 'MSBACKUP_USE': 'on', 'MSBACKUP_USE_NOTIFY': 'off', 'MSBACKUP_USE_EMAILS': 'off',
            'WORKDIR_PATH': str(f.state / 'work'), 'WORKDIR_PATH_SUBDIR': '',
            'FINALSTORAGE1': str(self.storage.parent), 'BACKUP_INTERVAL1': '0',
            'BACKUP_SCHEDULE_TIME1': '-1', 'BACKUPS_TO_KEEP1': '1', 'MS_SUBDIR1': '',
            'FILE_FORMAT1': '7z', 'COMPRESSION1': '0', 'RANDOM_SLEEP': '0',
            'LAST_SAVE1': self.last_save, 'LAST_ERROR1': '0',
        }.items()) + '\n')
        fakebin = f.root / 'bin'
        fakebin.mkdir()
        sudo = fakebin / 'sudo'
        sudo.write_text('#!/bin/sh\n[ "$1" = "$LBHOMEDIR/sbin/installcrontab.sh" ] && exit 0\nexit 99\n')
        sudo.chmod(0o755)
        f.env['PATH'] = str(fakebin) + ':' + f.env.get('PATH', '/usr/bin:/bin')
        worker = f.html / 'createmsbackup.php'
        source = worker.read_text()
        source = re.sub(r'\bsleep\([^;]*\);', 'usleep(1);', source)
        worker.write_text(source)
        self.worker = worker

    def snapshot(self, root):
        return {str(p.relative_to(root)): (p.read_bytes(), p.stat().st_mtime_ns) for p in root.rglob('*') if p.is_file()}

    def run_worker(self):
        f = self.fixture
        result = subprocess.run(['php', str(self.worker), 'manual', '1'], cwd=f.root, env=f.env, capture_output=True, text=True, timeout=30)
        self.assertNotRegex(result.stdout + result.stderr, r'(PHP Fatal error|Uncaught |Software error:)')
        return result

    def assert_failure_preserves_data(self, scenario):
        self.scenario = scenario
        cache_before = self.snapshot(self.cache)
        archives_before = self.snapshot(self.storage)
        result = self.run_worker()
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertEqual(self.snapshot(self.storage), archives_before)
        self.assertEqual(self.snapshot(self.cache), cache_before)
        self.assertRegex(self.fixture.config.read_text(), r'LAST_SAVE1="' + self.last_save + '"')
        self.assertEqual((self.fixture.state / 'miniserverbackup-ng_state.txt').read_text(), '-')

    def test_subtree_http_failure(self):
        self.assert_failure_preserves_data('subtree_404')

    def test_subtree_unauthorized(self):
        self.assert_failure_preserves_data('subtree_401')

    def test_listing_transport_failure(self):
        self.assert_failure_preserves_data('listing_transport')

    def test_malformed_listing(self):
        self.assert_failure_preserves_data('malformed')

    def test_impossible_listing_date(self):
        self.assert_failure_preserves_data('invalid_date')

    def test_empty_root_listing(self):
        self.assert_failure_preserves_data('empty_root')

    def test_download_http_failure(self):
        self.assert_failure_preserves_data('download_404')

    def test_download_transport_failure(self):
        self.assert_failure_preserves_data('download_transport')

    def test_short_download(self):
        self.assert_failure_preserves_data('short_download')

    def test_122_byte_size_bypass_removed(self):
        self.assert_failure_preserves_data('122_bytes')

    def test_error_page_even_with_matching_size(self):
        self.assert_failure_preserves_data('error_body')

    def assert_success(self, scenario):
        self.scenario = scenario
        result = self.run_worker()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertFalse((self.cache / 'removed.bin').exists())
        for name, content in self.content.items():
            self.assertEqual((self.cache / name.lstrip('/')).read_bytes(), content)
        archives = list(self.storage.glob('Backup_*.7z'))
        self.assertEqual(len(archives), 1)
        self.assertNotEqual(archives[0], self.old_archive)
        subprocess.run(['7za', 't', str(archives[0])], check=True, capture_output=True)
        destination = self.fixture.root / 'extracted'
        subprocess.run(['7za', 'x', '-y', '-o' + str(destination), str(archives[0])], check=True, capture_output=True)
        actual = {str(p.relative_to(destination)): p.read_bytes() for p in destination.rglob('*') if p.is_file()}
        self.assertEqual(actual, {name.lstrip('/'): content for name, content in self.content.items()})
        self.assertNotRegex(self.fixture.config.read_text(), r'LAST_SAVE1="' + self.last_save + '"')
        self.assertFalse(any('/sys/internal/' in path or 'tokens.xml' in path for path in self.requests))

    def test_successful_full_worker_and_rotation(self):
        self.assert_success('success')

    def test_cached_file_becomes_directory(self):
        shutil.rmtree(self.cache / 'config')
        (self.cache / 'config').write_bytes(b'previously a file')
        self.assert_success('success')

    def test_cached_directory_becomes_file(self):
        (self.cache / 'replace.bin').unlink()
        (self.cache / 'replace.bin').mkdir()
        (self.cache / 'replace.bin' / 'obsolete.bin').write_bytes(b'previous directory content')
        self.assert_success('success')

    def test_retry_replaces_partial_output(self):
        self.assert_success('retry_success')
        self.assertEqual(self.download_counts['/replace.bin'], 2)

    def check_followup_without_downloads(self, deletion):
        self.assert_success('success')
        original = self.snapshot(self.storage)
        config = self.fixture.config
        config.write_text(config.read_text().replace('BACKUPS_TO_KEEP1="1"', 'BACKUPS_TO_KEEP1="3"'))
        # Distinguish this second test-only run without waiting for wall time.
        source = self.worker.read_text()
        source = source.replace('date("YmdHis",time())', 'date("YmdHis",time()+60)')
        self.worker.write_text(source)
        self.requests.clear()
        if deletion:
            del self.content['/replace.bin']
        result = self.run_worker()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertFalse(any(path.startswith('/dev/fsget') for path in self.requests))
        after = self.snapshot(self.storage)
        for name, old in original.items():
            self.assertEqual(after[name], old)
        added = set(after) - set(original)
        self.assertEqual(len(added), 1)
        archive = self.storage / added.pop()
        destination = self.fixture.root / 'followup-extracted'
        subprocess.run(['7za', 'x', '-y', '-o' + str(destination), str(archive)], check=True, capture_output=True)
        actual = {str(path.relative_to(destination)): path.read_bytes() for path in destination.rglob('*') if path.is_file()}
        self.assertEqual(actual, {name.lstrip('/'): content for name, content in self.content.items()})

    def test_unchanged_followup_needs_no_download_directory(self):
        self.check_followup_without_downloads(False)

    def test_deletion_only_followup_preserves_older_archive(self):
        self.check_followup_without_downloads(True)

    def test_valid_historical_leap_day(self):
        self.assert_success('leap_day')
        self.assertEqual(datetime.datetime.fromtimestamp((self.cache / 'replace.bin').stat().st_mtime).day, 29)


if __name__ == '__main__':
    unittest.main(verbosity=2)
