FROM docker.io/krickwix/nntmux:microservices-pods-20260804-fragmented-posting-repair-v226@sha256:a9015bfcdc7ea780b5716d3e6d1e34f453797a37ced924122596d294168b3dae

ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision="$SOURCE_REVISION"
LABEL org.opencontainers.image.base.name="docker.io/krickwix/nntmux:microservices-pods-20260804-fragmented-posting-repair-v226"

# v226's par2 guard was end-anchored. The gate it exists to stay ahead of is
# not: ReleaseProcessingService's $par2Only predicate is
# `b.name REGEXP '\.(vol[0-9]+\+[0-9]+\.par2|par2)'`, with no `$`. So the guard
# was strictly WEAKER than the predicate it mirrors -- the worst direction for
# a guard to be wrong in, because it still reads as coverage.
#
# Brace-token binaries put the token after the extension:
#
#   {Lioness.S03.vol063+64.par2} {sraBl51wo8je} yEnc
#
# `$` misses every one. Caught on the first production apply against
# alt.binaries.movies: two cohorts merged, 616 collections, both already
# refused by name as par2_only by BraceTokenIdentityRepairService. No release
# was published and no payload was lost -- these are par2 volumes for a posting
# whose payload is not in the group, so the pipeline's own unanchored predicate
# takes them exactly as it would have before the merge.
#
# The regex here is transcribed from the production predicate rather than
# written from intent, and the regression test uses the brace-token shape.
COPY app/Services/Diagnostics/FragmentedPostingIdentityRepairService.php /app/app/Services/Diagnostics/FragmentedPostingIdentityRepairService.php
