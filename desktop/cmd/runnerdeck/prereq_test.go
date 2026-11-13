package main

import (
	"errors"
	"strings"
	"testing"
)

// lookPathStub answers exec.LookPath-like lookups for the named tools and
// reports every other tool as absent.
func lookPathStub(found ...string) func(string) (string, error) {
	present := make(map[string]bool, len(found))
	for _, name := range found {
		present[name] = true
	}
	return func(name string) (string, error) {
		if present[name] {
			return "C:/Windows/System32/" + name + ".exe", nil
		}
		return "", errors.New(name + ": executable file not found in %PATH%")
	}
}

func failLines(text string) []string {
	var lines []string
	for _, line := range strings.Split(text, "\n") {
		if strings.Contains(line, "[FAIL]") {
			lines = append(lines, line)
		}
	}
	return lines
}

func TestMissingDownloadToolsListsOnlyTheAbsentOnes(t *testing.T) {
	cases := map[string][]string{
		"curl and tar found": {"curl", "tar"},
		"only tar found":     {"tar"},
		"only curl found":    {"curl"},
		"neither found":      nil,
	}
	for name, found := range cases {
		want := map[string][]string{
			"curl and tar found": nil,
			"only tar found":     {"curl"},
			"only curl found":    {"tar"},
			"neither found":      {"curl", "tar"},
		}[name]
		got := missingDownloadTools(lookPathStub(found...))
		if len(got) != len(want) {
			t.Errorf("%s: missingDownloadTools() = %v, want %v", name, got, want)
			continue
		}
		for i := range want {
			if got[i] != want[i] {
				t.Errorf("%s: missingDownloadTools() = %v, want %v", name, got, want)
				break
			}
		}
	}
}

func TestMissingDownloadToolsLooksUpEveryTool(t *testing.T) {
	var asked []string
	lookPath := func(name string) (string, error) {
		asked = append(asked, name)
		return "", errors.New("not found")
	}
	missingDownloadTools(lookPath)
	if strings.Join(asked, ",") != "curl,tar" {
		t.Fatalf("looked up %v, want every download tool checked before deciding", asked)
	}
}

func TestEnsureDownloadToolsPassesWhenBothAreOnThePath(t *testing.T) {
	c, out := testConsole("", false, false)
	if !ensureDownloadTools(c, lookPathStub("curl", "tar")) {
		t.Fatal("curl and tar are present, so the check must not fail")
	}
	if !strings.Contains(out.String(), "[ OK ] curl and tar found") {
		t.Fatalf("the pass line should name both tools: %q", out.String())
	}
}

func TestEnsureDownloadToolsFailsNamingWhatIsMissingAndHowToFixIt(t *testing.T) {
	c, out := testConsole("", false, false)
	if ensureDownloadTools(c, lookPathStub()) {
		t.Fatal("an old Windows 10 build without curl and tar must fail the check")
	}
	text := out.String()
	lines := failLines(text)
	if len(lines) != 1 {
		t.Fatalf("expected one fail line, got %v in %q", lines, text)
	}
	for _, want := range []string{"curl", "tar"} {
		if !strings.Contains(lines[0], want) {
			t.Errorf("the fail line must name the missing tool %q: %q", want, lines[0])
		}
	}
	for _, want := range []string{"Windows 10 version 1803", "Windows Update"} {
		if !strings.Contains(text, want) {
			t.Errorf("the message must explain how to fix it, missing %q: %q", want, text)
		}
	}
}

func TestEnsureDownloadToolsNamesOnlyWhatIsMissing(t *testing.T) {
	c, out := testConsole("", false, false)
	if ensureDownloadTools(c, lookPathStub("tar")) {
		t.Fatal("a missing curl must fail the check")
	}
	lines := failLines(out.String())
	if len(lines) != 1 {
		t.Fatalf("expected one fail line, got %v", lines)
	}
	if strings.Contains(lines[0], "tar") {
		t.Errorf("tar is present, so it must not be reported as missing: %q", lines[0])
	}
	if !strings.Contains(lines[0], "curl") {
		t.Errorf("curl is absent and must be reported: %q", lines[0])
	}
}

func TestJoinTools(t *testing.T) {
	cases := map[string]string{
		"":          "",
		"curl":      "curl",
		"curl, tar": "curl and tar",
		"a, b, c":   "a, b and c",
	}
	for input, want := range cases {
		var names []string
		if input != "" {
			names = strings.Split(input, ", ")
		}
		if got := joinTools(names); got != want {
			t.Errorf("joinTools(%q) = %q, want %q", input, got, want)
		}
	}
}
