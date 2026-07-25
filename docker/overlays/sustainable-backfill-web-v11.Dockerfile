FROM docker.io/krickwix/nntmux:microservices-pods-20260711-nzb-cleanup-web-amd64-v9@sha256:81b9c5ae681caa95974dc66659c26dfa72ff08321f2389c545ed193f772535bf

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260711-nzb-cleanup-web-amd64-v9"

COPY app/Console/Commands/BackfillGroup.php /app/app/Console/Commands/BackfillGroup.php
COPY app/Console/Commands/GetArticleRange.php /app/app/Console/Commands/GetArticleRange.php
COPY app/Console/Commands/ProcessBackfill.php /app/app/Console/Commands/ProcessBackfill.php
COPY app/Console/Commands/ProcessSafe.php /app/app/Console/Commands/ProcessSafe.php
COPY app/Console/Commands/UpdateBackfill.php /app/app/Console/Commands/UpdateBackfill.php
COPY app/Console/Commands/UpdatePerGroup.php /app/app/Console/Commands/UpdatePerGroup.php
COPY app/Services/Backfill/BackfillService.php /app/app/Services/Backfill/BackfillService.php
COPY app/Services/Binaries/BinariesService.php /app/app/Services/Binaries/BinariesService.php
COPY app/Services/Distributed/BackfillExecutionGuard.php /app/app/Services/Distributed/BackfillExecutionGuard.php
COPY app/Services/ForkingService.php /app/app/Services/ForkingService.php
COPY app/Services/Releases/ReleasePump.php /app/app/Services/Releases/ReleasePump.php
COPY app/Services/Runners/BackfillRunner.php /app/app/Services/Runners/BackfillRunner.php
COPY app/Services/Runners/BaseRunner.php /app/app/Services/Runners/BaseRunner.php
COPY config/nntmux.php /app/config/nntmux.php
