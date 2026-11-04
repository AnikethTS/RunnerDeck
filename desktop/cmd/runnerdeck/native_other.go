//go:build !windows

package main

import (
	"os"
	"os/exec"
)

func showErrorBox(text string) { os.Stderr.WriteString(text + "\n") }

func askYesNoBox(text string) bool { return false }

func acquireSingleInstance() bool { return true }

func releaseSingleInstance() {}

func runInNewConsole(name string, args ...string) error {
	cmd := exec.Command(name, args...)
	cmd.Stdin = os.Stdin
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr
	return cmd.Run()
}
