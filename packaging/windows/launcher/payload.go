package main

import (
	"archive/zip"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
)

const runtimeMarker = ".complete"

func payloadID(data []byte) string {
	sum := sha256.Sum256(data)
	return hex.EncodeToString(sum[:])[:12]
}

func extractPayload(data []byte, dest string) error {
	reader, err := zip.NewReader(bytes.NewReader(data), int64(len(data)))
	if err != nil {
		return err
	}
	base := filepath.Clean(dest) + string(os.PathSeparator)
	for _, file := range reader.File {
		target := filepath.Join(dest, filepath.FromSlash(file.Name))
		if !strings.HasPrefix(target, base) {
			return fmt.Errorf("unsafe path in payload: %q", file.Name)
		}
		if file.FileInfo().IsDir() {
			if err := os.MkdirAll(target, 0o755); err != nil {
				return err
			}
			continue
		}
		if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
			return err
		}
		if err := writeZipFile(file, target); err != nil {
			return err
		}
	}
	return nil
}

func writeZipFile(file *zip.File, target string) error {
	source, err := file.Open()
	if err != nil {
		return err
	}
	defer source.Close()
	out, err := os.OpenFile(target, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o755)
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, source); err != nil {
		out.Close()
		return err
	}
	return out.Close()
}

func ensureRuntime(l layout, payload []byte) (layout, error) {
	runtimeRoot := filepath.Join(l.dataDir, "runtime")
	root := filepath.Join(runtimeRoot, payloadID(payload))
	if _, err := os.Stat(filepath.Join(root, runtimeMarker)); err != nil {
		staging := fmt.Sprintf("%s.unpacking-%d", root, os.Getpid())
		_ = os.RemoveAll(staging)
		if err := extractPayload(payload, staging); err != nil {
			_ = os.RemoveAll(staging)
			return l, fmt.Errorf("cannot unpack the bundled runtime: %w", err)
		}
		if err := os.WriteFile(filepath.Join(staging, runtimeMarker), nil, 0o644); err != nil {
			_ = os.RemoveAll(staging)
			return l, err
		}
		_ = os.RemoveAll(root)
		if err := os.Rename(staging, root); err != nil {
			_ = os.RemoveAll(staging)
			if _, statErr := os.Stat(filepath.Join(root, runtimeMarker)); statErr != nil {
				return l, err
			}
		}
	}
	pruneRuntimes(runtimeRoot, filepath.Base(root))
	l.php = filepath.Join(root, "php", "php.exe")
	l.app = filepath.Join(root, "app")
	return l, nil
}

func pruneRuntimes(runtimeRoot, keep string) {
	entries, err := os.ReadDir(runtimeRoot)
	if err != nil {
		return
	}
	for _, entry := range entries {
		if entry.IsDir() && entry.Name() != keep && !strings.Contains(entry.Name(), ".unpacking-") {
			_ = os.RemoveAll(filepath.Join(runtimeRoot, entry.Name()))
		}
	}
}
