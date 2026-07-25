<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use App\Services\NNTP\NNTPService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final readonly class CurrentForwardRefreshAuditor
{
    public function __construct(
        private NNTPService $nntp,
        private CurrentForwardRefreshPlanner $planner,
        private CurrentForwardWindowAudit $windowAudit,
        private CurrentForwardRefreshLedger $ledger,
    ) {}

    /**
     * @return array{enabled:bool,reason:string,audits:list<array<string,mixed>>,rejections:array<string,string>}
     */
    public function audit(?string $onlyGroup = null, bool $record = false): array
    {
        if ($record && config('nntmux_nntp.use_alternate_nntp_server') === true) {
            throw new RuntimeException('Recording current-forward audits is disabled for the alternate provider.');
        }

        $plan = $this->planner->plan();
        if (! $plan['enabled'] || $plan['proposals'] === []) {
            return [
                'enabled' => $plan['enabled'],
                'reason' => $plan['reason'],
                'audits' => [],
                'rejections' => $plan['rejections'],
            ];
        }

        $onlyGroup = $onlyGroup === null ? null : trim($onlyGroup);
        $proposals = array_values(array_filter(
            $plan['proposals'],
            static fn (array $proposal): bool => $onlyGroup === null || $proposal['group'] === $onlyGroup,
        ));
        if ($proposals === []) {
            return [
                'enabled' => true,
                'reason' => 'requested_group_not_eligible',
                'audits' => [],
                'rejections' => $plan['rejections'] + [$onlyGroup ?? '' => 'requested_group_not_eligible'],
            ];
        }

        $useAlternate = config('nntmux_nntp.use_alternate_nntp_server') === true;
        $connected = $useAlternate
            ? $this->nntp->doConnect(false, true)
            : $this->nntp->doConnect();
        if ($connected !== true) {
            throw new RuntimeException('Unable to connect to the configured NNTP provider.');
        }

        $audits = [];
        $rejections = $plan['rejections'];
        try {
            foreach ($proposals as $proposal) {
                try {
                    $audits[] = $this->auditProposal($proposal, $record);
                } catch (Throwable $exception) {
                    $rejections[$proposal['group']] = $exception->getMessage();
                }
            }
        } finally {
            $this->nntp->doQuit(true);
        }

        return [
            'enabled' => true,
            'reason' => $audits === [] ? 'no_audit_passed' : 'audit_passed',
            'audits' => $audits,
            'rejections' => $rejections,
        ];
    }

    /**
     * @param  array{group:string,source_id:int,first:int,last:int,provider_first:int,provider_high:int,provider_observed_at:string}  $proposal
     * @return array<string,mixed>
     */
    private function auditProposal(array $proposal, bool $record): array
    {
        $summary = $this->nntp->selectGroup($proposal['group'], false, true);
        if (NNTPService::isError($summary) || ! is_array($summary)) {
            throw new RuntimeException('provider_group_failed');
        }
        if (($summary['group'] ?? null) !== $proposal['group']) {
            throw new RuntimeException('provider_group_mismatch');
        }

        $providerFirst = (int) ($summary['first'] ?? 0);
        $providerHigh = (int) ($summary['last'] ?? 0);
        if (! CurrentForwardProviderCoverage::covers(
            $providerFirst,
            $providerHigh,
            $proposal['first'],
            $proposal['last'],
        )) {
            throw new RuntimeException('provider_range_drift');
        }

        $headers = $this->nntp->getXOVER($proposal['first'].'-'.$proposal['last']);
        if (NNTPService::isError($headers) || ! is_array($headers)) {
            throw new RuntimeException('provider_xover_failed');
        }
        $continuationAudit = $this->isPendingContinuationProposal($proposal);
        $evidence = $this->windowAudit->analyze(
            $headers,
            $proposal['first'],
            $proposal['last'],
            requireCompleteBinary: ! $continuationAudit,
        );
        $auditedProposal = array_replace($proposal, [
            'provider_first' => $providerFirst,
            'provider_high' => $providerHigh,
            'provider_observed_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $ledgerId = $record
            ? $this->ledger->recordAudit(
                $auditedProposal,
                $evidence,
                $continuationAudit ? 'exact-xover-continuation-v1' : 'exact-xover-v1',
            )
            : null;

        return $auditedProposal + $evidence + [
            'state' => 'AUDITED',
            'recorded' => $record,
            'ledger_id' => $ledgerId,
        ];
    }

    /**
     * @param  array{group:string,source_id:int,first:int,last:int,provider_first:int,provider_high:int,provider_observed_at:string}  $proposal
     */
    private function isPendingContinuationProposal(array $proposal): bool
    {
        if (! config('nntmux.orchestrator.current_forward_continuation_enabled', false)
            || ! Schema::hasColumn('current_forward_windows', 'chain_root_id')
            || ! Schema::hasColumn('current_forward_windows', 'continuation_deadline_at')
        ) {
            return false;
        }

        $root = DB::table('current_forward_windows')
            ->where('source_id', $proposal['source_id'])
            ->where('state', 'CONTINUATION_PENDING')
            ->whereColumn('id', 'chain_root_id')
            ->first();
        $deadline = strtotime((string) ($root->continuation_deadline_at ?? ''));
        if ($root === null || $deadline === false || time() >= $deadline) {
            return false;
        }

        $latestLast = DB::table('current_forward_windows')
            ->where('chain_root_id', $root->id)
            ->whereIn('state', ['CONTINUATION_PENDING', 'CHAINED'])
            ->max('last_article');

        return $latestLast !== null && (int) $latestLast + 1 === $proposal['first'];
    }
}
