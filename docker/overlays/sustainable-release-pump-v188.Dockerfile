FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-terminal-pair-hdtv-release-v186@sha256:adb8d04376221749d054ba176b6e52fdfbc066319766c18eb24f710e76546988

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260718-cf-terminal-pair-hdtv-release-v186"

COPY app/Console/Commands/ProcessReleasesCommand.php /app/app/Console/Commands/ProcessReleasesCommand.php
COPY app/Services/Distributed/DistributedJobWorker.php /app/app/Services/Distributed/DistributedJobWorker.php
COPY app/Services/ReleaseCreationService.php /app/app/Services/ReleaseCreationService.php
COPY app/Services/ReleaseProcessingService.php /app/app/Services/ReleaseProcessingService.php
COPY app/Services/Releases/ReleasePump.php /app/app/Services/Releases/ReleasePump.php
COPY app/Services/Runners/ReleasesRunner.php /app/app/Services/Runners/ReleasesRunner.php
COPY app/Services/Tmux/TmuxMonitorService.php /app/app/Services/Tmux/TmuxMonitorService.php
COPY config/nntmux.php /app/config/nntmux.php
