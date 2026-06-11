package shipper

import (
	"encoding/json"
	"log/slog"
	"os"
	"path/filepath"
	"sort"
	"time"
)

// spool persists batches the panel couldn't accept, one JSON file per batch,
// bounded by maxBytes (oldest files evicted first). Drained opportunistically
// once shipping succeeds again — no log line is lost across panel restarts.
type spool struct {
	dir      string
	maxBytes int64
	log      *slog.Logger
}

func newSpool(dir string, maxBytes int64, log *slog.Logger) *spool {
	_ = os.MkdirAll(dir, 0o700)
	return &spool{dir: dir, maxBytes: maxBytes, log: log}
}

func (s *spool) write(batch []entry) {
	raw, err := json.Marshal(batch)
	if err != nil {
		return
	}
	s.evictFor(int64(len(raw)))
	name := filepath.Join(s.dir, time.Now().UTC().Format("20060102T150405.000000000")+".json")
	if err := os.WriteFile(name, raw, 0o600); err != nil {
		s.log.Error("spool write failed — batch dropped", "err", err)
	}
}

// drain ships spooled batches oldest-first; stops at the first failure so
// ordering is preserved and we don't hammer a recovering panel.
func (s *spool) drain(ship func([]entry) error) {
	for _, f := range s.files() {
		raw, err := os.ReadFile(f)
		if err != nil {
			_ = os.Remove(f)
			continue
		}
		var batch []entry
		if json.Unmarshal(raw, &batch) != nil || len(batch) == 0 {
			_ = os.Remove(f)
			continue
		}
		if ship(batch) != nil {
			return
		}
		_ = os.Remove(f)
		s.log.Info("spooled batch delivered", "entries", len(batch))
	}
}

func (s *spool) evictFor(incoming int64) {
	files := s.files()
	var total int64
	sizes := make(map[string]int64, len(files))
	for _, f := range files {
		if info, err := os.Stat(f); err == nil {
			sizes[f] = info.Size()
			total += info.Size()
		}
	}
	for _, f := range files { // files() is oldest-first
		if total+incoming <= s.maxBytes {
			return
		}
		total -= sizes[f]
		_ = os.Remove(f)
		s.log.Warn("spool full — dropped oldest batch", "file", filepath.Base(f))
	}
}

func (s *spool) files() []string {
	matches, _ := filepath.Glob(filepath.Join(s.dir, "*.json"))
	sort.Strings(matches) // timestamp names ⇒ lexical order == chronological
	return matches
}
