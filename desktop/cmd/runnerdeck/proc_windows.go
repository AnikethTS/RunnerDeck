//go:build windows

package main

import (
	"os/exec"
	"syscall"
	"unsafe"

	"golang.org/x/sys/windows"
)

type processGroup struct {
	job windows.Handle
}

func newProcessGroup() (*processGroup, error) {
	job, err := windows.CreateJobObject(nil, nil)
	if err != nil {
		return nil, err
	}
	info := windows.JOBOBJECT_EXTENDED_LIMIT_INFORMATION{
		BasicLimitInformation: windows.JOBOBJECT_BASIC_LIMIT_INFORMATION{
			LimitFlags: windows.JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE,
		},
	}
	_, err = windows.SetInformationJobObject(
		job,
		windows.JobObjectExtendedLimitInformation,
		// nosemgrep: go.lang.security.audit.unsafe.use-of-unsafe-block
		uintptr(unsafe.Pointer(&info)),
		// nosemgrep: go.lang.security.audit.unsafe.use-of-unsafe-block
		uint32(unsafe.Sizeof(info)),
	)
	if err != nil {
		windows.CloseHandle(job)
		return nil, err
	}
	return &processGroup{job: job}, nil
}

func (g *processGroup) add(cmd *exec.Cmd) {
	if g == nil || cmd.Process == nil {
		return
	}
	const access = windows.PROCESS_SET_QUOTA | windows.PROCESS_TERMINATE
	handle, err := windows.OpenProcess(access, false, uint32(cmd.Process.Pid))
	if err != nil {
		return
	}
	defer windows.CloseHandle(handle)
	_ = windows.AssignProcessToJobObject(g.job, handle)
}

func (g *processGroup) close() {
	if g != nil {
		windows.CloseHandle(g.job)
	}
}

func hideWindow(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: windows.CREATE_NO_WINDOW}
}
