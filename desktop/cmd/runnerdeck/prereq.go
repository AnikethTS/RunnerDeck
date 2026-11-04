package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
)

const (
	vcRedistURL        = "https://aka.ms/vs/17/release/vc_redist.x64.exe"
	vcRedistPage       = "https://aka.ms/vs/17/release/vc_redist.x64.exe"
	statusDLLNotFound  = 0xC0000135
	exitAlreadyNewer   = 1638
	exitRebootRequired = 3010
)

type phpCheck struct {
	ok       bool
	code     int
	output   string
	startErr error
}

func isMissingRuntime(code int) bool {
	return uint32(code) == statusDLLNotFound
}

func firstLine(text string) string {
	line, _, _ := strings.Cut(strings.TrimSpace(text), "\n")
	return strings.TrimSpace(line)
}

func checkPHP(l layout, env []string) phpCheck {
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, l.php, "-v")
	cmd.Dir = l.app
	cmd.Env = env
	hideWindow(cmd)
	out, err := cmd.CombinedOutput()
	if err == nil {
		return phpCheck{ok: true, output: string(out)}
	}
	var exitErr *exec.ExitError
	if errors.As(err, &exitErr) {
		return phpCheck{code: exitErr.ExitCode(), output: string(out)}
	}
	return phpCheck{startErr: err, output: string(out)}
}

func psQuote(value string) string {
	return "'" + strings.ReplaceAll(value, "'", "''") + "'"
}

func runPowerShell(script string) error {
	cmd := exec.Command("powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", script)
	hideWindow(cmd)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("%w: %s", err, strings.TrimSpace(string(out)))
	}
	return nil
}

func download(url, dest string) error {
	client := &http.Client{Timeout: 10 * time.Minute}
	resp, err := client.Get(url)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("the server answered %s", resp.Status)
	}
	file, err := os.Create(dest)
	if err != nil {
		return err
	}
	size, copyErr := io.Copy(file, resp.Body)
	if closeErr := file.Close(); copyErr == nil {
		copyErr = closeErr
	}
	if copyErr != nil {
		return copyErr
	}
	if size < 1<<20 {
		return fmt.Errorf("the download is only %d bytes, which is too small to be the installer", size)
	}
	return nil
}

func verifyMicrosoftSignature(path string) error {
	script := fmt.Sprintf(
		"$s = Get-AuthenticodeSignature -LiteralPath %s; "+
			"if ($s.Status -ne 'Valid' -or $s.SignerCertificate.Subject -notmatch 'O=Microsoft Corporation') { exit 1 }",
		psQuote(path),
	)
	return runPowerShell(script)
}

func installVCRedist(c ui) error {
	dest := filepath.Join(os.TempDir(), "runnerdeck-vc_redist.x64.exe")
	defer os.Remove(dest)
	c.info("Downloading the installer from Microsoft...")
	if err := download(vcRedistURL, dest); err != nil {
		return fmt.Errorf("download failed: %w", err)
	}
	if err := verifyMicrosoftSignature(dest); err != nil {
		return fmt.Errorf("the download is not signed by Microsoft, so it was not run: %w", err)
	}
	c.ok("The installer is signed by Microsoft Corporation")
	c.info("Installing. Windows will ask for permission, please choose Yes...")
	script := fmt.Sprintf(
		"$p = Start-Process -FilePath %s -ArgumentList '/install','/quiet','/norestart' -Verb RunAs -Wait -PassThru; exit $p.ExitCode",
		psQuote(dest),
	)
	err := runPowerShell(script)
	if err == nil {
		return nil
	}
	var exitErr *exec.ExitError
	if errors.As(err, &exitErr) {
		if code := exitErr.ExitCode(); code == exitAlreadyNewer || code == exitRebootRequired {
			return nil
		}
	}
	return fmt.Errorf("the installer did not finish (it may have been cancelled): %w", err)
}

func ensurePHPRuntime(c ui, l layout, env []string) bool {
	check := checkPHP(l, env)
	if check.ok {
		c.ok(firstLine(check.output))
		return true
	}
	if check.startErr == nil && !isMissingRuntime(check.code) {
		c.fail(fmt.Sprintf("PHP stopped with exit code %d.", check.code))
		if out := strings.TrimSpace(check.output); out != "" {
			c.info(out)
		}
		return false
	}
	if check.startErr != nil {
		c.fail("PHP could not be started: " + check.startErr.Error())
		return false
	}
	c.warn("Windows is missing the Microsoft Visual C++ runtime that PHP needs.")
	c.info("It is a free component from Microsoft (about 18 MB).")
	if !c.confirm("Install it now?", true) {
		c.info("Install it from " + vcRedistPage)
		c.info("then start RunnerDeck again.")
		return false
	}
	if err := installVCRedist(c); err != nil {
		c.fail(err.Error())
		c.info("You can install it by hand from " + vcRedistPage)
		return false
	}
	check = checkPHP(l, env)
	if !check.ok {
		c.fail(fmt.Sprintf("PHP still does not start (exit code %d). Restart Windows and try again.", check.code))
		return false
	}
	c.ok("Installed. " + firstLine(check.output))
	return true
}

type ghState int

const (
	ghMissing ghState = iota
	ghLoggedOut
	ghReady
)

func findGH() string {
	if path, err := exec.LookPath("gh"); err == nil {
		return path
	}
	for _, root := range []string{os.Getenv("ProgramFiles"), os.Getenv("LOCALAPPDATA")} {
		if root == "" {
			continue
		}
		for _, sub := range []string{"GitHub CLI", filepath.Join("Programs", "GitHub CLI")} {
			candidate := filepath.Join(root, sub, "gh.exe")
			if _, err := os.Stat(candidate); err == nil {
				return candidate
			}
		}
	}
	return ""
}

func ghStatus(path string, env []string) ghState {
	if path == "" {
		return ghMissing
	}
	cmd := exec.Command(path, "auth", "status")
	cmd.Env = env
	hideWindow(cmd)
	if cmd.Run() == nil {
		return ghReady
	}
	return ghLoggedOut
}

func prependPath(env []string, dir string) []string {
	out := make([]string, 0, len(env)+1)
	replaced := false
	for _, entry := range env {
		name, value, ok := strings.Cut(entry, "=")
		if ok && strings.EqualFold(name, "PATH") {
			out = append(out, name+"="+dir+string(os.PathListSeparator)+value)
			replaced = true
			continue
		}
		out = append(out, entry)
	}
	if !replaced {
		out = append(out, "PATH="+dir)
	}
	return out
}

func ensureGitHubCLI(c ui, env []string) []string {
	path := findGH()
	state := ghStatus(path, env)
	if state == ghReady {
		c.ok("The GitHub CLI is installed and signed in")
		return env
	}
	if state == ghMissing {
		c.warn("The GitHub CLI (gh) is not installed. RunnerDeck uses it to talk to GitHub.")
		winget, wingetErr := exec.LookPath("winget")
		if wingetErr != nil {
			c.info("Install it from https://cli.github.com and start RunnerDeck again.")
			return env
		}
		if !c.confirm("Install it now with winget?", true) {
			c.info("You can install it later: winget install GitHub.cli")
			return env
		}
		if err := c.runVisible(winget, "install", "--id", "GitHub.cli", "-e",
			"--accept-package-agreements", "--accept-source-agreements"); err != nil {
			c.fail("winget could not install it: " + err.Error())
			c.info("Install it from https://cli.github.com and start RunnerDeck again.")
			return env
		}
		path = findGH()
		if path == "" {
			c.warn("It installed, but it is not visible yet. Close this window and start RunnerDeck again.")
			return env
		}
		env = prependPath(env, filepath.Dir(path))
		state = ghStatus(path, env)
		if state == ghReady {
			c.ok("The GitHub CLI is installed and signed in")
			return env
		}
	}
	c.warn("The GitHub CLI is not signed in to GitHub yet.")
	if !c.confirm("Sign in now? A browser window will open.", true) {
		c.info("You can sign in later with: gh auth login")
		return env
	}
	if err := c.runVisible(path, "auth", "login", "--hostname", "github.com", "--web", "--git-protocol", "https"); err != nil {
		c.fail("Sign-in did not finish: " + err.Error())
		c.info("You can try again later with: gh auth login")
		return env
	}
	c.ok("Signed in to GitHub")
	return env
}
