//go:build windows

package main

import (
	"os"
	"path/filepath"
	"runtime"
	"sync/atomic"
	"unsafe"

	webview2 "github.com/jchv/go-webview2"
	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
)

const (
	wmClose        = 0x0010
	wmSetIcon      = 0x0080
	swHide         = 0
	swShow         = 5
	swRestore      = 9
	imageIcon      = 1
	lrLoadFromFile = 0x10
	lrDefaultSize  = 0x40
	webviewClient  = `\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}`
)

var (
	user32               = windows.NewLazySystemDLL("user32.dll")
	procShowWindow       = user32.NewProc("ShowWindow")
	procIsIconic         = user32.NewProc("IsIconic")
	procSetForeground    = user32.NewProc("SetForegroundWindow")
	procSetWindowLongPtr = user32.NewProc("SetWindowLongPtrW")
	procCallWindowProc   = user32.NewProc("CallWindowProcW")
	procLoadImage        = user32.NewProc("LoadImageW")
	procSendMessage      = user32.NewProc("SendMessageW")
	procFindWindow       = user32.NewProc("FindWindowW")
	gwlpWndProc          = ^uintptr(3)
	activeWindow         *edgeWindow
)

func init() {
	runtime.LockOSThread()
}

type edgeWindow struct {
	view     webview2.WebView
	hwnd     uintptr
	original uintptr
	quitting atomic.Bool
}

func webview2Installed() bool {
	places := []struct {
		root registry.Key
		path string
	}{
		{registry.LOCAL_MACHINE, `SOFTWARE\WOW6432Node` + webviewClient},
		{registry.LOCAL_MACHINE, `SOFTWARE` + webviewClient},
		{registry.CURRENT_USER, `Software` + webviewClient},
	}
	for _, place := range places {
		key, err := registry.OpenKey(place.root, place.path, registry.QUERY_VALUE)
		if err != nil {
			continue
		}
		version, _, err := key.GetStringValue("pv")
		key.Close()
		if err == nil && version != "" && version != "0.0.0.0" {
			return true
		}
	}
	return false
}

func newAppWindow(l layout, hidden bool) appWindow {
	if !webview2Installed() {
		return nil
	}
	dataPath := filepath.Join(l.dataDir, "webview")
	if err := os.MkdirAll(dataPath, 0o755); err != nil {
		return nil
	}
	view := webview2.NewWithOptions(webview2.WebViewOptions{
		DataPath:      dataPath,
		AutoFocus:     true,
		WindowOptions: webview2.WindowOptions{Title: appTitle, Width: 1360, Height: 880, Center: true},
	})
	if view == nil {
		return nil
	}
	e := &edgeWindow{view: view, hwnd: uintptr(view.Window())}
	activeWindow = e
	e.original, _, _ = procSetWindowLongPtr.Call(e.hwnd, gwlpWndProc, windows.NewCallback(subclassProc))
	e.setIcon(filepath.Join(l.exeDir, "RunnerDeck.ico"))
	if hidden {
		procShowWindow.Call(e.hwnd, swHide)
	}
	view.SetHtml(splashHTML("Starting RunnerDeck..."))
	return e
}

func subclassProc(hwnd, msg, wp, lp uintptr) uintptr {
	e := activeWindow
	if msg == wmClose && !e.quitting.Load() {
		procShowWindow.Call(hwnd, swHide)
		return 0
	}
	result, _, _ := procCallWindowProc.Call(e.original, hwnd, uintptr(msg), wp, lp)
	return result
}

func (e *edgeWindow) setIcon(path string) {
	if _, err := os.Stat(path); err != nil {
		return
	}
	name, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return
	}
	// nosemgrep: go.lang.security.audit.unsafe.use-of-unsafe-block
	icon, _, _ := procLoadImage.Call(0, uintptr(unsafe.Pointer(name)), imageIcon, 0, 0, lrLoadFromFile|lrDefaultSize)
	if icon == 0 {
		return
	}
	procSendMessage.Call(e.hwnd, wmSetIcon, 1, icon)
	procSendMessage.Call(e.hwnd, wmSetIcon, 0, icon)
}

func (e *edgeWindow) Run() { e.view.Run() }

func (e *edgeWindow) Show() {
	if iconic, _, _ := procIsIconic.Call(e.hwnd); iconic != 0 {
		procShowWindow.Call(e.hwnd, swRestore)
	} else {
		procShowWindow.Call(e.hwnd, swShow)
	}
	procSetForeground.Call(e.hwnd)
}

func (e *edgeWindow) Close() {
	e.quitting.Store(true)
	e.view.Destroy()
}

func (e *edgeWindow) Navigate(url string) {
	e.view.Dispatch(func() { e.view.Navigate(url) })
}

func (e *edgeWindow) ShowStatus(text string) {
	e.view.Dispatch(func() { e.view.SetHtml(splashHTML(text)) })
}

func showExistingWindow() bool {
	class, _ := windows.UTF16PtrFromString("webview")
	title, _ := windows.UTF16PtrFromString(appTitle)
	// nosemgrep: go.lang.security.audit.unsafe.use-of-unsafe-block
	hwnd, _, _ := procFindWindow.Call(uintptr(unsafe.Pointer(class)), uintptr(unsafe.Pointer(title)))
	if hwnd == 0 {
		return false
	}
	procShowWindow.Call(hwnd, swShow)
	procSetForeground.Call(hwnd)
	return true
}
