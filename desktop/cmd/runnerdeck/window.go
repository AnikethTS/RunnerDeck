package main

import "html"

type appWindow interface {
	Run()
	Show()
	Close()
	Navigate(url string)
	ShowStatus(text string)
}

func splashHTML(text string) string {
	return `<!doctype html><meta charset="utf-8"><title>RunnerDeck</title>` +
		`<body style="margin:0;height:100vh;display:grid;place-items:center;background:#0f1419;color:#e6edf3;font:16px Segoe UI,sans-serif">` +
		`<div style="text-align:center"><div style="font-size:28px;font-weight:600;margin-bottom:12px">RunnerDeck</div>` +
		`<div style="opacity:.7">` + html.EscapeString(text) + `</div></div></body>`
}
