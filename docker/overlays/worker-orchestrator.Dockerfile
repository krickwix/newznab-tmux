FROM docker.io/krickwix/nntmux:microservices-pods-20260711-nzb-selector-wide-v15@sha256:0955aa53096e745c9b7ae5ea424e48fe1d49f6f3a313e1e6fc0a00224e7026bd

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260711-nzb-selector-wide-v15"

COPY app/Console/Commands/NntmuxWorkerOrchestrator.php /app/app/Console/Commands/NntmuxWorkerOrchestrator.php
COPY app/Services/Distributed/BackfillPermitGate.php /app/app/Services/Distributed/BackfillPermitGate.php
COPY app/Services/Distributed/DistributedJobCatalog.php /app/app/Services/Distributed/DistributedJobCatalog.php
COPY app/Services/Distributed/DistributedJobWorker.php /app/app/Services/Distributed/DistributedJobWorker.php
COPY app/Services/Metrics/NntmuxPrometheusMetrics.php /app/app/Services/Metrics/NntmuxPrometheusMetrics.php
COPY app/Services/Nzb/NzbBacklogCreationService.php /app/app/Services/Nzb/NzbBacklogCreationService.php
COPY app/Services/NNTP/NntpArticleDate.php /app/app/Services/NNTP/NntpArticleDate.php
COPY app/Services/Orchestrator /app/app/Services/Orchestrator
COPY app/Services/Runners/BackfillRunner.php /app/app/Services/Runners/BackfillRunner.php
COPY app/Services/Tmux/Tmux.php /app/app/Services/Tmux/Tmux.php
COPY config/nntmux.php /app/config/nntmux.php
COPY database/seeders/SettingsTableSeeder.php /app/database/seeders/SettingsTableSeeder.php
