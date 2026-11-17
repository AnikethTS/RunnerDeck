//go:build !windows

package main

func attachParentConsole() bool { return true }
