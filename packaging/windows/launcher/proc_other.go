//go:build !windows

package main

import "os/exec"

type processGroup struct{}

func newProcessGroup() (*processGroup, error) { return &processGroup{}, nil }

func (g *processGroup) add(cmd *exec.Cmd) {}

func (g *processGroup) close() {}

func hideWindow(cmd *exec.Cmd) {}
