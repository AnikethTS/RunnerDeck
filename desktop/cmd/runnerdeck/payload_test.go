package main

import (
	"archive/zip"
	"bytes"
	"os"
	"path/filepath"
	"testing"
)

func makeZip(t *testing.T, files map[string]string) []byte {
	t.Helper()
	var buf bytes.Buffer
	writer := zip.NewWriter(&buf)
	for name, body := range files {
		entry, err := writer.Create(name)
		if err != nil {
			t.Fatal(err)
		}
		if _, err := entry.Write([]byte(body)); err != nil {
			t.Fatal(err)
		}
	}
	if err := writer.Close(); err != nil {
		t.Fatal(err)
	}
	return buf.Bytes()
}

func TestExtractPayloadWritesFiles(t *testing.T) {
	dest := t.TempDir()
	data := makeZip(t, map[string]string{"php/php.exe": "exe", "app/VERSION": "1.2.3"})

	if err := extractPayload(data, dest); err != nil {
		t.Fatal(err)
	}
	body, err := os.ReadFile(filepath.Join(dest, "app", "VERSION"))
	if err != nil || string(body) != "1.2.3" {
		t.Fatalf("VERSION not extracted: %q %v", body, err)
	}
}

func TestExtractPayloadRejectsPathsOutsideTheDestination(t *testing.T) {
	dest := filepath.Join(t.TempDir(), "runtime")
	data := makeZip(t, map[string]string{"../evil.txt": "x"})

	if err := extractPayload(data, dest); err == nil {
		t.Fatal("a path that escapes the destination was accepted")
	}
	if _, err := os.Stat(filepath.Join(filepath.Dir(dest), "evil.txt")); err == nil {
		t.Fatal("a file was written outside the destination")
	}
}

func TestPayloadIDIsStableAndContentDependent(t *testing.T) {
	a := makeZip(t, map[string]string{"a": "1"})
	b := makeZip(t, map[string]string{"a": "2"})

	if payloadID(a) != payloadID(a) || payloadID(a) == payloadID(b) || len(payloadID(a)) != 12 {
		t.Fatal("payload id is not a stable 12 character content hash")
	}
}

func TestEnsureRuntimeUnpacksOnceAndPrunesOldVersions(t *testing.T) {
	dataDir := t.TempDir()
	old := filepath.Join(dataDir, "runtime", "oldversion1")
	if err := os.MkdirAll(old, 0o755); err != nil {
		t.Fatal(err)
	}
	payload := makeZip(t, map[string]string{"php/php.exe": "exe", "app/VERSION": "9.9.9"})

	first, err := ensureRuntime(layout{dataDir: dataDir}, payload)
	if err != nil {
		t.Fatal(err)
	}
	if body, err := os.ReadFile(filepath.Join(first.app, "VERSION")); err != nil || string(body) != "9.9.9" {
		t.Fatalf("app not unpacked: %q %v", body, err)
	}
	if filepath.Base(first.php) != "php.exe" {
		t.Fatalf("unexpected php path %s", first.php)
	}
	if _, err := os.Stat(old); err == nil {
		t.Fatal("the old runtime was not removed")
	}

	marker := filepath.Join(filepath.Dir(filepath.Dir(first.php)), runtimeMarker)
	stamp, _ := os.Stat(marker)
	second, err := ensureRuntime(layout{dataDir: dataDir}, payload)
	if err != nil || second.php != first.php {
		t.Fatalf("second call changed the runtime: %v", err)
	}
	again, _ := os.Stat(marker)
	if !stamp.ModTime().Equal(again.ModTime()) {
		t.Fatal("the runtime was unpacked again")
	}
}
