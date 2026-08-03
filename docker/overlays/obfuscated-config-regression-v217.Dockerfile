FROM docker.io/krickwix/nntmux:microservices-pods-20260802-misaligned-cursor-pump-deadline-v216@sha256:7925bd7828cf215ff10aba8947c06ad4ba977b44e1daf99df22f8fd04102f70b

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260802-misaligned-cursor-pump-deadline-v216"

# The whole point of this build: v214/v215 copied config/nntmux.php from a
# branch that lacked the obfuscated_* keys, so config() resolved to NULL and
# the normalizers already shipped in v216 ran as permanent no-ops.
COPY config/nntmux.php /app/config/nntmux.php

# Distinguish unmeasured from zero in the orchestrator's yield and safety
# signals. Verified byte-identical to v216 apart from these changes, so the
# COPY cannot clobber out-of-band content.
COPY app/Services/Metrics/NntmuxPrometheusMetrics.php /app/app/Services/Metrics/NntmuxPrometheusMetrics.php
COPY app/Services/Orchestrator/PipelineSnapshot.php /app/app/Services/Orchestrator/PipelineSnapshot.php
COPY app/Services/Orchestrator/PipelineSnapshotRepository.php /app/app/Services/Orchestrator/PipelineSnapshotRepository.php
COPY app/Services/Orchestrator/PrometheusSafetySignalProvider.php /app/app/Services/Orchestrator/PrometheusSafetySignalProvider.php
COPY app/Services/Orchestrator/WorkerControlPolicy.php /app/app/Services/Orchestrator/WorkerControlPolicy.php
COPY app/Services/Orchestrator/WorkerControlStateStore.php /app/app/Services/Orchestrator/WorkerControlStateStore.php

# No RUN step on purpose: /app/bootstrap/cache holds no config.php, so there is
# no cached config to invalidate, and a RUN here would need cross-arch emulation.
