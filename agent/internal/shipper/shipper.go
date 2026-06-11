// Package shipper batches log lines and delivers them to the panel over
// HTTPS with retry/backoff. Never blocks the tailers: when the panel is
// unreachable the queue overflows to a disk spool, oldest data dropped last.
package shipper

import (
	"bytes"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"fmt"
	"log/slog"
	"math/rand"
	"net/http"
	"os"
	"time"

	"github.com/Fabio-Kumahost/logwatch2/agent/internal/config"
	"github.com/Fabio-Kumahost/logwatch2/agent/internal/tail"
)

type entry struct {
	TS         string `json:"ts"`
	SourceFile string `json:"source_file"`
	Service    string `json:"service"`
	Level      string `json:"level,omitempty"` // hint only; panel re-classifies
	Message    string `json:"message"`
	Raw        string `json:"raw"`
}

type Shipper struct {
	cfg    *config.Config
	client *http.Client
	in     <-chan tail.Line
	spool  *spool
	log    *slog.Logger
}

func New(cfg *config.Config, in <-chan tail.Line, log *slog.Logger) (*Shipper, error) {
	transport := &http.Transport{TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS12}}
	if cfg.Panel.TLSCAFile != "" {
		pem, err := os.ReadFile(cfg.Panel.TLSCAFile)
		if err != nil {
			return nil, fmt.Errorf("read tls_ca_file: %w", err)
		}
		pool := x509.NewCertPool()
		if !pool.AppendCertsFromPEM(pem) {
			return nil, fmt.Errorf("tls_ca_file contains no valid certificates")
		}
		transport.TLSClientConfig.RootCAs = pool
	}
	return &Shipper{
		cfg:    cfg,
		client: &http.Client{Timeout: cfg.Panel.Timeout, Transport: transport},
		in:     in,
		spool:  newSpool(cfg.Batch.SpoolDir, cfg.Batch.SpoolMaxBytes, log),
		log:    log,
	}, nil
}

// Run collects lines into batches (size- or time-triggered) and ships them.
func (s *Shipper) Run(stop <-chan struct{}) {
	batch := make([]entry, 0, s.cfg.Batch.MaxEntries)
	timer := time.NewTimer(s.cfg.Batch.MaxWait)
	defer timer.Stop()

	flush := func() {
		if len(batch) == 0 {
			return
		}
		if err := s.shipWithRetry(batch, stop); err != nil {
			s.log.Warn("panel unreachable, spooling batch", "entries", len(batch), "err", err)
			s.spool.write(batch)
		}
		batch = batch[:0]
	}

	for {
		select {
		case <-stop:
			flush()
			return
		case line := <-s.in:
			batch = append(batch, entry{
				TS:         line.Time.Format(time.RFC3339),
				SourceFile: line.Path,
				Service:    line.Service,
				Level:      line.Level,
				Message:    line.Text,
				Raw:        line.Text,
			})
			if len(batch) >= s.cfg.Batch.MaxEntries {
				flush()
				timer.Reset(s.cfg.Batch.MaxWait)
			}
		case <-timer.C:
			flush()
			s.spool.drain(func(b []entry) error { return s.shipOnce(b) }) // recover after outages
			timer.Reset(s.cfg.Batch.MaxWait)
		}
	}
}

// shipWithRetry: exponential backoff with jitter, capped at 60s, max ~5 min
// of attempts per batch before handing off to the spool.
func (s *Shipper) shipWithRetry(batch []entry, stop <-chan struct{}) error {
	backoff := time.Second
	deadline := time.Now().Add(5 * time.Minute)
	for {
		err := s.shipOnce(batch)
		if err == nil {
			return nil
		}
		if time.Now().After(deadline) {
			return err
		}
		sleep := backoff + time.Duration(rand.Int63n(int64(backoff/2)))
		s.log.Debug("ship failed, retrying", "in", sleep, "err", err)
		select {
		case <-stop:
			return err
		case <-time.After(sleep):
		}
		if backoff < 60*time.Second {
			backoff *= 2
		}
	}
}

func (s *Shipper) shipOnce(batch []entry) error {
	body, err := json.Marshal(map[string]any{"agent_version": Version, "entries": batch})
	if err != nil {
		return err
	}
	req, err := http.NewRequest(http.MethodPost, s.cfg.Panel.URL+"/api/v1/ingest/logs", bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+s.cfg.Panel.Token)
	req.Header.Set("Content-Type", "application/json")

	resp, err := s.client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	switch {
	case resp.StatusCode == http.StatusAccepted:
		return nil
	case resp.StatusCode == http.StatusTooManyRequests:
		retry := parseRetryAfter(resp.Header.Get("Retry-After"), 10*time.Second)
		time.Sleep(retry) // honor the panel's back-pressure before reporting failure
		return fmt.Errorf("rate limited")
	case resp.StatusCode == http.StatusUnauthorized:
		// Fatal: a bad token never heals by retrying. Log loudly; systemd keeps
		// us alive so the operator sees it in `systemctl status`.
		s.log.Error("panel rejected token (401) — rotate/fix the token in /etc/logwatch2")
		return fmt.Errorf("unauthorized")
	default:
		return fmt.Errorf("panel returned %s", resp.Status)
	}
}

// Heartbeat posts liveness every interval; the panel marks the server
// offline when heartbeats stop.
func (s *Shipper) Heartbeat(stop <-chan struct{}, watchedFiles func() int) {
	hostname, _ := os.Hostname()
	ticker := time.NewTicker(s.cfg.HeartbeatInterval)
	defer ticker.Stop()
	for {
		select {
		case <-stop:
			return
		case <-ticker.C:
			body, _ := json.Marshal(map[string]any{
				"agent_version": Version,
				"hostname":      hostname,
				"watched_files": watchedFiles(),
			})
			req, err := http.NewRequest(http.MethodPost,
				s.cfg.Panel.URL+"/api/v1/agent/heartbeat", bytes.NewReader(body))
			if err != nil {
				continue
			}
			req.Header.Set("Authorization", "Bearer "+s.cfg.Panel.Token)
			req.Header.Set("Content-Type", "application/json")
			if resp, err := s.client.Do(req); err == nil {
				resp.Body.Close()
			}
		}
	}
}

func parseRetryAfter(h string, fallback time.Duration) time.Duration {
	if h == "" {
		return fallback
	}
	if d, err := time.ParseDuration(h + "s"); err == nil && d > 0 && d < 5*time.Minute {
		return d
	}
	return fallback
}

// Version is injected at build time: -ldflags "-X .../shipper.Version=v0.1.0"
var Version = "dev"
