package main

import (
	"fmt"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"runtime"
	"strings"
	"syscall"
	"time"
)

var embeddedVersion string

func main() {
	os.Exit(run(os.Args[1:]))
}

func run(args []string) int {
	opts, err := parseOptions(args, os.Getenv)
	if err != nil {
		fmt.Fprintln(os.Stderr, "runnerdeck:", err)
		return 2
	}
	exe, err := os.Executable()
	if err != nil {
		fmt.Fprintln(os.Stderr, "runnerdeck: cannot locate itself:", err)
		return 1
	}
	l := newLayout(exe, os.Getenv)
	if opts.showVersion {
		fmt.Println("RunnerDeck", appVersion(l))
		return 0
	}
	if len(payloadZip) > 0 {
		fmt.Println("Preparing RunnerDeck (the first start takes a few seconds)...")
		unpacked, err := ensureRuntime(l, payloadZip)
		if err != nil {
			fmt.Fprintln(os.Stderr, "runnerdeck:", err)
			return 1
		}
		l = unpacked
	}
	if _, err := os.Stat(l.php); err != nil {
		fmt.Fprintf(os.Stderr, "runnerdeck: bundled PHP not found at %s\nKeep runnerdeck.exe next to the php and app folders.\n", l.php)
		return 1
	}
	for _, dir := range []string{l.dataDir, filepath.Join(l.dataDir, "db"), filepath.Join(l.dataDir, "runners")} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			fmt.Fprintf(os.Stderr, "runnerdeck: cannot create %s: %v\n", dir, err)
			return 1
		}
	}
	env := withDefaults(os.Environ(), l.defaults())

	runPHP(l, env, os.Stdout, filepath.Join(l.app, "bin", "banner.php"))

	if _, err := exec.LookPath("gh"); err != nil {
		fmt.Println("Warning: the GitHub CLI (gh) was not found on PATH.")
		fmt.Println("Install it with: winget install GitHub.cli   then run: gh auth login")
		fmt.Println()
	}

	if !isLoopback(opts.host) && !authEnabled(l, env) {
		fmt.Fprintln(os.Stderr, "runnerdeck: a non-loopback bind requires TOTP login. Run: php\\php.exe app\\bin\\setup-totp.php")
		return 1
	}

	group, err := newProcessGroup()
	if err != nil {
		fmt.Fprintln(os.Stderr, "runnerdeck: warning: children may outlive this window:", err)
	}
	defer group.close()

	logPath := filepath.Join(l.dataDir, "server.log")
	logFile, err := os.OpenFile(logPath, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o644)
	if err != nil {
		fmt.Fprintf(os.Stderr, "runnerdeck: cannot open %s: %v\n", logPath, err)
		return 1
	}
	defer logFile.Close()

	watcher := phpCommand(l, env, logFile, filepath.Join(l.app, "bin", "watch-auto-restart.php"))
	server := phpCommand(l, env, logFile,
		"-d", "max_execution_time=0",
		"-S", fmt.Sprintf("%s:%d", opts.host, opts.port),
		"-t", filepath.Join(l.app, "public"))
	for _, cmd := range []*exec.Cmd{watcher, server} {
		hideWindow(cmd)
		if err := cmd.Start(); err != nil {
			fmt.Fprintf(os.Stderr, "runnerdeck: cannot start %s: %v\n", filepath.Base(cmd.Args[len(cmd.Args)-1]), err)
			stop(watcher, server)
			return 1
		}
		group.add(cmd)
	}

	exited := make(chan error, 1)
	go func() { exited <- server.Wait() }()

	base := fmt.Sprintf("http://%s:%d", opts.host, opts.port)
	if !waitHealthy(base+"/health.php", 40*time.Second, exited) {
		fmt.Fprintf(os.Stderr, "runnerdeck: the server did not start. Last lines of %s:\n", logPath)
		if data, err := os.ReadFile(logPath); err == nil {
			fmt.Fprintln(os.Stderr, tailLines(string(data), 15))
		}
		stop(watcher, server)
		return 1
	}

	suffix := ""
	if isLoopback(opts.host) {
		suffix = " (localhost only)"
	}
	fmt.Printf("RunnerDeck is running on %s%s\n", base, suffix)
	fmt.Printf("Server log: %s\n", logPath)
	fmt.Println("Press Ctrl+C or close this window to stop.")
	if !opts.noBrowser {
		openBrowser(base)
	}

	signals := make(chan os.Signal, 1)
	signal.Notify(signals, os.Interrupt, syscall.SIGTERM)
	select {
	case <-signals:
	case <-exited:
		fmt.Fprintln(os.Stderr, "runnerdeck: the server stopped unexpectedly.")
	}
	stop(watcher, server)
	return 0
}

func appVersion(l layout) string {
	if embeddedVersion != "" {
		return embeddedVersion
	}
	data, err := os.ReadFile(filepath.Join(l.app, "VERSION"))
	if err != nil {
		return "unknown"
	}
	return strings.TrimSpace(string(data))
}

func phpCommand(l layout, env []string, out *os.File, args ...string) *exec.Cmd {
	cmd := exec.Command(l.php, args...)
	cmd.Dir = l.app
	cmd.Env = env
	cmd.Stdout = out
	cmd.Stderr = out
	return cmd
}

func runPHP(l layout, env []string, out *os.File, args ...string) {
	cmd := phpCommand(l, env, out, args...)
	hideWindow(cmd)
	_ = cmd.Run()
}

func authEnabled(l layout, env []string) bool {
	check := `require $argv[1] . "/src/bootstrap.php"; exit(\RunnerDeck\Core\Config::authEnabled() ? 0 : 1);`
	cmd := exec.Command(l.php, "-r", check, "--", l.app)
	cmd.Env = env
	hideWindow(cmd)
	return cmd.Run() == nil
}

func waitHealthy(url string, timeout time.Duration, exited <-chan error) bool {
	client := &http.Client{Timeout: 2 * time.Second}
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		select {
		case <-exited:
			return false
		default:
		}
		if resp, err := client.Get(url); err == nil {
			resp.Body.Close()
			if resp.StatusCode == http.StatusOK {
				return true
			}
		}
		time.Sleep(300 * time.Millisecond)
	}
	return false
}

func stop(cmds ...*exec.Cmd) {
	for _, cmd := range cmds {
		if cmd != nil && cmd.Process != nil {
			_ = cmd.Process.Kill()
		}
	}
}

func openBrowser(url string) {
	var cmd *exec.Cmd
	switch runtime.GOOS {
	case "windows":
		cmd = exec.Command("rundll32", "url.dll,FileProtocolHandler", url)
	case "darwin":
		cmd = exec.Command("open", url)
	default:
		cmd = exec.Command("xdg-open", url)
	}
	_ = cmd.Start()
}
