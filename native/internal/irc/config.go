package irc

import (
	"context"
	"crypto/tls"
	"fmt"
	"net"
	"os"
	"strconv"
	"strings"
	"time"
)

const defaultDialTimeout = 15 * time.Second

type RuntimeConfig struct {
	Server        string
	Port          string
	TLS           bool
	Nickname      string
	Username      string
	RealName      string
	Password      string
	Channels      []Channel
	MaxLines      int
	MaxCandidates int
	DialTimeout   time.Duration
}

func RuntimeConfigFromEnv() RuntimeConfig {
	username := strings.TrimSpace(os.Getenv("SCRAPE_IRC_USERNAME"))
	port := strings.TrimSpace(os.Getenv("SCRAPE_IRC_PORT"))
	if port == "" {
		port = "6667"
	}

	return RuntimeConfig{
		Server:        envDefault("SCRAPE_IRC_SERVER", "irc.synirc.net"),
		Port:          port,
		TLS:           boolEnv("SCRAPE_IRC_TLS"),
		Nickname:      username,
		Username:      username,
		RealName:      username,
		Password:      os.Getenv("SCRAPE_IRC_PASSWORD"),
		Channels:      []Channel{{Name: envDefault("NNTMUX_NATIVE_WORKER_IRC_CHANNEL", "#PreNNTmux")}},
		MaxLines:      intEnv("NNTMUX_NATIVE_WORKER_IRC_MAX_LINES", 0),
		MaxCandidates: intEnv("NNTMUX_NATIVE_WORKER_IRC_MAX_CANDIDATES", 0),
		DialTimeout:   defaultDialTimeout,
	}
}

func (c RuntimeConfig) Validate() error {
	if strings.TrimSpace(c.Server) == "" {
		return fmt.Errorf("SCRAPE_IRC_SERVER is required")
	}
	if strings.TrimSpace(c.Port) == "" {
		return fmt.Errorf("SCRAPE_IRC_PORT is required")
	}
	if _, err := strconv.Atoi(c.Port); err != nil {
		return fmt.Errorf("SCRAPE_IRC_PORT must be numeric")
	}
	if strings.TrimSpace(c.Nickname) == "" {
		return fmt.Errorf("SCRAPE_IRC_USERNAME is required")
	}
	if c.MaxLines < 0 {
		return fmt.Errorf("NNTMUX_NATIVE_WORKER_IRC_MAX_LINES must be non-negative")
	}
	if c.MaxCandidates < 0 {
		return fmt.Errorf("NNTMUX_NATIVE_WORKER_IRC_MAX_CANDIDATES must be non-negative")
	}

	return validateSessionConfig(c.SessionConfig())
}

func (c RuntimeConfig) Address() string {
	return net.JoinHostPort(c.Server, c.Port)
}

func (c RuntimeConfig) SessionConfig() SessionConfig {
	return SessionConfig{
		Nickname:      c.Nickname,
		Username:      c.Username,
		RealName:      c.RealName,
		Password:      c.Password,
		Channels:      c.Channels,
		MaxLines:      c.MaxLines,
		MaxCandidates: c.MaxCandidates,
	}
}

func DialRuntime(ctx context.Context, cfg RuntimeConfig) (net.Conn, error) {
	if err := cfg.Validate(); err != nil {
		return nil, err
	}
	timeout := cfg.DialTimeout
	if timeout <= 0 {
		timeout = defaultDialTimeout
	}
	dialer := net.Dialer{Timeout: timeout}
	conn, err := dialer.DialContext(ctx, "tcp", cfg.Address())
	if err != nil {
		return nil, fmt.Errorf("connect irc server: %w", err)
	}
	if cfg.TLS {
		tlsConn := tls.Client(conn, &tls.Config{ServerName: cfg.Server})
		if err := tlsConn.Handshake(); err != nil {
			_ = conn.Close()
			return nil, fmt.Errorf("irc tls handshake: %w", err)
		}

		return tlsConn, nil
	}

	return conn, nil
}

func envDefault(key string, fallback string) string {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		return fallback
	}

	return value
}

func boolEnv(key string) bool {
	switch strings.ToLower(strings.TrimSpace(os.Getenv(key))) {
	case "1", "true", "yes", "on":
		return true
	default:
		return false
	}
}

func intEnv(key string, fallback int) int {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		return fallback
	}
	integer, err := strconv.Atoi(value)
	if err != nil {
		return fallback
	}

	return integer
}
