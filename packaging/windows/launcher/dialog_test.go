package main

import (
	"strings"
	"testing"
)

func testDialog() (*dialogUI, *[]string, *[]string, *[]string) {
	var statuses, errors, questions []string
	d := &dialogUI{
		status:    func(s string) { statuses = append(statuses, s) },
		showError: func(s string) { errors = append(errors, s) },
		ask:       func(s string) bool { questions = append(questions, s); return true },
		runWindow: func(string, ...string) error { return nil },
	}
	return d, &statuses, &errors, &questions
}

func TestDialogStepsUpdateTheTooltip(t *testing.T) {
	d, statuses, _, _ := testDialog()
	d.step(2, 4, "Checking PHP")
	d.info("Unpacking")
	if got := strings.Join(*statuses, "|"); got != "Checking PHP...|Unpacking" {
		t.Fatalf("statuses = %q", got)
	}
}

func TestDialogShowsFailureWithItsHints(t *testing.T) {
	d, _, errors, _ := testDialog()
	d.step(4, 4, "Starting")
	d.fail("The server stopped")
	d.info("Try another port")
	d.flushError()
	if len(*errors) != 1 || !strings.Contains((*errors)[0], "The server stopped\nTry another port") {
		t.Fatalf("errors = %q", *errors)
	}
	d.flushError()
	if len(*errors) != 1 {
		t.Fatal("a flushed failure must not be shown twice")
	}
}

func TestDialogConfirmIncludesTheWarning(t *testing.T) {
	d, _, errors, questions := testDialog()
	d.warn("The Visual C++ runtime is missing.")
	if !d.confirm("Install it now?", true) {
		t.Fatal("expected the dialog answer to be returned")
	}
	if len(*questions) != 1 || !strings.Contains((*questions)[0], "missing.\n\nInstall it now?") {
		t.Fatalf("questions = %q", *questions)
	}
	d.flushError()
	if len(*errors) != 0 {
		t.Fatal("a confirmed warning must not also be shown as an error")
	}
}

func TestTrayIsOptInUnlessBuiltAsTheTrayApp(t *testing.T) {
	if trayRequested(options{}) {
		t.Fatal("the console build must not start the tray by default")
	}
	if !trayRequested(options{tray: true}) {
		t.Fatal("--tray must start the tray")
	}
	defaultMode = "tray"
	defer func() { defaultMode = "" }()
	if !trayRequested(options{}) || trayRequested(options{console: true}) {
		t.Fatal("the tray build starts the tray unless --console is given")
	}
}
