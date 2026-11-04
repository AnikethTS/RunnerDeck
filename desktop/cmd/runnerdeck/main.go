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

const totalSteps = 4

func main() {
	os.Exit(run(os.Args[1:]))
}

func run(args []string) int {
	opts, err := parseOptions(args, os.Getenv)
	if err != nil {
		fmt.Fprintln(os.Stderr, "runnerdeck:", err)
		if defaultMode == "tray" {
			showErrorBox(err.Error())
		}
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

	if trayRequested(opts) {
		return runTray(opts, l)
	}

	c := newConsole(opts.assumeYes)
	fmt.Print(bannerText(appVersion(l)))
	fmt.Println("  Welcome! Setting RunnerDeck up on this computer. A few quick checks first.")

	code, group, watcher, server, exited, logPath := start(c, opts, l)
	if code != 0 {
		c.pauseBeforeClosing()
		return code
	}
	defer group.close()

	base := fmt.Sprintf("http://%s:%d", opts.host, opts.port)
	suffix := ""
	if isLoopback(opts.host) {
		suffix = " (localhost only)"
	}
	c.ok(fmt.Sprintf("RunnerDeck is running on %s%s", base, suffix))
	fmt.Printf("\n  Server log: %s\n", logPath)
	fmt.Println("  Press Ctrl+C or close this window to stop RunnerDeck.")
	if !opts.noBrowser {
		openBrowser(base)
	}

	signals := make(chan os.Signal, 1)
	signal.Notify(signals, os.Interrupt, syscall.SIGTERM)
	select {
	case <-signals:
	case <-exited:
		fmt.Fprintln(os.Stderr, "runnerdeck: the server stopped unexpectedly. See the server log above.")
	}
	stop(watcher, server)
	return 0
}

func start(c ui, opts options, l layout) (int, *processGroup, *exec.Cmd, *exec.Cmd, chan error, string) {
	none := func(code int) (int, *processGroup, *exec.Cmd, *exec.Cmd, chan error, string) {
		return code, nil, nil, nil, nil, ""
	}

	c.step(1, totalSteps, "Preparing RunnerDeck")
	if len(payloadZip) > 0 {
		if needsUnpack(l, payloadZip) {
			c.info("Unpacking the bundled PHP and dashboard (about 90 MB, first start only)...")
		}
		unpacked, err := ensureRuntime(l, payloadZip)
		if err != nil {
			c.fail(err.Error())
			return none(1)
		}
		l = unpacked
	}
	if _, err := os.Stat(l.php); err != nil {
		c.fail(fmt.Sprintf("The bundled PHP was not found at %s", l.php))
		c.info("Keep runnerdeck.exe next to the php and app folders.")
		return none(1)
	}
	for _, dir := range []string{l.dataDir, filepath.Join(l.dataDir, "db"), filepath.Join(l.dataDir, "runners")} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			c.fail(fmt.Sprintf("Cannot create %s: %v", dir, err))
			return none(1)
		}
	}
	c.ok("Your settings and runners are kept in " + l.dataDir)
	env := withDefaults(os.Environ(), l.defaults())

	c.step(2, totalSteps, "Checking PHP")
	if !ensurePHPRuntime(c, l, env) {
		return none(1)
	}

	c.step(3, totalSteps, "Checking the GitHub CLI")
	env = ensureGitHubCLI(c, env)

	c.step(4, totalSteps, "Starting the dashboard")
	if !isLoopback(opts.host) && !authEnabled(l, env) {
		c.fail("Listening beyond this computer requires a TOTP login. Run: php\\php.exe app\\bin\\setup-totp.php")
		return none(1)
	}
	group, err := newProcessGroup()
	if err != nil {
		c.warn("PHP may keep running if you close this window: " + err.Error())
	}

	logPath := filepath.Join(l.dataDir, "server.log")
	logFile, err := os.OpenFile(logPath, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o644)
	if err != nil {
		c.fail(fmt.Sprintf("Cannot open %s: %v", logPath, err))
		return none(1)
	}

	watcher := phpCommand(l, env, logFile, filepath.Join(l.app, "bin", "watch-auto-restart.php"))
	server := phpCommand(l, env, logFile,
		"-d", "max_execution_time=0",
		"-S", fmt.Sprintf("%s:%d", opts.host, opts.port),
		"-t", filepath.Join(l.app, "public"))
	for _, cmd := range []*exec.Cmd{watcher, server} {
		hideWindow(cmd)
		if err := cmd.Start(); err != nil {
			c.fail(fmt.Sprintf("Cannot start %s: %v", filepath.Base(cmd.Args[len(cmd.Args)-1]), err))
			stop(watcher, server)
			return none(1)
		}
		group.add(cmd)
	}

	exited := make(chan error, 1)
	go func() { exited <- server.Wait() }()

	healthy, exitErr, died := waitHealthy(fmt.Sprintf("http://%s:%d/health.php", opts.host, opts.port), 40*time.Second, exited)
	if !healthy {
		if died {
			c.fail(fmt.Sprintf("The server stopped right away (%v).", exitErr))
		} else {
			c.fail("The server did not answer within 40 seconds.")
		}
		if data, readErr := os.ReadFile(logPath); readErr == nil && strings.TrimSpace(string(data)) != "" {
			c.info("Last lines of " + logPath + ":")
			c.info(tailLines(string(data), 15))
		} else {
			c.info("The server log is empty: " + logPath)
		}
		c.info(fmt.Sprintf("Is port %d already in use? Try: runnerdeck.exe --port 9000", opts.port))
		stop(watcher, server)
		return none(1)
	}
	return 0, group, watcher, server, exited, logPath
}

func appVersion(l layout) string {
	if embeddedVersion != "" {
		return embeddedVersion
	}
	data, err := os.ReadFile(filepath.Join(filepath.Dir(l.app), "VERSION"))
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

func authEnabled(l layout, env []string) bool {
	check := `require $argv[1] . "/src/bootstrap.php"; exit(\RunnerDeck\Configuration\Config::authEnabled() ? 0 : 1);`
	cmd := exec.Command(l.php, "-r", check, "--", l.app)
	cmd.Env = env
	hideWindow(cmd)
	return cmd.Run() == nil
}

func waitHealthy(url string, timeout time.Duration, exited <-chan error) (healthy bool, exitErr error, died bool) {
	client := &http.Client{Timeout: 2 * time.Second}
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		select {
		case err := <-exited:
			return false, err, true
		default:
		}
		if resp, err := client.Get(url); err == nil {
			resp.Body.Close()
			if resp.StatusCode == http.StatusOK {
				return true, nil, false
			}
		}
		time.Sleep(300 * time.Millisecond)
	}
	return false, nil, false
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
