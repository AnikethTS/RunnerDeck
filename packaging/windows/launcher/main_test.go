package main

import (
	"path/filepath"
	"strings"
	"testing"
)

func env(values map[string]string) func(string) string {
	return func(name string) string { return values[name] }
}

func TestParseOptionsDefaults(t *testing.T) {
	opts, err := parseOptions(nil, env(nil))
	if err != nil {
		t.Fatal(err)
	}
	if opts.host != "127.0.0.1" || opts.port != 8090 || opts.noBrowser || opts.showVersion {
		t.Fatalf("unexpected defaults: %+v", opts)
	}
}

func TestParseOptionsFlagsAndBindHost(t *testing.T) {
	opts, err := parseOptions(
		[]string{"--port", "9001", "--no-browser", "--version"},
		env(map[string]string{"RUNNERDECK_BIND_HOST": "0.0.0.0"}),
	)
	if err != nil {
		t.Fatal(err)
	}
	if opts.host != "0.0.0.0" || opts.port != 9001 || !opts.noBrowser || !opts.showVersion {
		t.Fatalf("unexpected options: %+v", opts)
	}
	opts, err = parseOptions([]string{"--port=8123"}, env(nil))
	if err != nil || opts.port != 8123 {
		t.Fatalf("--port=N failed: %+v %v", opts, err)
	}
}

func TestParseOptionsRejectsBadInput(t *testing.T) {
	for _, args := range [][]string{{"--port"}, {"--port", "abc"}, {"--port", "70000"}, {"--wat"}} {
		if _, err := parseOptions(args, env(nil)); err == nil {
			t.Fatalf("expected an error for %v", args)
		}
	}
}

func TestDataDirPrefersExplicitOverrideThenLocalAppData(t *testing.T) {
	exeDir := filepath.Join("C:", "tools", "runnerdeck")
	if got := dataDirFor(exeDir, env(map[string]string{"RUNNERDECK_DATA_DIR": "D:/rd"})); got != "D:/rd" {
		t.Fatalf("override ignored: %s", got)
	}
	got := dataDirFor(exeDir, env(map[string]string{"LOCALAPPDATA": "C:/Users/me/AppData/Local"}))
	if got != filepath.Join("C:/Users/me/AppData/Local", "RunnerDeck") {
		t.Fatalf("unexpected data dir: %s", got)
	}
}

func TestDefaultsPointEveryWritablePathIntoTheDataDir(t *testing.T) {
	l := layout{dataDir: filepath.Join("data", "RunnerDeck")}
	defaults := l.defaults()
	for _, key := range []string{"RUNNERDECK_SETTINGS_FILE", "RUNNERDECK_HISTORY_FILE", "RUNNERDECK_POOL_DIR"} {
		value := defaults[key]
		if !strings.HasPrefix(value, "data/RunnerDeck/") || strings.Contains(value, "\\") {
			t.Fatalf("%s should be a forward-slash path under the data dir, got %q", key, value)
		}
	}
}

func TestWithDefaultsKeepsUserSettings(t *testing.T) {
	base := []string{"PATH=x", "RUNNERDECK_POOL_DIR=D:/mine", "RUNNERDECK_HISTORY_FILE="}
	out := withDefaults(base, map[string]string{
		"RUNNERDECK_POOL_DIR":      "C:/default/pool",
		"RUNNERDECK_HISTORY_FILE":  "C:/default/history",
		"RUNNERDECK_SETTINGS_FILE": "C:/default/settings.json",
	})
	joined := strings.Join(out, "\n")
	if strings.Contains(joined, "C:/default/pool") {
		t.Fatal("a user-set value was overridden")
	}
	if !strings.Contains(joined, "RUNNERDECK_HISTORY_FILE=C:/default/history") ||
		!strings.Contains(joined, "RUNNERDECK_SETTINGS_FILE=C:/default/settings.json") {
		t.Fatalf("defaults missing: %v", out)
	}
}

func TestIsLoopback(t *testing.T) {
	for _, host := range []string{"127.0.0.1", "::1", "[::1]", "localhost", "LOCALHOST"} {
		if !isLoopback(host) {
			t.Fatalf("%s should be loopback", host)
		}
	}
	for _, host := range []string{"0.0.0.0", "192.168.1.5", "example.com"} {
		if isLoopback(host) {
			t.Fatalf("%s should not be loopback", host)
		}
	}
}

func TestTailLines(t *testing.T) {
	if got := tailLines("a\nb\nc\nd\n", 2); got != "c\nd" {
		t.Fatalf("got %q", got)
	}
	if got := tailLines("only", 5); got != "only" {
		t.Fatalf("got %q", got)
	}
}
