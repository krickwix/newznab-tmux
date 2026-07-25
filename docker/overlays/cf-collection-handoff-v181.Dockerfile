FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-provider-reserve-v180@sha256:35963169112aa13374cb16aeb922e20615b4c6ac491e2f9136f44df6d22b6ae9

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260718-cf-provider-reserve-v180"

COPY app/Services/Orchestrator/CurrentForwardWindowLineage.php /app/app/Services/Orchestrator/CurrentForwardWindowLineage.php
COPY app/Services/Releases/SplitCollectionReconciler.php /app/app/Services/Releases/SplitCollectionReconciler.php
COPY database/migrations/2026_07_18_081500_add_current_forward_collection_handoffs.php /app/database/migrations/2026_07_18_081500_add_current_forward_collection_handoffs.php
