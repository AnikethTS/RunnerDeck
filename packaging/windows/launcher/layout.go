package main

import (
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
)

type options struct {
	host        string
	port        int
	noBrowser   bool
	showVersion bool
	assumeYes   bool
	tray        bool
	console     bool
}

type layout struct {
	exeDir  string
	php     string
	app     string
	dataDir string
}

func parseOptions(args []string, getenv func(string) string) (options, error) {
	opts := options{host: "127.0.0.1", port: 8090}
	if host := strings.TrimSpace(getenv("RUNNERDECK_BIND_HOST")); host != "" {
		opts.host = host
	}
	for i := 0; i < len(args); i++ {
		arg := args[i]
		switch {
		case arg == "--no-browser":
			opts.noBrowser = true
		case arg == "--tray":
			opts.tray = true
		case arg == "--console":
			opts.console = true
		case arg == "--yes" || arg == "-y":
			opts.assumeYes = true
		case arg == "--version" || arg == "-v":
			opts.showVersion = true
		case arg == "--port" || arg == "-p":
			if i+1 >= len(args) {
				return opts, fmt.Errorf("%s needs a port number", arg)
			}
			i++
			port, err := parsePort(args[i])
			if err != nil {
				return opts, err
			}
			opts.port = port
		case strings.HasPrefix(arg, "--port="):
			port, err := parsePort(strings.TrimPrefix(arg, "--port="))
			if err != nil {
				return opts, err
			}
			opts.port = port
		default:
			return opts, fmt.Errorf("unknown option %q (try --port N, --no-browser, --yes, --tray, --console, --version)", arg)
		}
	}
	return opts, nil
}

func parsePort(raw string) (int, error) {
	port, err := strconv.Atoi(strings.TrimSpace(raw))
	if err != nil || port < 1 || port > 65535 {
		return 0, fmt.Errorf("%q is not a valid port (1-65535)", raw)
	}
	return port, nil
}

func newLayout(exePath string, getenv func(string) string) layout {
	exeDir := filepath.Dir(exePath)
	return layout{
		exeDir:  exeDir,
		php:     filepath.Join(exeDir, "php", "php.exe"),
		app:     filepath.Join(exeDir, "app"),
		dataDir: dataDirFor(exeDir, getenv),
	}
}

func dataDirFor(exeDir string, getenv func(string) string) string {
	if dir := strings.TrimSpace(getenv("RUNNERDECK_DATA_DIR")); dir != "" {
		return dir
	}
	if local := strings.TrimSpace(getenv("LOCALAPPDATA")); local != "" {
		return filepath.Join(local, "RunnerDeck")
	}
	if config, err := os.UserConfigDir(); err == nil && config != "" {
		return filepath.Join(config, "RunnerDeck")
	}
	return filepath.Join(exeDir, "data")
}

func (l layout) defaults() map[string]string {
	slash := filepath.ToSlash
	return map[string]string{
		"RUNNERDECK_SETTINGS_FILE": slash(filepath.Join(l.dataDir, "settings.json")),
		"RUNNERDECK_HISTORY_FILE":  slash(filepath.Join(l.dataDir, "db", "history.sqlite")),
		"RUNNERDECK_POOL_DIR":      slash(filepath.Join(l.dataDir, "runners")),
	}
}

func withDefaults(base []string, defaults map[string]string) []string {
	present := map[string]bool{}
	for _, entry := range base {
		if name, value, ok := strings.Cut(entry, "="); ok && value != "" {
			present[strings.ToUpper(name)] = true
		}
	}
	out := append([]string{}, base...)
	for name, value := range defaults {
		if !present[name] {
			out = append(out, name+"="+value)
		}
	}
	return out
}

func isLoopback(host string) bool {
	switch strings.ToLower(strings.Trim(host, "[]")) {
	case "127.0.0.1", "::1", "localhost":
		return true
	}
	return false
}

func tailLines(text string, n int) string {
	lines := strings.Split(strings.TrimRight(text, "\r\n"), "\n")
	if len(lines) > n {
		lines = lines[len(lines)-n:]
	}
	return strings.Join(lines, "\n")
}
