// Package journald streams the systemd journal by running
// `journalctl -f --output=json` as a subprocess — no cgo, no libsystemd
// linkage, works on every distro that has systemd.
package journald

import (
	"bufio"
	"encoding/json"
	"log/slog"
	"os/exec"
	"strconv"
	"time"

	"github.com/Fabio-Kumahost/logwatch2/agent/internal/config"
	"github.com/Fabio-Kumahost/logwatch2/agent/internal/tail"
)

// syslog priority (0-7) → panel log level.
var priorityLevel = map[string]string{
	"0": "critical", "1": "critical", "2": "critical",
	"3": "error", "4": "warning", "5": "notice", "6": "info", "7": "debug",
}

// Watch streams journal entries into out until stop closes, restarting the
// subprocess with backoff if it dies (journal rotation, OOM, upgrades).
func Watch(src config.Source, out chan<- tail.Line, stop <-chan struct{}, log *slog.Logger) {
	backoff := time.Second
	for {
		select {
		case <-stop:
			return
		default:
		}

		if err := run(src, out, stop); err != nil {
			log.Warn("journald reader exited, restarting", "err", err, "in", backoff)
		}
		select {
		case <-stop:
			return
		case <-time.After(backoff):
		}
		if backoff < 30*time.Second {
			backoff *= 2
		}
	}
}

func run(src config.Source, out chan<- tail.Line, stop <-chan struct{}) error {
	args := []string{"-f", "--output=json", "--since", "now", "--no-pager"}
	for _, unit := range src.Units {
		args = append(args, "-u", unit)
	}
	cmd := exec.Command("journalctl", args...)
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		return err
	}
	if err := cmd.Start(); err != nil {
		return err
	}

	done := make(chan struct{})
	go func() { // kill the subprocess when the agent shuts down
		select {
		case <-stop:
			_ = cmd.Process.Kill()
		case <-done:
		}
	}()
	defer close(done)

	scanner := bufio.NewScanner(stdout)
	scanner.Buffer(make([]byte, 0, 64<<10), 1<<20)
	for scanner.Scan() {
		if line, ok := parse(scanner.Bytes(), src); ok {
			select {
			case out <- line:
			case <-stop:
				_ = cmd.Process.Kill()
				return cmd.Wait()
			}
		}
	}
	return cmd.Wait()
}

func parse(raw []byte, src config.Source) (tail.Line, bool) {
	// MESSAGE is usually a string but can be a byte array for binary data —
	// json.RawMessage lets us skip those instead of failing the whole line.
	var entry struct {
		Message   json.RawMessage `json:"MESSAGE"`
		Priority  string          `json:"PRIORITY"`
		Unit      string          `json:"_SYSTEMD_UNIT"`
		Ident     string          `json:"SYSLOG_IDENTIFIER"`
		Timestamp string          `json:"__REALTIME_TIMESTAMP"` // microseconds
	}
	if json.Unmarshal(raw, &entry) != nil {
		return tail.Line{}, false
	}
	var message string
	if json.Unmarshal(entry.Message, &message) != nil || message == "" {
		return tail.Line{}, false // binary or empty payload — skip
	}

	service := src.Service
	if entry.Unit != "" {
		service = entry.Unit
	} else if entry.Ident != "" {
		service = entry.Ident
	}

	ts := time.Now().UTC()
	if usec, err := strconv.ParseInt(entry.Timestamp, 10, 64); err == nil {
		ts = time.UnixMicro(usec).UTC()
	}

	return tail.Line{
		Path:    "journald",
		Service: service,
		Text:    message,
		Time:    ts,
		Level:   priorityLevel[entry.Priority],
	}, true
}
