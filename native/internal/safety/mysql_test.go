package safety

import "testing"

func TestAllowsProductionCommitOnlyForExplicitRemoveCrapOptIn(t *testing.T) {
	t.Setenv(AllowProductionCommitEnv, "")
	if AllowsProductionCommit("removecrap") {
		t.Fatal("removecrap production commit should require explicit opt-in")
	}

	t.Setenv(AllowProductionCommitEnv, "removecrap")
	if !AllowsProductionCommit("removecrap") {
		t.Fatal("removecrap production commit should be allowed with explicit opt-in")
	}
	if AllowsProductionCommit("binaries") {
		t.Fatal("production commit opt-in must not apply to other lanes")
	}

	t.Setenv(AllowProductionCommitEnv, "all")
	if AllowsProductionCommit("removecrap") {
		t.Fatal("wildcard production commit opt-in must not be accepted")
	}
}

func TestIsAllowedNativeFixtureDatabaseIncludesExplicitEvalDatabaseOnlyWhenAllowed(t *testing.T) {
	t.Parallel()

	if !IsAllowedNativeFixtureDatabase("nntmux_native_test", false) {
		t.Fatal("nntmux_native_test should remain allowed for fixtures")
	}
	if !IsAllowedNativeFixtureDatabase("worker_native_test", false) {
		t.Fatal("*_native_test should remain allowed for fixtures")
	}
	if IsAllowedNativeFixtureDatabase("nntmux_native_eval", false) {
		t.Fatal("nntmux_native_eval should require explicit fixture eval allowance")
	}
	if !IsAllowedNativeFixtureDatabase("nntmux_native_eval", true) {
		t.Fatal("nntmux_native_eval should be allowed with explicit fixture eval allowance")
	}
	if IsAllowedNativeFixtureDatabase("prod_native_eval", true) {
		t.Fatal("only the exact nntmux_native_eval database should be allowed as eval fixture DB")
	}
}

func TestIsAllowedNativeCommitDatabaseIncludesExactEvalDatabase(t *testing.T) {
	t.Parallel()

	if !IsAllowedNativeCommitDatabase("nntmux_native_test") {
		t.Fatal("native test database should remain allowed for commits")
	}
	if !IsAllowedNativeCommitDatabase("nntmux_native_eval") {
		t.Fatal("nntmux_native_eval should be allowed for guarded compose eval commits")
	}
	if IsAllowedNativeCommitDatabase("nntmux_native_eval_backup") {
		t.Fatal("only the exact nntmux_native_eval database should be allowed for eval commits")
	}
}
