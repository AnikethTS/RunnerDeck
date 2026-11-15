package main

import (
	"path/filepath"
	"reflect"
	"testing"
)

func TestIsCLIOnlyForTheCliSubcommand(t *testing.T) {
	if !isCLI([]string{"cli", "list"}) || !isCLI([]string{"cli"}) {
		t.Fatal("cli should select the command line")
	}
	for _, args := range [][]string{nil, {}, {"--port", "9000"}, {"list"}, {"--version"}} {
		if isCLI(args) {
			t.Fatalf("%v should not select the command line", args)
		}
	}
}

func TestCliCommandRunsTheBundledPHPOnTheCliScript(t *testing.T) {
	l := layout{php: filepath.Join("rt", "php", "php.exe"), app: filepath.Join("rt", "app")}
	cmd := cliCommand(l, []string{"A=1"}, []string{"logs", "runner-1", "-n", "5"})

	want := []string{
		filepath.Join("rt", "php", "php.exe"),
		filepath.Join("rt", "app", "bin", "runnerdeck.php"),
		"logs", "runner-1", "-n", "5",
	}
	if !reflect.DeepEqual(cmd.Args, want) {
		t.Fatalf("args = %v, want %v", cmd.Args, want)
	}
	if !reflect.DeepEqual(cmd.Env, []string{"A=1"}) {
		t.Fatalf("env = %v", cmd.Env)
	}
}

func TestCliCommandWithoutArgumentsStillNamesTheScript(t *testing.T) {
	l := layout{php: "php.exe", app: "app"}
	cmd := cliCommand(l, nil, nil)
	if len(cmd.Args) != 2 || cmd.Args[1] != filepath.Join("app", "bin", "runnerdeck.php") {
		t.Fatalf("args = %v", cmd.Args)
	}
}
