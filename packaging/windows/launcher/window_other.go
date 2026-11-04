//go:build !windows

package main

func newAppWindow(l layout, hidden bool) appWindow { return nil }

func showExistingWindow() bool { return false }
