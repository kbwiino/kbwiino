<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\ConstitutionProposal;
use App\Services\ConstitutionService as C;
use App\Support\{Database, HttpException, Request, Response};

final class ConstitutionController extends BaseController
{
    public function show(Request $r): Response
    {
        $project = $this->project($r, 'project.viewDetails');
        $cur = C::current($project);
        $versions = Database::fetchAll('SELECT version_number, status, approved_at FROM research_constitutions WHERE project_id = ? ORDER BY version_number DESC', [$project['id']]);
        $pending = ConstitutionProposal::where(['project_id' => $project['id'], 'status' => 'pending'], 'id DESC');
        return $this->ok([
            'current' => $cur ? C::present($cur) : null,
            'versions' => $versions,
            'pending_proposals' => array_map([C::class, 'presentProposal'], $pending),
            'notice' => 'The Research Constitution is the latest officially approved research structure. Only an authorised supervisor can approve changes; AI findings are suggestions only.',
        ]);
    }

    public function initialize(Request $r): Response
    {
        $project = $this->project($r, 'project.approveConstitution');
        $data = $this->validate($r, ['notes' => 'nullable|string|max:5000']);
        $c = C::initialize($r, $this->user($r), $project, $data['notes'] ?? null);
        return $this->created(C::present($c));
    }

    public function version(Request $r): Response
    {
        $project = $this->project($r, 'project.viewDetails');
        $c = C::version($project, (int) $r->param('version')) ?? throw HttpException::notFound('Version');
        return $this->ok(C::present($c));
    }

    public function proposals(Request $r): Response
    {
        $project = $this->project($r, 'project.viewDetails');
        $rows = ConstitutionProposal::where(['project_id' => $project['id']], 'id DESC');
        return $this->ok(array_map([C::class, 'presentProposal'], $rows));
    }

    public function propose(Request $r): Response
    {
        $project = $this->project($r, 'project.proposeConstitutionChange');
        $data = $this->validate($r, ['changes' => 'required|array', 'rationale' => 'nullable|string|max:5000']);
        $p = C::propose($r, $this->user($r), $project, $data['changes'], $data['rationale'] ?? null);
        return $this->created(C::presentProposal($p));
    }

    public function decide(Request $r): Response
    {
        $project = $this->project($r, 'project.approveConstitution');
        $data = $this->validate($r, ['decision' => 'required|in:approve,reject', 'notes' => 'nullable|string|max:5000']);
        $proposal = ConstitutionProposal::findByUuid((string) $r->param('pid'));
        if (!$proposal || (int) $proposal['project_id'] !== (int) $project['id']) {
            throw HttpException::notFound('Proposal');
        }
        $out = C::decide($r, $this->user($r), $project, $proposal, $data['decision'], $data['notes'] ?? null);
        return $this->ok([
            'proposal' => C::presentProposal($out['proposal']),
            'constitution' => $out['constitution'] ? C::present($out['constitution']) : null,
        ]);
    }

    public function withdraw(Request $r): Response
    {
        $project = $this->project($r, 'project.proposeConstitutionChange');
        $proposal = ConstitutionProposal::findByUuid((string) $r->param('pid'));
        if (!$proposal || (int) $proposal['project_id'] !== (int) $project['id']) {
            throw HttpException::notFound('Proposal');
        }
        if ((int) $proposal['proposed_by'] !== (int) $this->user($r)['id'] || $proposal['status'] !== 'pending') {
            throw HttpException::forbidden('Only the author can withdraw a pending proposal.');
        }
        ConstitutionProposal::updateById((int) $proposal['id'], ['status' => 'withdrawn']);
        return $this->ok(['withdrawn' => true]);
    }

    /** Compare two approved versions, or a version against the project's working data (to=working). */
    public function compare(Request $r): Response
    {
        $project = $this->project($r, 'project.viewDetails');
        $from = $r->input('from', 'current');
        $to = $r->input('to', 'working');
        $resolve = function (string $which) use ($project) {
            if ($which === 'working') {
                return ['label' => 'Working project data (not approved)', 'values' => C::workingSnapshot($project), 'official' => false];
            }
            $c = $which === 'current' ? C::current($project) : C::version($project, (int) $which);
            if (!$c) {
                throw HttpException::notFound('Constitution version');
            }
            return ['label' => 'Constitution v' . $c['version_number'] . ($c['status'] === 'approved' ? ' (current)' : ''), 'values' => C::values($c), 'official' => true];
        };
        $a = $resolve((string) $from);
        $b = $resolve((string) $to);
        return $this->ok([
            'from' => ['label' => $a['label'], 'official' => $a['official']],
            'to' => ['label' => $b['label'], 'official' => $b['official']],
            'rows' => C::compare($a['values'], $b['values']),
        ]);
    }

    public function inconsistencies(Request $r): Response
    {
        $project = $this->project($r, 'project.viewDetails');
        return $this->ok([
            'label' => 'Detected differences are suggestions requiring human review. Nothing has been changed.',
            'items' => C::detectInconsistencies($project),
        ]);
    }
}
