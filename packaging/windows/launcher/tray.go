package main

import (
	_ "embed"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"sync"
	"sync/atomic"
	"time"

	"fyne.io/systray"
)

//go:embed icon.ico
var trayIcon []byte

var defaultMode string

func runTray(opts options, l layout) int {
	base := fmt.Sprintf("http://%s:%d", opts.host, opts.port)
	if !acquireSingleInstance() {
		if !showExistingWindow() {
			openBrowser(base)
		}
		return 0
	}

	var win appWindow
	if !opts.noBrowser {
		win = newAppWindow(l, opts.background)
	}

	var (
		exitCode  atomic.Int32
		uninstall atomic.Bool
		mu        sync.Mutex
		group     *processGroup
		watcher   *exec.Cmd
		server    *exec.Cmd
	)
	uninstaller := filepath.Join(l.exeDir, "unins000.exe")
	_, statErr := os.Stat(uninstaller)
	canUninstall := statErr == nil
	quit := make(chan struct{})
	closeQuit := sync.OnceFunc(func() { close(quit) })
	cleanup := sync.OnceFunc(func() {
		mu.Lock()
		defer mu.Unlock()
		stop(watcher, server)
		group.close()
	})

	openDashboard := func() {
		if win != nil {
			win.Show()
			return
		}
		openBrowser(base)
	}
	requestQuit := func() {
		if win != nil {
			win.Close()
			return
		}
		systray.Quit()
	}
	setStatus := func(text string) {
		systray.SetTooltip("RunnerDeck - " + text)
		if win != nil {
			win.ShowStatus(text)
		}
	}

	onReady := func() {
		systray.SetIcon(trayIcon)
		systray.SetTitle("RunnerDeck")
		systray.SetTooltip("RunnerDeck - starting")
		open := systray.AddMenuItem("Open dashboard", "Open RunnerDeck")
		logItem := systray.AddMenuItem("Show server log", "Open the server log")
		systray.AddSeparator()
		var uninstallClicks <-chan struct{}
		if canUninstall {
			item := systray.AddMenuItem("Uninstall RunnerDeck...", "Remove RunnerDeck from this computer")
			uninstallClicks = item.ClickedCh
		}
		quitItem := systray.AddMenuItem("Quit RunnerDeck", "Stop RunnerDeck")

		go func() {
			for {
				select {
				case <-open.ClickedCh:
					openDashboard()
				case <-logItem.ClickedCh:
					_ = exec.Command("notepad.exe", filepath.Join(l.dataDir, "server.log")).Start()
				case <-uninstallClicks:
					uninstall.Store(true)
					requestQuit()
					return
				case <-quitItem.ClickedCh:
					requestQuit()
					return
				case <-quit:
					return
				}
			}
		}()

		go func() {
			d := &dialogUI{
				status:    setStatus,
				showError: showErrorBox,
				ask:       askYesNoBox,
				runWindow: runInNewConsole,
			}
			if opts.noPrompts {
				d.showError = func(text string) { fmt.Fprintln(os.Stderr, text) }
				d.ask = func(string) bool { return false }
			}
			code, g, w, s, exited, _ := start(d, opts, l)
			if code != 0 {
				d.flushError()
				exitCode.Store(int32(code))
				requestQuit()
				return
			}
			mu.Lock()
			group, watcher, server = g, w, s
			mu.Unlock()
			systray.SetTooltip("RunnerDeck - running on " + base)
			if win != nil {
				win.Navigate(base)
			} else if !opts.noBrowser {
				openBrowser(base)
			}
			select {
			case <-exited:
				if !opts.noPrompts {
					showErrorBox("The RunnerDeck server stopped unexpectedly. Open the server log from the tray menu for details.")
				}
				exitCode.Store(1)
				requestQuit()
			case <-quit:
			}
		}()
	}

	onExit := func() {
		closeQuit()
		cleanup()
	}

	if win == nil {
		systray.Run(onReady, onExit)
	} else {
		go systray.Run(onReady, onExit)
		win.Run()
		systray.Quit()
	}
	closeQuit()
	cleanup()
	time.Sleep(100 * time.Millisecond)
	if uninstall.Load() {
		releaseSingleInstance()
		_ = exec.Command(uninstaller).Start()
	}
	return int(exitCode.Load())
}

func trayRequested(opts options) bool {
	return opts.tray || (defaultMode == "tray" && !opts.console)
}
