// Package pipeline post-processes tailed lines before shipping:
//   - docker_json: unwraps Docker's json-file format ({"log":…,"time":…})
//   - multiline:   joins continuation lines (stack traces) into one entry
//
// Lines from sources without processing pass through untouched.
package pipeline

import (
	"encoding/json"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"github.com/Fabio-Kumahost/logwatch2/agent/internal/config"
	"github.com/Fabio-Kumahost/logwatch2/agent/internal/tail"
)

type rule struct {
	glob       string
	dockerJSON bool
	multiline  *regexp.Regexp
	maxLines   int
}

type buffer struct {
	first tail.Line
	parts []string
	since time.Time
}

type Pipeline struct {
	rules   []rule
	byPath  map[string]*rule // concrete path → matched rule (glob cache)
	buffers map[string]*buffer
	out     chan<- tail.Line
}

func New(sources []config.Source, out chan<- tail.Line) *Pipeline {
	p := &Pipeline{
		byPath:  map[string]*rule{},
		buffers: map[string]*buffer{},
		out:     out,
	}
	for _, s := range sources {
		if s.Type != "file" {
			continue // journald bypasses the pipeline
		}
		r := rule{glob: s.Path, dockerJSON: s.Format == "docker_json"}
		if s.Multiline.Enabled {
			r.multiline = regexp.MustCompile(s.Multiline.Pattern) // validated at config load
			r.maxLines = s.Multiline.MaxLines
		}
		p.rules = append(p.rules, r)
	}
	return p
}

// Run consumes raw lines until stop closes, flushing multiline buffers that
// have been quiet for a second (a stack trace is "done" when output pauses).
func (p *Pipeline) Run(in <-chan tail.Line, stop <-chan struct{}) {
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-stop:
			p.flushAll()
			return
		case line := <-in:
			p.process(line)
		case <-ticker.C:
			p.flushStale(time.Second)
		}
	}
}

func (p *Pipeline) process(line tail.Line) {
	r := p.ruleFor(line.Path)
	if r == nil {
		p.out <- line
		return
	}

	if r.dockerJSON {
		if text, ts, ok := ParseDockerJSON(line.Text); ok {
			line.Text = text
			if !ts.IsZero() {
				line.Time = ts
			}
		} // unparseable lines ship raw — never drop data silently
	}

	if r.multiline == nil {
		p.out <- line
		return
	}

	buf := p.buffers[line.Path]
	if buf != nil && r.multiline.MatchString(line.Text) && len(buf.parts) < r.maxLines {
		buf.parts = append(buf.parts, line.Text)
		buf.since = time.Now()
		return
	}
	if buf != nil {
		p.emit(buf)
	}
	p.buffers[line.Path] = &buffer{first: line, parts: []string{line.Text}, since: time.Now()}
}

func (p *Pipeline) emit(b *buffer) {
	line := b.first
	line.Text = strings.Join(b.parts, "\n")
	p.out <- line
}

func (p *Pipeline) flushStale(age time.Duration) {
	now := time.Now()
	for path, b := range p.buffers {
		if now.Sub(b.since) >= age {
			p.emit(b)
			delete(p.buffers, path)
		}
	}
}

func (p *Pipeline) flushAll() {
	for path, b := range p.buffers {
		p.emit(b)
		delete(p.buffers, path)
	}
}

func (p *Pipeline) ruleFor(path string) *rule {
	if r, seen := p.byPath[path]; seen {
		return r
	}
	var match *rule
	for i := range p.rules {
		if ok, _ := filepath.Match(p.rules[i].glob, path); ok || p.rules[i].glob == path {
			match = &p.rules[i]
			break
		}
	}
	p.byPath[path] = match // nil is cached too: pass-through
	return match
}

// ParseDockerJSON unwraps one line of Docker's json-file log driver.
func ParseDockerJSON(raw string) (text string, ts time.Time, ok bool) {
	var entry struct {
		Log    string `json:"log"`
		Stream string `json:"stream"`
		Time   string `json:"time"`
	}
	if err := json.Unmarshal([]byte(raw), &entry); err != nil || entry.Log == "" {
		return "", time.Time{}, false
	}
	if t, err := time.Parse(time.RFC3339Nano, entry.Time); err == nil {
		ts = t
	}
	return strings.TrimRight(entry.Log, "\r\n"), ts, true
}
