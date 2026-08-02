FROM docker.io/krickwix/nntmux:microservices-pods-20260802-split-lookback-retention-v215@sha256:028421bdfb9c2b12f682331cb8c5bde98c005ae6684442a76c9d4e3a2431951c

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260802-split-lookback-retention-v215"

COPY app/Services/Releases/SplitCollectionReconciler.php /app/app/Services/Releases/SplitCollectionReconciler.php
COPY app/Services/ReleaseProcessingService.php /app/app/Services/ReleaseProcessingService.php
COPY app/Services/Binaries/BinariesService.php /app/app/Services/Binaries/BinariesService.php
COPY app/Services/Backfill/BackfillService.php /app/app/Services/Backfill/BackfillService.php
