// Package config loads and validates the agent's YAML configuration.
package config

import (
	"fmt"
	"os"
	"regexp"
	"strings"
	"time"

	"gopkg.in/yaml.v3"
)

type Config struct {
	Panel struct {
		URL           string        `yaml:"url"`            // https://logs.example.com
		Token         string        `yaml:"token"`          // lw2_… ; or token_file
		TokenFile     string        `yaml:"token_file"`     // preferred: keeps token out of config
		AllowInsecure bool          `yaml:"allow_insecure"` // permit http:// (lab use only)
		TLSCAFile     string        `yaml:"tls_ca_file"`    // pin a self-signed CA
		Timeout       time.Duration `yaml:"timeout"`
	} `yaml:"panel"`

	Batch struct {
		MaxEntries    int           `yaml:"max_entries"` // ship when this many lines queued
		MaxWait       time.Duration `yaml:"max_wait"`    // … or this much time passed
		SpoolDir      string        `yaml:"spool_dir"`   // overflow to disk when panel is down
		SpoolMaxBytes int64         `yaml:"spool_max_bytes"`
	} `yaml:"batch"`

	HeartbeatInterval time.Duration `yaml:"heartbeat_interval"`
	StateFile         string        `yaml:"state_file"` // tail offsets survive restarts

	Sources []Source `yaml:"sources"`
}

type Source struct {
	Path    string `yaml:"path"`    // file path or glob (/var/log/nginx/*.log)
	Service string `yaml:"service"` // label; defaults to basename without extension
	Type    string `yaml:"type"`    // file (default) | journald
	Format  string `yaml:"format"`  // plain (default) | docker_json

	// journald only: restrict to specific units (empty = whole journal).
	Units []string `yaml:"units"`

	// Join continuation lines (stack traces) into one entry.
	Multiline struct {
		Enabled  bool   `yaml:"enabled"`
		Pattern  string `yaml:"pattern"`   // continuation regex, default: ^[ \t]
		MaxLines int    `yaml:"max_lines"` // default 50
	} `yaml:"multiline"`
}

func Load(path string) (*Config, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("read config: %w", err)
	}
	cfg := defaults()
	if err := yaml.Unmarshal(raw, cfg); err != nil {
		return nil, fmt.Errorf("parse config: %w", err)
	}
	if err := cfg.validate(); err != nil {
		return nil, err
	}
	return cfg, nil
}

func defaults() *Config {
	c := &Config{}
	c.Panel.Timeout = 30 * time.Second
	c.Batch.MaxEntries = 200
	c.Batch.MaxWait = 5 * time.Second
	c.Batch.SpoolDir = "/var/lib/logwatch-agent/spool"
	c.Batch.SpoolMaxBytes = 50 << 20 // 50 MiB
	c.HeartbeatInterval = 60 * time.Second
	c.StateFile = "/var/lib/logwatch-agent/state.json"
	return c
}

func (c *Config) validate() error {
	if c.Panel.URL == "" {
		return fmt.Errorf("panel.url is required")
	}
	if strings.HasPrefix(c.Panel.URL, "http://") && !c.Panel.AllowInsecure {
		return fmt.Errorf("panel.url uses plain http — set allow_insecure: true only for lab setups")
	}
	if c.Panel.TokenFile != "" {
		raw, err := os.ReadFile(c.Panel.TokenFile)
		if err != nil {
			return fmt.Errorf("read token_file: %w", err)
		}
		c.Panel.Token = strings.TrimSpace(string(raw))
	}
	if !strings.HasPrefix(c.Panel.Token, "lw2_") {
		return fmt.Errorf("panel.token missing or malformed (expected lw2_… prefix)")
	}
	if len(c.Sources) == 0 {
		return fmt.Errorf("at least one source is required")
	}
	for i := range c.Sources {
		s := &c.Sources[i]
		switch s.Type {
		case "", "file":
			s.Type = "file"
			if s.Path == "" {
				return fmt.Errorf("sources[%d].path is empty", i)
			}
		case "journald":
			if s.Service == "" {
				s.Service = "journald"
			}
		default:
			return fmt.Errorf("sources[%d].type must be file or journald", i)
		}
		switch s.Format {
		case "":
			s.Format = "plain"
		case "plain", "docker_json":
		default:
			return fmt.Errorf("sources[%d].format must be plain or docker_json", i)
		}
		if s.Multiline.Enabled {
			if s.Multiline.Pattern == "" {
				s.Multiline.Pattern = `^[ \t]`
			}
			if _, err := regexp.Compile(s.Multiline.Pattern); err != nil {
				return fmt.Errorf("sources[%d].multiline.pattern: %w", i, err)
			}
			if s.Multiline.MaxLines <= 0 {
				s.Multiline.MaxLines = 50
			}
		}
	}
	return nil
}
