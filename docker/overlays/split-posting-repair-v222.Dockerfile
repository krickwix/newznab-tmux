FROM docker.io/krickwix/nntmux:microservices-pods-20260803-brace-token-posting-repair-v221@sha256:1181b40b385498f2cd02daafb67de16660fd233763d6e8d00b5e8bc213c38ee4

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260803-brace-token-posting-repair-v221"

# Adds a second, independent reclaim pass. v221's target is the brace-token
# obfuscation style; this one targets ordinary unobfuscated multi-file posts,
# which stall for a completely different reason and are the larger residue
# (5,762 cohorts over ~62,668 files versus 51,198 brace-token rows).
#
# The defect is CollectionHandler::collectionIdentity(), which keys a collection
# on `cleanedName . $totalFiles`. On these subjects the only counter present is a
# PART counter, so one posting mints one key per file -- the live Paper Boy
# posting in alt.binaries.cinemageddon spread over 7 collections declaring
# 242/226/124/63/238 "files". Stage 1 then compares a file count against that
# part count: `16 >= CEIL(242 * 0.94)` = `16 >= 228`, false at any completeness,
# so fully downloaded articles sit at filecheck=0 until retention takes them.
#
# The service refuses any cohort whose merged shape either delete predicate
# would take (below_min_files, par2_only) and leaves it stranded, for the same
# reason spelled out in the v221 overlay: recomputing totalfiles without that
# guard is what cost 512 collections and ~541 MB on 2026-08-03. It also parks
# survivor binaries above MAX(filenumber) rather than below zero -- the v220
# failure -- because the final dense ordinals are numbers the cohort's members
# already hold.
#
# Additive: nothing here is on an ingest or release path. Both files are new, and
# no existing file is replaced, so this image behaves exactly like v221 until the
# command is run by hand. Dry-run is the default; --update deletes collections.
COPY app/Services/Diagnostics/SplitPostingIdentityRepairService.php /app/app/Services/Diagnostics/SplitPostingIdentityRepairService.php
COPY app/Console/Commands/RepairSplitPostingIdentity.php /app/app/Console/Commands/RepairSplitPostingIdentity.php

# DELIBERATELY NOT SHIPPED: the ingest-side fix for this class.
#
# Keying these collections on the filename stem instead of the part count is the
# real cure, and it is scoped separately because it changes the identity of every
# live collection in every group -- not just the stalled ones. Until it lands
# this repair is for historical residue only, which is why the survivor hash is
# namespaced ('splitposting:g<id>:...') and cannot be recomputed by ingest: a
# later article for a merged posting mints a new collection rather than joining
# it. That is safe for the residue precisely because these postings have stopped
# receiving articles -- --before is what enforces the assumption -- and would not
# be safe for live traffic.
#
# ALSO NOT A CURE FOR EVERY STALLED ROW, measured rather than assumed: across the
# residue only 24.1% of files (15,122 of 62,668) hold >= 94% of their declared
# parts. Of 5,762 cohorts, 1,716 clear minfilestoformrelease, 358 are all-par2,
# 1,435 survive both delete predicates, and only 197 of those also clear the
# stage 4 completeness bar immediately. The repair fixes identity; the remaining
# 1,238 wait on articles it cannot supply, and 823 cohorts (38,205 files) hold no
# complete file at all. Expect a modest first release yield, not a flush.

# No RUN step on purpose: /app/bootstrap/cache holds no config.php, so there is
# no cached config to invalidate, and a RUN here would need cross-arch emulation.
