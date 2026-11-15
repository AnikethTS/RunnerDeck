package main

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
)

// exitMissingDLL is STATUS_DLL_NOT_FOUND: PHP could not start because the
// Visual C++ runtime is missing.
const exitMissingDLL = 0xC0000135

// isCLI reports whether the arguments ask for the command line rather than
// the dashboard: RunnerDeck.exe cli list.
func isCLI(args []string) bool {
	return len(args) > 0 && args[0] == "cli"
}

// cliCommand runs the PHP command line (app/bin/runnerdeck.php) with the
// bundled PHP.
func cliCommand(l layout, env []string, args []string) *exec.Cmd {
	full := append([]string{filepath.Join(l.app, "bin", "runnerdeck.php")}, args...)
	cmd := exec.Command(l.php, full...)
	cmd.Env = env
	return cmd
}

func runCLI(args []string) int {
	attached := attachParentConsole()
	exe, err := os.Executable()
	if err != nil {
		fmt.Fprintln(os.Stderr, "runnerdeck: cannot locate itself:", err)
		return 1
	}
	l := newLayout(exe, os.Getenv)
	if len(payloadZip) > 0 {
		if needsUnpack(l, payloadZip) {
			fmt.Fprintln(os.Stderr, "Unpacking the bundled PHP and dashboard (about 90 MB, first start only)...")
		}
		unpacked, err := ensureRuntime(l, payloadZip)
		if err != nil {
			fmt.Fprintln(os.Stderr, "runnerdeck:", err)
			return 1
		}
		l = unpacked
	}
	if _, err := os.Stat(l.php); err != nil {
		fmt.Fprintf(os.Stderr, "runnerdeck: the bundled PHP was not found at %s\n", l.php)
		return 1
	}
	for _, dir := range []string{l.dataDir, filepath.Join(l.dataDir, "db"), filepath.Join(l.dataDir, "runners")} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			fmt.Fprintf(os.Stderr, "runnerdeck: cannot create %s: %v\n", dir, err)
			return 1
		}
	}

	cmd := cliCommand(l, withDefaults(os.Environ(), l.defaults()), args)
	cmd.Stdin = os.Stdin
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr
	if !attached {
		hideWindow(cmd)
	}
	if err := cmd.Run(); err != nil {
		var exit *exec.ExitError
		if errors.As(err, &exit) {
			if uint32(exit.ExitCode()) == exitMissingDLL {
				fmt.Fprintln(os.Stderr, "runnerdeck: PHP could not start. Start RunnerDeck once to install the Visual C++ runtime it needs.")
			}
			return exit.ExitCode()
		}
		fmt.Fprintln(os.Stderr, "runnerdeck:", err)
		return 1
	}
	return 0
}
