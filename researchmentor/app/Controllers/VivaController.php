<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Agents\VivaPreparationAgent;
use App\Models\{ResearchProject, VivaSession};
use App\Policies\{Gate, ProjectAccess};
use App\Services\VivaService;
use App\Support\{HttpException, Request, Response};

final class VivaController extends BaseController
{
    private function session(Request $r): array
    {
        $user = $this->user($r);
        $s = VivaSession::findByUuid((string) $r->param('id'));
        if (!$s || !Gate::allows($user, 'viva.view', $s)) {
            throw HttpException::notFound('Session'); // practice sessions are private to the student
        }
        return [$s, ResearchProject::findOrFail((int) $s['project_id'])];
    }

    public function modes(Request $r): Response
    {
        return $this->ok(array_map(fn($k, $v) => ['mode' => $k, 'label' => $v[0], 'description' => $v[1]], array_keys(VivaPreparationAgent::MODES), VivaPreparationAgent::MODES), ['notice' => VivaPreparationAgent::NOTICE]);
    }

    /** POST /api/ai/viva-session */
    public function start(Request $r): Response
    {
        $user = $this->user($r);
        $data = $this->validate($r, ['project_id' => 'required|uuid', 'mode' => 'required|in:' . implode(',', array_keys(VivaPreparationAgent::MODES))]);
        $project = ResearchProject::findByUuid($data['project_id']);
        if (!$project || !ProjectAccess::relations($user, $project)) {
            throw HttpException::notFound('Project');
        }
        $s = VivaService::start($r, $user, $project, $data['mode']);
        return $this->created(VivaService::present($s));
    }

    public function show(Request $r): Response
    {
        [$s] = $this->session($r);
        return $this->ok(VivaService::present($s));
    }

    public function answer(Request $r): Response
    {
        [$s, $project] = $this->session($r);
        $data = $this->validate($r, ['question_id' => 'required|uuid', 'answer' => 'required|string|min:2|max:8000']);
        return $this->ok(VivaService::answer($r, $this->user($r), $project, $s, $data['question_id'], $data['answer']));
    }

    public function end(Request $r): Response
    {
        [$s] = $this->session($r);
        return $this->ok(VivaService::present(VivaService::end($r, $this->user($r), $s)));
    }

    public function history(Request $r): Response
    {
        $user = $this->user($r);
        $project = $this->project($r, 'project.view');
        $rows = VivaSession::where(['project_id' => $project['id'], 'student_id' => $user['id']], 'id DESC', 50);
        return $this->ok(array_map(fn($s) => VivaService::present($s, false), $rows), ['notice' => VivaPreparationAgent::NOTICE]);
    }
}
