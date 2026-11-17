//go:build windows

package main

import (
	"os"

	"golang.org/x/sys/windows"
)

// attachParentConsole lets the windowless tray build print to the terminal
// that started it. It reports whether a console is available; when it is
// not, the caller hides the console window of the child process instead.
// Streams that were redirected (a pipe or a file) are left alone.
func attachParentConsole() bool {
	const attachParentProcess = ^uint32(0)
	attach := windows.NewLazySystemDLL("kernel32.dll").NewProc("AttachConsole")
	result, _, err := attach.Call(uintptr(attachParentProcess))
	attached := result != 0 || err == windows.ERROR_ACCESS_DENIED

	reopen(windows.STD_INPUT_HANDLE, "CONIN$", os.O_RDONLY, &os.Stdin)
	reopen(windows.STD_OUTPUT_HANDLE, "CONOUT$", os.O_WRONLY, &os.Stdout)
	reopen(windows.STD_ERROR_HANDLE, "CONOUT$", os.O_WRONLY, &os.Stderr)
	return attached
}

func reopen(id uint32, name string, flag int, target **os.File) {
	if handle, err := windows.GetStdHandle(id); err == nil && handle != 0 {
		return
	}
	if file, err := os.OpenFile(name, flag, 0); err == nil {
		*target = file
	}
}
