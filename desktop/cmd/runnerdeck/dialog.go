package main

import "strings"

type dialogUI struct {
	status     func(string)
	showError  func(string)
	ask        func(string) bool
	runWindow  func(name string, args ...string) error
	lines      []string
	collecting bool
}

func (d *dialogUI) step(n, total int, title string) {
	d.collecting = false
	d.lines = nil
	d.status(title + "...")
}

func (d *dialogUI) ok(msg string) { d.collecting = false }

func (d *dialogUI) warn(msg string) {
	d.collecting = true
	d.lines = append(d.lines, msg)
}

func (d *dialogUI) fail(msg string) {
	d.collecting = true
	d.lines = append(d.lines, msg)
}

func (d *dialogUI) info(msg string) {
	if d.collecting {
		d.lines = append(d.lines, msg)
		return
	}
	d.status(msg)
}

func (d *dialogUI) confirm(question string, defaultYes bool) bool {
	text := strings.Join(append(d.lines, "", question), "\n")
	d.lines = nil
	d.collecting = false
	return d.ask(text)
}

func (d *dialogUI) runVisible(name string, args ...string) error {
	return d.runWindow(name, args...)
}

func (d *dialogUI) flushError() {
	if len(d.lines) == 0 {
		return
	}
	d.showError(strings.Join(d.lines, "\n"))
	d.lines = nil
}
