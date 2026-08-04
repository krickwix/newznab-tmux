FROM docker.io/krickwix/nntmux:microservices-pods-20260804-split-repair-park-retained-v228@sha256:42dc29cfea256f6c1b991605c03b320737de0037f7f0f1206200eb298f678b4c

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260804-split-repair-park-retained-v228"

# Phase 1 of docs/design/2026-08-04-ingest-collection-keying.md: the classifier
# that tells a real file counter from a PART counter leaked in through
# extractFileNumberAndTotal()'s raw-subject fallback.
#
# NOTHING ACTS ON IT. There is no code path from the flag to a write. The key
# change it enables cannot ship until per-collection ordinals are allocated --
# settings.completion is NULL (=100), so stage 0 reduces to
# COUNT(DISTINCT filenumber) == MAX(filenumber), and without an allocator two
# files of one posting would land in one collection both claiming filenumber 1
# and collide on UNIQUE (collections_id, filenumber).
#
# Inertness was proved differentially, not asserted: the fixtures'
# [fileNumber, totalFiles] are pinned by a test that passes against both this
# service and the one on origin/microservices-pods.
#
# WHY THIS ONE DOES COPY THE CONFIG, when the standing rule is not to. The
# v214/v215 hazard is copying a config MISSING keys the running code reads.
# Direction verified in-pod against the v228 image immediately before this
# build: 180 leaf keys live, ZERO of them absent from the branch file, and the
# branch adds `ingest_partcount_key_groups`. Strict superset -- one key gained,
# none lost. Without the copy the new key would not exist in the image and
# config() would return [] for it, which is the v225 failure exactly: the
# manifest sets the variable, the code reads it, and it silently resolves to a
# default.
COPY app/Services/Binaries/HeaderStorageService.php /app/app/Services/Binaries/HeaderStorageService.php
COPY config/nntmux.php /app/config/nntmux.php
