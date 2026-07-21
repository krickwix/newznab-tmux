FROM docker.io/krickwix/nntmux:microservices-pods-20260718-cf-release-disposition-release-v179@sha256:8e1d68b8004ed126f210f63e66a3c467f192d2ca3909d94a696542974dec0fc8

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260718-cf-release-disposition-release-v179"

COPY app/Services/Orchestrator/CurrentForwardWindowLineage.php /app/app/Services/Orchestrator/CurrentForwardWindowLineage.php
COPY app/Services/Releases/SplitCollectionReconciler.php /app/app/Services/Releases/SplitCollectionReconciler.php
COPY database/migrations/2026_07_18_081500_add_current_forward_collection_handoffs.php /app/database/migrations/2026_07_18_081500_add_current_forward_collection_handoffs.php
