package main

import (
	_ "embed"
	"fmt"
	"os/exec"
	"path/filepath"
	"time"

	"fyne.io/systray"
)

//go:embed icon.ico
var trayIcon []byte

var defaultMode string

func runTray(opts options, l layout) int {
	base := fmt.Sprintf("http://%s:%d", opts.host, opts.port)
	if !acquireSingleInstance() {
		openBrowser(base)
		return 0
	}

	exitCode := 0
	quit := make(chan struct{})
	var (
		group   *processGroup
		watcher *exec.Cmd
		server  *exec.Cmd
	)

	onReady := func() {
		systray.SetIcon(trayIcon)
		systray.SetTitle("RunnerDeck")
		systray.SetTooltip("RunnerDeck - starting")
		open := systray.AddMenuItem("Open dashboard", "Open RunnerDeck in your browser")
		logItem := systray.AddMenuItem("Show server log", "Open the server log")
		systray.AddSeparator()
		quitItem := systray.AddMenuItem("Quit RunnerDeck", "Stop RunnerDeck")

		go func() {
			for {
				select {
				case <-open.ClickedCh:
					openBrowser(base)
				case <-logItem.ClickedCh:
					_ = exec.Command("notepad.exe", filepath.Join(l.dataDir, "server.log")).Start()
				case <-quitItem.ClickedCh:
					systray.Quit()
					return
				case <-quit:
					return
				}
			}
		}()

		go func() {
			d := &dialogUI{
				status:    func(text string) { systray.SetTooltip("RunnerDeck - " + text) },
				showError: showErrorBox,
				ask:       askYesNoBox,
				runWindow: runInNewConsole,
			}
			code, g, w, s, exited, _ := start(d, opts, l)
			if code != 0 {
				d.flushError()
				exitCode = code
				systray.Quit()
				return
			}
			group, watcher, server = g, w, s
			systray.SetTooltip("RunnerDeck - running on " + base)
			if !opts.noBrowser {
				openBrowser(base)
			}
			select {
			case <-exited:
				showErrorBox("The RunnerDeck server stopped unexpectedly. Open the server log from the tray menu for details.")
				exitCode = 1
				systray.Quit()
			case <-quit:
			}
		}()
	}

	onExit := func() {
		close(quit)
		stop(watcher, server)
		group.close()
		time.Sleep(100 * time.Millisecond)
	}

	systray.Run(onReady, onExit)
	return exitCode
}

func trayRequested(opts options) bool {
	return opts.tray || (defaultMode == "tray" && !opts.console)
}
