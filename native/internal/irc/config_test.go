package irc

import "testing"

func TestRuntimeConfigFromEnvUsesScrapeIRCCredentialsAndNativeBounds(t *testing.T) {
	t.Setenv("SCRAPE_IRC_SERVER", "irc.example.test")
	t.Setenv("SCRAPE_IRC_PORT", "6697")
	t.Setenv("SCRAPE_IRC_TLS", "true")
	t.Setenv("SCRAPE_IRC_USERNAME", "nntmuxbot")
	t.Setenv("SCRAPE_IRC_PASSWORD", "secret")
	t.Setenv("NNTMUX_NATIVE_WORKER_IRC_CHANNEL", "#PreNNTmux")
	t.Setenv("NNTMUX_NATIVE_WORKER_IRC_MAX_LINES", "25")
	t.Setenv("NNTMUX_NATIVE_WORKER_IRC_MAX_CANDIDATES", "2")

	cfg := RuntimeConfigFromEnv()
	if cfg.Server != "irc.example.test" || cfg.Port != "6697" || !cfg.TLS {
		t.Fatalf("server config = %#v", cfg)
	}
	if cfg.Nickname != "nntmuxbot" || cfg.Username != "nntmuxbot" || cfg.RealName != "nntmuxbot" || cfg.Password != "secret" {
		t.Fatalf("identity config = %#v", cfg)
	}
	if len(cfg.Channels) != 1 || cfg.Channels[0].Name != "#PreNNTmux" {
		t.Fatalf("channels = %#v", cfg.Channels)
	}
	if cfg.MaxLines != 25 || cfg.MaxCandidates != 2 {
		t.Fatalf("bounds = lines:%d candidates:%d", cfg.MaxLines, cfg.MaxCandidates)
	}
	if err := cfg.Validate(); err != nil {
		t.Fatalf("Validate: %v", err)
	}
}

func TestRuntimeConfigValidateRequiresUsernameAndNumericPort(t *testing.T) {
	cfg := RuntimeConfig{
		Server:   "irc.example.test",
		Port:     "not-a-port",
		Nickname: "nntmuxbot",
		Username: "nntmuxbot",
		RealName: "nntmuxbot",
		Channels: []Channel{{Name: "#PreNNTmux"}},
	}
	if err := cfg.Validate(); err == nil || err.Error() != "SCRAPE_IRC_PORT must be numeric" {
		t.Fatalf("Validate port error = %v", err)
	}

	cfg.Port = "6667"
	cfg.Nickname = ""
	cfg.Username = ""
	cfg.RealName = ""
	if err := cfg.Validate(); err == nil || err.Error() != "SCRAPE_IRC_USERNAME is required" {
		t.Fatalf("Validate username error = %v", err)
	}
}
