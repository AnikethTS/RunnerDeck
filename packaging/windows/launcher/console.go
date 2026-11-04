package main

import (
	"bufio"
	"fmt"
	"io"
	"os"
	"strings"
)

type console struct {
	in          *bufio.Reader
	out         io.Writer
	interactive bool
	assumeYes   bool
}

func newConsole(assumeYes bool) *console {
	info, err := os.Stdin.Stat()
	interactive := err == nil && info.Mode()&os.ModeCharDevice != 0
	return &console{in: bufio.NewReader(os.Stdin), out: os.Stdout, interactive: interactive, assumeYes: assumeYes}
}

func (c *console) step(n, total int, title string) {
	fmt.Fprintf(c.out, "\n[%d/%d] %s\n", n, total, title)
}

func (c *console) ok(msg string)   { fmt.Fprintf(c.out, "      [ OK ] %s\n", msg) }
func (c *console) warn(msg string) { fmt.Fprintf(c.out, "      [ !! ] %s\n", msg) }
func (c *console) fail(msg string) { fmt.Fprintf(c.out, "      [FAIL] %s\n", msg) }
func (c *console) info(msg string) { fmt.Fprintf(c.out, "             %s\n", msg) }

func (c *console) confirm(question string, defaultYes bool) bool {
	if c.assumeYes {
		fmt.Fprintf(c.out, "      %s yes (--yes)\n", question)
		return true
	}
	if !c.interactive {
		return false
	}
	hint := "[Y/n]"
	if !defaultYes {
		hint = "[y/N]"
	}
	fmt.Fprintf(c.out, "      %s %s ", question, hint)
	text, err := c.in.ReadString('\n')
	if err != nil && text == "" {
		return defaultYes
	}
	return parseYesNo(text, defaultYes)
}

func (c *console) pauseBeforeClosing() {
	if !c.interactive {
		return
	}
	fmt.Fprint(c.out, "\nPress Enter to close this window. ")
	_, _ = c.in.ReadString('\n')
}

func parseYesNo(text string, defaultYes bool) bool {
	switch strings.ToLower(strings.TrimSpace(text)) {
	case "y", "yes":
		return true
	case "n", "no":
		return false
	}
	return defaultYes
}
