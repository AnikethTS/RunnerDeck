package main

import (
	"bufio"
	"bytes"
	"os"
	"strings"
	"testing"
)

func testConsole(input string, interactive, assumeYes bool) (*console, *bytes.Buffer) {
	var out bytes.Buffer
	return &console{
		in:          bufio.NewReader(strings.NewReader(input)),
		out:         &out,
		interactive: interactive,
		assumeYes:   assumeYes,
	}, &out
}

func TestParseYesNo(t *testing.T) {
	cases := map[string]bool{"y": true, "YES\n": true, "n": false, "No\r\n": false, "": true, "maybe": true}
	for input, want := range cases {
		if got := parseYesNo(input, true); got != want {
			t.Errorf("parseYesNo(%q, true) = %v, want %v", input, got, want)
		}
	}
	if parseYesNo("", false) {
		t.Error("an empty answer should follow the default of no")
	}
}

func TestConfirmReadsTheAnswer(t *testing.T) {
	c, out := testConsole("n\n", true, false)
	if c.confirm("Install it now?", true) {
		t.Fatal("answered no but got yes")
	}
	if !strings.Contains(out.String(), "Install it now? [Y/n]") {
		t.Fatalf("prompt not shown: %q", out.String())
	}
	c, _ = testConsole("\n", true, false)
	if !c.confirm("Go on?", true) {
		t.Fatal("an empty answer should take the default")
	}
}

func TestConfirmTreatsEndOfInputAsNo(t *testing.T) {
	c, _ := testConsole("", true, false)
	if c.confirm("Sign in now?", true) {
		t.Fatal("end of input must never count as agreement")
	}
}

func TestConfirmNeverBlocksWhenNotInteractive(t *testing.T) {
	c, out := testConsole("y\n", false, false)
	if c.confirm("Install it now?", true) {
		t.Fatal("a non-interactive console must not agree on its own")
	}
	if out.Len() != 0 {
		t.Fatalf("nothing should be printed: %q", out.String())
	}
}

func TestConfirmAssumesYesWithTheFlag(t *testing.T) {
	c, out := testConsole("", false, true)
	if !c.confirm("Install it now?", false) {
		t.Fatal("--yes should accept")
	}
	if !strings.Contains(out.String(), "yes (--yes)") {
		t.Fatalf("the automatic answer should be visible: %q", out.String())
	}
}

func TestStepAndStatusLinesAreAscii(t *testing.T) {
	c, out := testConsole("", false, false)
	c.step(2, 4, "Checking PHP")
	c.ok("fine")
	c.warn("careful")
	c.fail("broken")
	c.info("detail")
	for _, r := range out.String() {
		if r > 126 {
			t.Fatalf("non-ASCII character %q in console output", r)
		}
	}
	if !strings.Contains(out.String(), "[2/4] Checking PHP") || !strings.Contains(out.String(), "[FAIL] broken") {
		t.Fatalf("unexpected output: %q", out.String())
	}
}

func TestBannerShowsVersionCreditsAndLogoWithoutTrailingSpaces(t *testing.T) {
	text := bannerText("1.2.3")
	for _, want := range []string{"Welcome to RunnerDeck v1.2.3", "Built by Aniketh TS and contributors", "@@@", repoURL} {
		if !strings.Contains(text, want) {
			t.Errorf("banner is missing %q", want)
		}
	}
	for _, line := range strings.Split(text, "\n") {
		if line != strings.TrimRight(line, " ") {
			t.Errorf("trailing spaces on %q", line)
		}
		for _, r := range line {
			if r > 126 {
				t.Fatalf("non-ASCII character %q in the banner", r)
			}
		}
	}
}

func TestMissingRuntimeIsRecognisedWhateverTheSignOfTheCode(t *testing.T) {
	if !isMissingRuntime(-1073741515) || !isMissingRuntime(3221225781) {
		t.Fatal("0xC0000135 must be recognised as a missing DLL")
	}
	for _, code := range []int{0, 1, 255, -1, 0xC0000005} {
		if isMissingRuntime(code) {
			t.Fatalf("%d is not a missing DLL", code)
		}
	}
}

func TestFirstLine(t *testing.T) {
	if got := firstLine("PHP 8.5.12 (cli)\nCopyright (c) The PHP Group\n"); got != "PHP 8.5.12 (cli)" {
		t.Fatalf("got %q", got)
	}
}

func TestPSQuoteEscapesSingleQuotes(t *testing.T) {
	if got := psQuote(`C:\Users\O'Brien\x.exe`); got != `'C:\Users\O''Brien\x.exe'` {
		t.Fatalf("got %s", got)
	}
}

func TestPrependPathKeepsTheRestOfTheEnvironment(t *testing.T) {
	sep := string(os.PathListSeparator)
	out := prependPath([]string{"A=1", "Path=old"}, "new")
	if out[1] != "Path=new"+sep+"old" || out[0] != "A=1" {
		t.Fatalf("unexpected: %v", out)
	}
	added := prependPath([]string{"A=1"}, "new")
	if added[len(added)-1] != "PATH=new" {
		t.Fatalf("PATH not added: %v", added)
	}
}

func TestParseOptionsAcceptsYes(t *testing.T) {
	opts, err := parseOptions([]string{"--yes"}, env(nil))
	if err != nil || !opts.assumeYes {
		t.Fatalf("--yes not accepted: %+v %v", opts, err)
	}
}
