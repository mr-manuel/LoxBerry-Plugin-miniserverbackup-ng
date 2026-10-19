#!/usr/bin/env python3
"""Run isolated regressions against commands and conditions in the PHP source.

Usage: python3 tests/compatibility.py
Requires only Python's standard library, PHP CLI, and 7za. No LoxBerry library,
configuration, credentials, or network connection is loaded. Archives and source
fixtures are created in a temporary directory and removed after each test.
"""

import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import time
import unittest


ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / "webfrontend/html/createmsbackup.php").read_text()


def php_literal(value):
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def run_php(code):
    result = subprocess.run(
        ["php"], input="<?php\n" + code, text=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=20,
    )
    if result.returncode:
        raise AssertionError("Isolated PHP evaluation failed:\n" + result.stderr)
    try:
        return json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("Unexpected PHP output: " + result.stdout + result.stderr) from exc


def archive_expression(command, output):
    # Evaluate the original PHP concatenation, including its escaping and options.
    matches = re.findall(
        r"^\s*exec\((.+), \$" + re.escape(output) + r"\);\s*$",
        SOURCE, re.MULTILINE,
    )
    matches = [item for item in matches if item.startswith("'7za " + command + " '")]
    if not matches:
        raise AssertionError("Archive command not found: 7za " + command)
    if command == "a":
        # The last add statement is the branch without a previous backup.
        return matches[-1]
    if len(set(matches)) != 1:
        raise AssertionError("Ambiguous archive command: 7za " + command)
    return matches[0]


def evaluate_command(expression, variables):
    payload = php_literal(json.dumps(variables))
    return run_php(
        "$variables = json_decode(" + payload + ", true);\n"
        "extract($variables, EXTR_OVERWRITE);\n"
        "echo json_encode(" + expression + ");\n"
    )


def schedule_expression():
    marker = SOURCE.index("// |- Backup time is greater than current time")
    start = SOURCE.index("else if", marker)
    opening = SOURCE.index("(", start)
    depth = 1
    quote = None
    escaped = False
    for index in range(opening + 1, len(SOURCE)):
        character = SOURCE[index]
        if quote:
            if escaped:
                escaped = False
            elif character == "\\":
                escaped = True
            elif character == quote:
                quote = None
        elif character in ("'", '\"'):
            quote = character
        elif character == "(":
            depth += 1
        elif character == ")":
            depth -= 1
            if depth == 0:
                expression = SOURCE[opening + 1:index]
                expression, times = re.subn(r"\btime\s*\(\s*\)", "$test_now", expression)
                expression, dates = re.subn(
                    r"\bdate\s*\(\s*([\"'])H:i\1\s*\)", "$test_clock", expression,
                )
                if times != 1 or dates != 1:
                    raise AssertionError("Cannot replace the scheduler clock safely")
                return expression
    raise AssertionError("Unterminated scheduler condition")


def file_contents(directory):
    return {
        str(path.relative_to(directory)): path.read_bytes()
        for path in directory.rglob("*") if path.is_file()
    }


def directories(directory):
    return {str(path.relative_to(directory)) for path in directory.rglob("*") if path.is_dir()}


@unittest.skipUnless(shutil.which("php") and shutil.which("7za"), "PHP CLI and 7za required")
class ArchiveRegression(unittest.TestCase):
    def run_command(self, command, cwd):
        # PHP exec() also passes the rendered command through the shell. All paths
        # and source files here belong to this test's temporary fixture directory.
        result = subprocess.run(
            command, shell=True, cwd=cwd, text=True,
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=30,
        )
        self.assertEqual(result.returncode, 0, command + "\n" + result.stdout)
        self.assertIn("Everything is Ok", result.stdout, command + "\n" + result.stdout)

    def assert_archive(self, archive, expected_files, expected_directories, root):
        destination = root / (archive.stem + " extracted")
        result = subprocess.run(
            ["7za", "x", "-y", "-o" + str(destination), str(archive)],
            text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=30,
        )
        self.assertEqual(result.returncode, 0, result.stdout)
        actual_files = file_contents(destination)
        self.assertEqual(set(actual_files), set(expected_files))
        for name, content in expected_files.items():
            self.assertEqual(actual_files[name], content, name)
        self.assertEqual(directories(destination), expected_directories)

    def exercise_archive(self, spaced):
        with tempfile.TemporaryDirectory(prefix="miniserverbackup-regression-") as temporary:
            root = Path(temporary) / ("backup's path with spaces" if spaced else "backup")
            savedir = root / ("current backup" if spaced else "currentbackup")
            backupdir = root / ("final storage" if spaced else "finalstorage")
            folder = "001_Mein Miniserver" if spaced else "001_Miniserver"
            source = savedir / folder
            storage = backupdir / folder
            source.mkdir(parents=True)
            storage.mkdir(parents=True)
            initial = {
                "unchanged.txt": b"preserve unchanged bytes\n",
                "changed.txt": b"original bytes\n",
                "deleted.txt": b"remove this file\n",
                "nested dir/unchanged.bin": bytes(range(256)),
                "obsolete directory/removed.txt": b"remove this whole directory\n",
            }
            old_timestamp = int(time.time()) - 120
            for name, content in initial.items():
                path = source / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(content)
                os.utime(path, (old_timestamp, old_timestamp))
            (source / "empty directory").mkdir()
            initial_directories = directories(source)
            variables = {
                "compression": "", "bkp_dest_dir": str(backupdir),
                "bkpfolder": folder, "savedir_path": str(savedir),
                "bkpdir": "Backup_first", "fileformat_extension": ".7z",
                "latest_filename": "Backup_first.7z",
            }
            add = archive_expression("a", "seven_zip_output")
            update = archive_expression("u", "seven_zip_output")
            integrity = archive_expression("t", "seven_zip_check_output")
            self.run_command(evaluate_command(add, variables), root)
            first = storage / "Backup_first.7z"
            self.assertTrue(first.is_file())
            self.run_command(evaluate_command(integrity, variables), root)
            self.assert_archive(first, initial, initial_directories, root)

            (source / "changed.txt").write_bytes(b"changed bytes with a different size\n")
            new_timestamp = old_timestamp + 60
            os.utime(source / "changed.txt", (new_timestamp, new_timestamp))
            (source / "new file.txt").write_bytes(b"new content\n")
            (source / "nested dir/new nested.txt").write_bytes(b"new nested content\n")
            (source / "deleted.txt").unlink()
            shutil.rmtree(source / "obsolete directory")
            expected = file_contents(source)
            expected_directories = directories(source)
            second = storage / "Backup_second.7z"
            # Match the plugin's copy-then-update flow without loading the plugin.
            shutil.copyfile(first, second)
            variables["bkpdir"] = "Backup_second"
            self.run_command(evaluate_command(update, variables), root)
            variables["latest_filename"] = second.name
            self.run_command(evaluate_command(integrity, variables), root)
            self.assert_archive(second, expected, expected_directories, root)
            # Updating the copied archive must also preserve the older backup.
            self.assert_archive(first, initial, initial_directories, root)
            incremental = storage / "Incremental_Backup_second.7z"
            self.assertTrue(incremental.is_file())
            variables["latest_filename"] = incremental.name
            self.run_command(evaluate_command(integrity, variables), root)

    def test_first_and_followup_archive(self):
        self.exercise_archive(False)

    def test_archive_paths_with_spaces_and_apostrophe(self):
        self.exercise_archive(True)


@unittest.skipUnless(shutil.which("php"), "PHP CLI required")
class ScheduleRegression(unittest.TestCase):
    def test_actual_skip_condition(self):
        now = 1_800_000_000
        # name, manual, interval minutes, age seconds, scheduled time, clock, skip
        cases = [
            ("manual daily before clock", 1, 1440, 3600, "23:55", "12:00", False),
            ("manual overdue before clock", 1, 1440, 172800, "23:55", "12:00", False),
            ("manual short interval", 1, 60, 60, -1, "12:00", False),
            ("auto interval not elapsed", 0, 1440, 3600, "02:00", "12:00", True),
            ("auto overdue before clock", 0, 1440, 172800, "23:55", "12:00", True),
            ("auto due at clock", 0, 1440, 172800, "12:00", "12:00", False),
            ("auto overdue after clock", 0, 1440, 172800, "02:00", "12:00", False),
            ("auto overdue no clock", 0, 1440, 172800, -1, "12:00", False),
            ("auto short due no clock", 0, 60, 7200, -1, "12:00", False),
            ("auto short not due", 0, 60, 60, -1, "12:00", True),
            ("auto short ignores daily clock", 0, 60, 7200, "23:55", "12:00", False),
        ]
        data = [dict(
            manual_backup=manual, backupinterval=interval, last_save=now-age,
            backup_schedule_time=schedule, test_now=now, test_clock=clock,
        ) for _, manual, interval, age, schedule, clock, _ in cases]
        results = run_php(
            "$cases = json_decode(" + php_literal(json.dumps(data)) + ", true);\n"
            "$results = [];\nforeach ($cases as $case) {\n"
            "extract($case, EXTR_OVERWRITE);\n"
            "$results[] = (bool)(" + schedule_expression() + ");\n}\n"
            "echo json_encode($results);\n"
        )
        self.assertEqual(len(results), len(cases))
        for case, actual in zip(cases, results):
            with self.subTest(case=case[0]):
                self.assertEqual(actual, case[-1])


if __name__ == "__main__":
    unittest.main(verbosity=2)
