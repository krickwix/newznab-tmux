FROM docker.io/krickwix/nntmux:microservices-pods-20260803-obfuscated-config-regression-v217@sha256:f3825e86776d60952d5db28c98b87d3e9fe0ef5ceaa1258b4bf598eaa41f1494

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260803-obfuscated-config-regression-v217"

# v217 finally made the brace-token normalizer live, which exposed the next
# defect: stripping the token is necessary but not sufficient. The surviving
# name still keys the collection through CollectionsCleaningService, which
# strips digit runs, so part01..partNN and every par2 volume of a posting share
# one key -- 98 real filenames measured onto FIVE. With file_number pinned 1/1
# against UNIQUE (collections_id, filenumber) they would also share ONE binary,
# emitting an NZB describing 1 file instead of 43. Brace-token collections are
# therefore keyed on the de-tokenised filename instead.
COPY app/Services/Binaries/ObfuscatedSubjectNormalizer.php /app/app/Services/Binaries/ObfuscatedSubjectNormalizer.php
COPY app/Services/Binaries/HeaderParser.php /app/app/Services/Binaries/HeaderParser.php
COPY app/Services/Binaries/CollectionHandler.php /app/app/Services/Binaries/CollectionHandler.php

# The reclaim pass for the 51,198 collections stranded before that fix. Needs no
# NNTP access: the real filename survives in collections.subject. Dry-run by
# default.
COPY app/Services/Diagnostics/BraceTokenIdentityRepairService.php /app/app/Services/Diagnostics/BraceTokenIdentityRepairService.php
COPY app/Console/Commands/RepairBraceTokenIdentity.php /app/app/Console/Commands/RepairBraceTokenIdentity.php

# Unrelated to the brace-token work but shipped here because v218 had not been
# deployed yet: the selector's last-resort round-robin read a candidate's yield
# history unguarded, and Laravel turns the resulting E_WARNING into an
# ErrorException that WorkerOrchestrator's catch-all converts into
# failClosed(). Production sat in that crash-and-fail-closed loop from
# 07:22:35 UTC with backfill paused and no pod restart to signal it.
COPY app/Services/Orchestrator/BackfillTargetSelector.php /app/app/Services/Orchestrator/BackfillTargetSelector.php

# No RUN step on purpose: /app/bootstrap/cache holds no config.php, so there is
# no cached config to invalidate, and a RUN here would need cross-arch emulation.
