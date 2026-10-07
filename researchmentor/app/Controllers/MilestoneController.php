<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\ResearchMilestone;
use App\Services\AuditLogger;
use App\Support\{HttpException, Request, Response};

final class MilestoneController extends BaseController
{
    private function present(array $m): array
    {
        $state = $m['completed_at'] ? 'completed' : ($m['due_date'] < gmdate('Y-m-d') ? 'overdue' : 'pending');
        return ['id' => $m['uuid'], 'title' => $m['title'], 'description' => $m['description'], 'due_date' => $m['due_date'], 'completed_at' => $m['completed_at'], 'state' => $state];
    }

    public function index(Request $r): Response
    {
        $project = $this->project($r, 'project.viewProgress');
        return $this->ok(array_map([$this, 'present'], ResearchMilestone::where(['project_id' => $project['id']], 'due_date ASC, id ASC')));
    }

    public function store(Request $r): Response
    {
        $project = $this->project($r, 'project.manageMilestones');
        $data = $this->validate($r, ['title' => 'required|string|max:255', 'description' => 'nullable|string|max:5000', 'due_date' => 'required|date']);
        $id = ResearchMilestone::create($data + ['project_id' => $project['id'], 'created_by' => $this->user($r)['id']]);
        $m = ResearchMilestone::find($id);
        AuditLogger::log($r, 'milestone.create', 'milestone', $m['uuid'], ['project' => $project['uuid']]);
        return $this->created($this->present($m));
    }

    private function load(Request $r, array $project): array
    {
        $m = ResearchMilestone::findByUuid((string) $r->param('mid'));
        if (!$m || (int) $m['project_id'] !== (int) $project['id']) {
            throw HttpException::notFound('Milestone');
        }
        return $m;
    }

    public function update(Request $r): Response
    {
        $project = $this->project($r, 'project.manageMilestones');
        $m = $this->load($r, $project);
        $data = $this->validate($r, array_intersect_key(['title' => 'required|string|max:255', 'description' => 'nullable|string|max:5000', 'due_date' => 'required|date'], $r->body));
        ResearchMilestone::updateById((int) $m['id'], $data);
        return $this->ok($this->present(ResearchMilestone::find((int) $m['id'])));
    }

    public function complete(Request $r): Response
    {
        $project = $this->project($r, 'project.manageMilestones');
        $m = $this->load($r, $project);
        $done = (bool) ($r->body['completed'] ?? true);
        ResearchMilestone::updateById((int) $m['id'], ['completed_at' => $done ? gmdate('Y-m-d H:i:s') : null]);
        AuditLogger::log($r, $done ? 'milestone.complete' : 'milestone.reopen', 'milestone', $m['uuid'], ['project' => $project['uuid']]);
        return $this->ok($this->present(ResearchMilestone::find((int) $m['id'])));
    }

    public function destroy(Request $r): Response
    {
        $project = $this->project($r, 'project.manageMilestones');
        $m = $this->load($r, $project);
        ResearchMilestone::deleteById((int) $m['id']);
        return $this->ok(['deleted' => true]);
    }
}
