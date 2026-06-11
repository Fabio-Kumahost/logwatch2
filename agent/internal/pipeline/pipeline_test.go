package pipeline

import (
	"testing"
	"time"

	"github.com/USERNAME/logwatch2/agent/internal/config"
	"github.com/USERNAME/logwatch2/agent/internal/tail"
)

func fileSource(path, format string, multiline bool) config.Source {
	var s config.Source
	s.Path = path
	s.Service = "test"
	s.Type = "file"
	s.Format = format
	if multiline {
		s.Multiline.Enabled = true
		s.Multiline.Pattern = `^[ \t]`
		s.Multiline.MaxLines = 50
	}
	return s
}

func TestParseDockerJSON(t *testing.T) {
	text, ts, ok := ParseDockerJSON(
		`{"log":"connection refused\n","stream":"stderr","time":"2026-06-11T07:32:01.123456789Z"}`)
	if !ok {
		t.Fatal("expected ok")
	}
	if text != "connection refused" {
		t.Fatalf("text = %q", text)
	}
	if ts.Year() != 2026 || ts.Nanosecond() != 123456789 {
		t.Fatalf("time not parsed: %v", ts)
	}

	if _, _, ok := ParseDockerJSON("plain non-json line"); ok {
		t.Fatal("plain text must not parse as docker json")
	}
}

func TestDockerJSONPassthroughOnGarbage(t *testing.T) {
	out := make(chan tail.Line, 10)
	p := New([]config.Source{fileSource("/var/lib/docker/containers/*/*-json.log", "docker_json", false)}, out)

	p.process(tail.Line{Path: "/var/lib/docker/containers/abc/abc-json.log", Text: "not json at all"})
	got := <-out
	if got.Text != "not json at all" {
		t.Fatalf("garbage should ship raw, got %q", got.Text)
	}
}

func TestMultilineJoinsStackTraces(t *testing.T) {
	out := make(chan tail.Line, 10)
	p := New([]config.Source{fileSource("/app/app.log", "plain", true)}, out)

	p.process(tail.Line{Path: "/app/app.log", Text: "ERROR: boom"})
	p.process(tail.Line{Path: "/app/app.log", Text: "  at main.go:42"})
	p.process(tail.Line{Path: "/app/app.log", Text: "\tat lib.go:7"})
	p.process(tail.Line{Path: "/app/app.log", Text: "INFO: next event"}) // flushes previous

	got := <-out
	want := "ERROR: boom\n  at main.go:42\n\tat lib.go:7"
	if got.Text != want {
		t.Fatalf("joined = %q, want %q", got.Text, want)
	}

	p.flushAll()
	got = <-out
	if got.Text != "INFO: next event" {
		t.Fatalf("second entry = %q", got.Text)
	}
}

func TestMultilineFlushesAfterQuietPeriod(t *testing.T) {
	out := make(chan tail.Line, 10)
	p := New([]config.Source{fileSource("/app/app.log", "plain", true)}, out)

	p.process(tail.Line{Path: "/app/app.log", Text: "ERROR: tail of file"})
	p.flushStale(0) // simulate the 1s ticker firing after quiet time

	select {
	case got := <-out:
		if got.Text != "ERROR: tail of file" {
			t.Fatalf("got %q", got.Text)
		}
	case <-time.After(time.Second):
		t.Fatal("stale buffer was not flushed")
	}
}

func TestUnmatchedPathsPassThrough(t *testing.T) {
	out := make(chan tail.Line, 10)
	p := New([]config.Source{fileSource("/var/log/nginx/*.log", "plain", true)}, out)

	p.process(tail.Line{Path: "/var/log/syslog", Text: "hello"})
	if got := <-out; got.Text != "hello" {
		t.Fatalf("passthrough broken: %q", got.Text)
	}
}
