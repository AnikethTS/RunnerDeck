import io
import re
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import linux
import macos
from common import ROOT, Context, artifact_name, launcher_script, read_version


def read_ar(data: bytes) -> dict[str, bytes]:
    assert data.startswith(b"!<arch>\n")
    members, position = {}, 8
    while position < len(data):
        header = data[position:position + 60]
        name, size = header[:16].decode().strip(), int(header[48:58])
        position += 60
        members[name] = data[position:position + size]
        position += size + (size % 2)
    return members


def read_tar(data: bytes) -> dict[str, tarfile.TarInfo]:
    with tarfile.open(fileobj=io.BytesIO(data), mode="r:gz") as archive:
        return {member.name: member for member in archive.getmembers()}


class ArtifactNames(unittest.TestCase):
    def test_follows_the_release_convention(self):
        self.assertEqual("RunnerDeck-1.2.3-windows-x64-setup.exe", artifact_name("1.2.3", "windows-x64", "-setup.exe"))
        self.assertEqual("RunnerDeck-1.2.3-linux-all.deb", artifact_name("1.2.3", "linux-all", ".deb"))


class DebianPackage(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        ctx = Context(out=Path(cls.tmp.name), go="go", version=read_version())
        cls.deb = linux.build_deb(ctx)[0]
        cls.members = read_ar(cls.deb.read_bytes())

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def test_is_a_valid_ar_archive_in_the_order_dpkg_needs(self):
        self.assertEqual(["debian-binary", "control.tar.gz", "data.tar.gz"], list(self.members))
        self.assertEqual(b"2.0\n", self.members["debian-binary"])

    def test_control_describes_the_package(self):
        control = read_tar(self.members["control.tar.gz"])
        self.assertIn("./control", control)
        with tarfile.open(fileobj=io.BytesIO(self.members["control.tar.gz"]), mode="r:gz") as archive:
            text = archive.extractfile("./control").read().decode()
        self.assertIn("Package: runnerdeck\n", text)
        self.assertIn(f"Version: {read_version()}\n", text)
        self.assertRegex(text, r"Depends: .*php-cli")
        self.assertNotIn("@", text.split("Maintainer:")[1].split("\n")[0].replace("@users.noreply.github.com", ""))

    def test_installs_the_app_the_launcher_and_the_desktop_files(self):
        data = read_tar(self.members["data.tar.gz"])
        for path in (
            "./usr/bin/runnerdeck",
            "./usr/share/runnerdeck/run.sh",
            "./usr/share/runnerdeck/VERSION",
            "./usr/share/runnerdeck/app/public/index.php",
            "./usr/share/runnerdeck/app/src/bootstrap.php",
            "./usr/share/applications/runnerdeck.desktop",
            "./usr/lib/systemd/user/runnerdeck.service",
            "./usr/share/icons/hicolor/512x512/apps/runnerdeck.png",
        ):
            self.assertIn(path, data)
        self.assertEqual(0o755, data["./usr/bin/runnerdeck"].mode)
        self.assertEqual(0o755, data["./usr/share/runnerdeck/run.sh"].mode)
        self.assertEqual(0, data["./usr/bin/runnerdeck"].uid)

    def test_the_package_ships_no_dev_tooling(self):
        names = read_tar(self.members["data.tar.gz"])
        for forbidden in ("vendor", "tests", "composer.json", ".env", "storage"):
            self.assertFalse([n for n in names if f"/{forbidden}" in n], forbidden)

    def test_launcher_points_at_the_installed_app(self):
        script = launcher_script("/usr/share/runnerdeck").decode()
        self.assertNotIn("@APP_ROOT@", script)
        self.assertIn('APP_ROOT="${RUNNERDECK_APP_ROOT:-/usr/share/runnerdeck}"', script)


class HomebrewFormula(unittest.TestCase):
    def test_fills_every_placeholder(self):
        text = macos.render_formula("https://example.test/runnerdeck-v1.2.3.zip", "a" * 64)
        leftovers = set(re.findall(r"@[A-Z_]+@", text)) - {"@APP_ROOT@"}
        self.assertEqual(set(), leftovers)
        self.assertIn('url "https://example.test/runnerdeck-v1.2.3.zip"', text)
        self.assertIn('sha256 "' + "a" * 64 + '"', text)
        self.assertNotIn("version ", text)
        self.assertRegex(text, r"class Runnerdeck < Formula")

    def test_inreplaces_the_launcher_root_at_install_time(self):
        text = (ROOT / "packaging" / "macos" / "runnerdeck.rb.in").read_text()
        self.assertIn('inreplace bin/"runnerdeck", "@APP_ROOT@", libexec', text)
        self.assertIn("@APP_ROOT@", (ROOT / "packaging" / "common" / "runnerdeck").read_text())


if __name__ == "__main__":
    unittest.main()
