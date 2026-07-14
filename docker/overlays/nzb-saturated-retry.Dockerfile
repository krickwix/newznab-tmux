FROM docker.io/krickwix/nntmux:microservices-pods-20260714-nzb-completion-v143@sha256:3f5ccef8d0c088dac9d07c4ae01426ce8aa4d109858bb91075e672ae61665e0b

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260714-nzb-completion-v143"

COPY app/Services/Distributed/DistributedJobWorker.php /app/app/Services/Distributed/DistributedJobWorker.php
COPY app/Services/Tmux/TmuxMonitorService.php /app/app/Services/Tmux/TmuxMonitorService.php
