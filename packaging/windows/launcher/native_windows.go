//go:build windows

package main

import (
	"os/exec"
	"syscall"

	"golang.org/x/sys/windows"
)

const (
	appTitle = "RunnerDeck"
	idYes    = 6
)

func messageBox(text string, flags uint32) int32 {
	title, _ := windows.UTF16PtrFromString(appTitle)
	body, _ := windows.UTF16PtrFromString(text)
	result, _ := windows.MessageBox(0, body, title, flags|windows.MB_SETFOREGROUND)
	return result
}

func showErrorBox(text string) {
	messageBox(text, windows.MB_OK|windows.MB_ICONERROR)
}

func askYesNoBox(text string) bool {
	return messageBox(text, windows.MB_YESNO|windows.MB_ICONQUESTION) == idYes
}

func acquireSingleInstance() bool {
	name, _ := windows.UTF16PtrFromString("RunnerDeckTray")
	_, err := windows.CreateMutex(nil, false, name)
	return err != windows.ERROR_ALREADY_EXISTS
}

func runInNewConsole(name string, args ...string) error {
	full := append([]string{"/c", "start", appTitle + " setup", "/wait", name}, args...)
	cmd := exec.Command("cmd.exe", full...)
	cmd.SysProcAttr = &syscall.SysProcAttr{CreationFlags: windows.CREATE_NO_WINDOW}
	return cmd.Run()
}
