// Package tail follows log files with rotation detection.
//
// Polling (1s) instead of inotify keeps the agent dependency-free and works
// on every filesystem (NFS, overlayfs in containers). At a 1s interval the
// CPU cost is negligible — the design goal is "boring and correct".
package tail

import (
	"bufio"
	"encoding/json"
	"os"
	"path/filepath"
	"sync"
	"time"
)

type Line struct {
	Path    string
	Service string
	Text    string
	Time    time.Time
	Level   string // optional hint (journald priority); panel re-classifies anyway
}

type fileState struct {
	Offset int64  `json:"offset"`
	Inode  uint64 `json:"inode"`
}

type Tailer struct {
	mu        sync.Mutex
	state     map[string]fileState // path → position
	stateFile string
	out       chan<- Line
}

func New(stateFile string, out chan<- Line) *Tailer {
	t := &Tailer{state: map[string]fileState{}, stateFile: stateFile, out: out}
	t.loadState()
	return t
}

// Watch polls one source (path may be a glob) until stop is closed.
func (t *Tailer) Watch(pathGlob, service string, stop <-chan struct{}) {
	ticker := time.NewTicker(1 * time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-stop:
			return
		case <-ticker.C:
			matches, err := filepath.Glob(pathGlob)
			if err != nil {
				continue // invalid glob already rejected at config validation
			}
			for _, path := range matches {
				t.readNew(path, service)
			}
		}
	}
}

// readNew emits lines appended since the stored offset, handling truncation
// and rotation (inode change ⇒ start the new file from the beginning).
func (t *Tailer) readNew(path, service string) {
	f, err := os.Open(path)
	if err != nil {
		return // unreadable now (rotation in progress, permissions) — retry next tick
	}
	defer f.Close()

	info, err := f.Stat()
	if err != nil {
		return
	}
	inode := inodeOf(info)

	t.mu.Lock()
	st, known := t.state[path]
	switch {
	case !known:
		// First sight: start at EOF — never replay a server's history on install.
		st = fileState{Offset: info.Size(), Inode: inode}
	case st.Inode != inode || info.Size() < st.Offset:
		// Rotated or truncated: new content starts at 0.
		st = fileState{Offset: 0, Inode: inode}
	}
	t.mu.Unlock()

	if _, err := f.Seek(st.Offset, 0); err != nil {
		return
	}
	r := bufio.NewReaderSize(f, 64<<10)
	read := st.Offset
	now := time.Now().UTC()
	for {
		line, err := r.ReadString('\n')
		if err != nil {
			break // partial line stays unread until its newline arrives
		}
		read += int64(len(line))
		if txt := trimLine(line); txt != "" {
			t.out <- Line{Path: path, Service: service, Text: txt, Time: now}
		}
	}

	t.mu.Lock()
	t.state[path] = fileState{Offset: read, Inode: inode}
	t.mu.Unlock()
}

func trimLine(s string) string {
	for len(s) > 0 && (s[len(s)-1] == '\n' || s[len(s)-1] == '\r') {
		s = s[:len(s)-1]
	}
	if len(s) > 32<<10 { // defensive cap; panel rejects oversized messages anyway
		s = s[:32<<10]
	}
	return s
}

// SaveState persists offsets; called periodically and on shutdown.
func (t *Tailer) SaveState() error {
	t.mu.Lock()
	raw, err := json.Marshal(t.state)
	t.mu.Unlock()
	if err != nil {
		return err
	}
	tmp := t.stateFile + ".tmp"
	if err := os.WriteFile(tmp, raw, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, t.stateFile) // atomic on POSIX
}

func (t *Tailer) loadState() {
	raw, err := os.ReadFile(t.stateFile)
	if err != nil {
		return // fresh start
	}
	_ = json.Unmarshal(raw, &t.state)
}
