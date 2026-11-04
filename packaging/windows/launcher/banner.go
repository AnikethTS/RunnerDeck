package main

import "strings"

const repoURL = "https://github.com/AnikethTS/RunnerDeck"

var logoLines = []string{
	"          ######",
	"      ##############",
	"  ########      ###### @",
	"#######     @@@    # @@@@@",
	"####    @@@@@@@     @@@@ #",
	"####    @@@@@@    @@@@  ##",
	"####  @@@@@  @  @@@@@ ####",
	"###@@@@@@ @@@ @@@@@   ####",
	"#@@@@@@   @@@@@@@     ####",
	"@@@@@#      @@@     ######",
	"  @#######      ########",
	"      ##############",
	"          ######",
}

func bannerText(version string) string {
	beside := map[int]string{
		3:  "Welcome to RunnerDeck v" + version,
		4:  "One dashboard for every self-hosted",
		5:  "GitHub Actions runner.",
		7:  "Built by Aniketh TS and contributors",
		8:  "License  MIT",
		10: "Docs and issues",
		11: repoURL,
	}
	var b strings.Builder
	b.WriteString("\n")
	for i, line := range logoLines {
		b.WriteString(strings.TrimRight("  "+padRight(line, 28)+beside[i], " "))
		b.WriteString("\n")
	}
	b.WriteString("\n")
	return b.String()
}

func padRight(s string, width int) string {
	if len(s) >= width {
		return s
	}
	return s + strings.Repeat(" ", width-len(s))
}
