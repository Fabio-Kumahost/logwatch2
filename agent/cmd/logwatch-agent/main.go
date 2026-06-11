// logwatch-agent — ships log files and the systemd journal to a Logwatch2 panel.
//
//	logwatch-agent --config /etc/logwatch2/agent.yaml
package main

import (
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"sync"
	"syscall"
	"time"

	"github.com/Fabio-Kumahost/logwatch2/agent/internal/config"
	"github.com/Fabio-Kumahost/logwatch2/agent/internal/journald"
	"github.com/Fabio-Kumahost/logwatch2/agent/internal/pipeline"
	"github.com/Fabio-Kumahost/logwatch2/agent/internal/shipper"
	"github.com/Fabio-Kumahost/logwatch2/agent/internal/tail"
)

func main() {
	configPath := flag.String("config", "/etc/logwatch2/agent.yaml", "path to agent config")
	checkOnly := flag.Bool("check", false, "validate config and exit")
	showVersion := flag.Bool("version", false, "print version and exit")
	flag.Parse()

	if *showVersion {
		fmt.Println("logwatch-agent", shipper.Version)
		return
	}

	log := slog.New(slog.NewTextHandler(os.Stderr, nil))

	cfg, err := config.Load(*configPath)
	if err != nil {
		log.Error("config error", "err", err)
		os.Exit(1)
	}
	if *checkOnly {
		fmt.Println("config ok:", len(cfg.Sources), "source(s)")
		return
	}

	// tailers → raw → pipeline (docker_json unwrap, multiline join) → ship
	// journald readers emit pre-parsed lines and skip the pipeline.
	raw := make(chan tail.Line, 4096)
	ship := make(chan tail.Line, 4096)
	tailer := tail.New(cfg.StateFile, raw)
	pipe := pipeline.New(cfg.Sources, ship)

	sh, err := shipper.New(cfg, ship, log)
	if err != nil {
		log.Error("shipper init failed", "err", err)
		os.Exit(1)
	}

	stop := make(chan struct{})
	var wg sync.WaitGroup
	files, journals := 0, 0

	for _, src := range cfg.Sources {
		wg.Add(1)
		if src.Type == "journald" {
			journals++
			go func(s config.Source) {
				defer wg.Done()
				journald.Watch(s, ship, stop, log)
			}(src)
			continue
		}
		files++
		go func(s config.Source) {
			defer wg.Done()
			tailer.Watch(s.Path, s.Service, stop)
		}(src)
	}

	wg.Add(3)
	go func() { defer wg.Done(); pipe.Run(raw, stop) }()
	go func() { defer wg.Done(); sh.Run(stop) }()
	go func() {
		defer wg.Done()
		sh.Heartbeat(stop, func() int { return len(cfg.Sources) })
	}()

	// Persist tail offsets every 10s so a crash replays at most 10s of lines.
	wg.Add(1)
	go func() {
		defer wg.Done()
		t := time.NewTicker(10 * time.Second)
		defer t.Stop()
		for {
			select {
			case <-stop:
				return
			case <-t.C:
				if err := tailer.SaveState(); err != nil {
					log.Warn("state save failed", "err", err)
				}
			}
		}
	}()

	log.Info("logwatch-agent started", "version", shipper.Version,
		"file_sources", files, "journald_sources", journals, "panel", cfg.Panel.URL)

	sig := make(chan os.Signal, 1)
	signal.Notify(sig, syscall.SIGINT, syscall.SIGTERM)
	<-sig

	log.Info("shutting down, flushing buffers")
	close(stop)
	wg.Wait()
	if err := tailer.SaveState(); err != nil {
		log.Warn("final state save failed", "err", err)
	}
}
