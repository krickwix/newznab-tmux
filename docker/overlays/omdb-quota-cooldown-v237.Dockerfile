FROM docker.io/krickwix/nntmux:microservices-pods-20260805-orchestrator-free-run-no-observation-v236@sha256:6a3599322ae811e04e0044255fe35fabb0433e12ff6c18937b927dd722462f23

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260805-orchestrator-free-run-no-observation-v236"

# Stop hammering OMDB after it says the daily quota is gone.
#
# Live symptom, on every movie lookup:
#   IMDb fetch failed [waf_block -> omdb_fallback_http_failure] for tt0043024
#
# Diagnosed in-pod: OMDB answers HTTP 401 with
# {"Response":"False","Error":"Request limit reached!"} -- a spent DAILY
# allowance, reported as 401 rather than 429, which is why nothing recognised
# it. IMDb HTML is WAF-blocked, so every single lookup falls through to OMDB;
# with the classics archives running (3,616 movies in six hours from
# alt.binaries.multimedia.vintage-film alone) that is thousands of refused
# calls an hour against an API that has explicitly asked us to stop. A good way
# to get a key banned.
#
# imdbapi.dev already had this shape -- 429 sets a 300s cooldown. OMDB had
# none: a 401 just returned false and the next title retried immediately.
#
# The cooldown default is an hour, not five minutes, because the allowance is
# daily: retrying every few minutes against a spent quota is precisely the
# behaviour being removed, while an hour still picks the reset up promptly.
# OMDB_COOLDOWN_SECONDS=0 disables it.
#
# A bad key is deliberately NOT treated as an exhausted quota -- cooling down
# for an hour on "Invalid API key!" would hide a misconfiguration behind a
# plausible-looking pause.
#
# This does not fix identification, which was never broken: TMDb carried every
# title through. It removes the wasted calls and the log noise.
COPY app/Services/ImdbScraper.php /app/app/Services/ImdbScraper.php
COPY config/nntmux_api.php /app/config/nntmux_api.php
