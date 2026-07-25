FROM docker.io/krickwix/nntmux:microservices-pods-20260714-current-forward-v152@sha256:a04d4bcfd5e5030307c7206999e7969938567c07530ac76344767b8a81a616e5

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260714-current-forward-v152"

COPY app/Services/Distributed/DistributedJobWorker.php /app/app/Services/Distributed/DistributedJobWorker.php
COPY app/Services/Tmux/TmuxMonitorService.php /app/app/Services/Tmux/TmuxMonitorService.php
