package nntp

import (
	"fmt"
	"net"
	"os"
	"strconv"
	"strings"
	"time"
)

const (
	defaultConnectTimeout = 15 * time.Second
	defaultSocketTimeout  = 120 * time.Second
)

type Config struct {
	Server         string
	Port           string
	Username       string
	Password       string
	SSL            bool
	ConnectTimeout time.Duration
	SocketTimeout  time.Duration
}

func ConfigFromEnv() Config {
	alternate := boolEnv("USE_ALTERNATE_NNTP_SERVER")
	suffix := ""
	if alternate {
		suffix = "_A"
	}

	ssl := boolEnv("NNTP_SSLENABLED" + suffix)
	port := os.Getenv("NNTP_PORT" + suffix)
	if port == "" {
		if ssl {
			port = "563"
		} else {
			port = "119"
		}
	}

	return Config{
		Server:         os.Getenv("NNTP_SERVER" + suffix),
		Port:           port,
		Username:       os.Getenv("NNTP_USERNAME" + suffix),
		Password:       os.Getenv("NNTP_PASSWORD" + suffix),
		SSL:            ssl,
		ConnectTimeout: durationEnv("NNTP_CONNECT_TIMEOUT"+suffix, defaultConnectTimeout),
		SocketTimeout:  durationEnv("NNTP_SOCKET_TIMEOUT"+suffix, defaultSocketTimeout),
	}
}

func (c Config) Validate() error {
	if strings.TrimSpace(c.Server) == "" {
		return fmt.Errorf("NNTP_SERVER is required for native NNTP probing")
	}
	if strings.TrimSpace(c.Port) == "" {
		return fmt.Errorf("NNTP_PORT is required for native NNTP probing")
	}
	if _, err := strconv.Atoi(c.Port); err != nil {
		return fmt.Errorf("NNTP_PORT must be numeric")
	}
	if c.ConnectTimeout <= 0 {
		return fmt.Errorf("NNTP_CONNECT_TIMEOUT must be positive")
	}
	if c.SocketTimeout <= 0 {
		return fmt.Errorf("NNTP_SOCKET_TIMEOUT must be positive")
	}

	return nil
}

func (c Config) Address() string {
	return net.JoinHostPort(c.Server, c.Port)
}

func boolEnv(key string) bool {
	switch strings.ToLower(strings.TrimSpace(os.Getenv(key))) {
	case "1", "true", "yes", "on":
		return true
	default:
		return false
	}
}

func durationEnv(key string, fallback time.Duration) time.Duration {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		return fallback
	}

	seconds, err := strconv.Atoi(value)
	if err != nil || seconds <= 0 {
		return fallback
	}

	return time.Duration(seconds) * time.Second
}
