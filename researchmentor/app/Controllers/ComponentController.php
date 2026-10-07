<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\{ResearchHypothesis, ResearchObjective, ResearchQuestion, ResearchTheory, ResearchVariable, ConceptualFramework};
use App\Policies\ProjectAccess;
use App\Services\AuditLogger;
use App\Support\{Database, HttpException, Request, Response};

/** CRUD for the project's working research design: objectives, questions, hypotheses, variables, theories. */
final class ComponentController extends BaseController
{
    private const CONFIG = [
        'objectives' => ['model' => ResearchObjective::class, 'text' => 'body', 'rules' => ['body' => 'required|string|max:5000', 'objective_type' => 'in:general,specific']],
        'questions' => ['model' => ResearchQuestion::class, 'text' => 'body', 'rules' => ['body' => 'required|string|max:5000', 'objective_id' => 'nullable|uuid'], 'links' => ['objective_id' => ResearchObjective::class]],
        'hypotheses' => ['model' => ResearchHypothesis::class, 'text' => 'body', 'rules' => ['body' => 'required|string|max:5000', 'kind' => 'in:null,alternative,directional', 'question_id' => 'nullable|uuid'], 'links' => ['question_id' => ResearchQuestion::class]],
        'variables' => ['model' => ResearchVariable::class, 'text' => 'name', 'rules' => ['name' => 'required|string|max:255', 'variable_role' => 'in:independent,dependent,mediating,moderating,control,other', 'definition' => 'nullable|string|max:5000', 'indicators' => 'nullable|string|max:5000', 'instrument' => 'nullable|string|max:5000', 'measurement_scale' => 'nullable|string|max:100', 'hypothesis_id' => 'nullable|uuid'], 'links' => ['hypothesis_id' => ResearchHypothesis::class]],
        'theories' => ['model' => ResearchTheory::class, 'text' => 'name', 'rules' => ['name' => 'required|string|max:255', 'proponents' => 'nullable|string|max:255', 'relevance' => 'nullable|string|max:5000']],
    ];

    private function cfg(Request $r): array
    {
        $name = explode('/', $r->path)[4] ?? '';
        return (self::CONFIG[$name] ?? throw HttpException::notFound('Resource')) + ['name' => $name];
    }

    private function present(array $cfg, array $row): array
    {
        $out = ['id' => $row['uuid'], 'origin' => $row['origin'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
        foreach (array_keys($cfg['rules']) as $f) {
            if (isset($cfg['links'][$f])) {
                $out[$f] = $row[$f] ? ($cfg['links'][$f]::find((int) $row[$f])['uuid'] ?? null) : null;
            } else {
                $out[$f] = $row[$f] ?? null;
            }
        }
        if (isset($row['position'])) {
            $out['position'] = (int) $row['position'];
        }
        return $out;
    }

    private function resolveLinks(array $cfg, array $data, array $project): array
    {
        foreach ($cfg['links'] ?? [] as $field => $model) {
            if (array_key_exists($field, $data)) {
                if ($data[$field] === null) {
                    continue;
                }
                $target = $model::findByUuid($data[$field]);
                if (!$target || (int) $target['project_id'] !== (int) $project['id']) {
                    throw HttpException::unprocessable('Invalid link.', [$field => ['Unknown item in this project.']]);
                }
                $data[$field] = $target['id'];
            }
        }
        return $data;
    }

    private function origin(Request $r, array $project): string
    {
        return in_array('supervisor', ProjectAccess::relations($this->user($r), $project), true) ? 'supervisor' : 'student';
    }

    public function index(Request $r): Response
    {
        $project = $this->project($r, 'project.viewDetails');
        $cfg = $this->cfg($r);
        $order = $cfg['name'] === 'variables' || $cfg['name'] === 'theories' ? 'id ASC' : 'position ASC, id ASC';
        $rows = $cfg['model']::where(['project_id' => $project['id']], $order);
        return $this->ok(array_map(fn($row) => $this->present($cfg, $row), $rows));
    }

    public function store(Request $r): Response
    {
        $project = $this->project($r, 'project.update');
        $cfg = $this->cfg($r);
        $data = $this->resolveLinks($cfg, $this->validate($r, $cfg['rules']), $project);
        $model = $cfg['model'];
        $row = $data + ['project_id' => $project['id'], 'origin' => $this->origin($r, $project), 'created_by' => $this->user($r)['id']];
        if (in_array('position', $model::columns(), true)) {
            $row['position'] = (int) Database::fetchValue('SELECT COALESCE(MAX(position),0)+1 FROM `' . $model::table() . '` WHERE project_id = ?', [$project['id']]);
        }
        $id = $model::create($row);
        $created = $model::find($id);
        AuditLogger::log($r, 'project.' . $cfg['name'] . '_add', $cfg['name'], $created['uuid'], ['project' => $project['uuid']]);
        return $this->created($this->present($cfg, $created));
    }

    private function item(Request $r, array $cfg, array $project): array
    {
        $row = $cfg['model']::findByUuid((string) $r->param('item'));
        if (!$row || (int) $row['project_id'] !== (int) $project['id']) {
            throw HttpException::notFound('Item');
        }
        return $row;
    }

    public function update(Request $r): Response
    {
        $project = $this->project($r, 'project.update');
        $cfg = $this->cfg($r);
        $row = $this->item($r, $cfg, $project);
        // Partial update: validate only the supplied fields (required fields stay non-empty when supplied).
        $rules = array_intersect_key($cfg['rules'], $r->body);
        $data = $this->resolveLinks($cfg, $this->validate($r, $rules), $project);
        $cfg['model']::updateById((int) $row['id'], $data);
        AuditLogger::log($r, 'project.' . $cfg['name'] . '_update', $cfg['name'], $row['uuid'], ['project' => $project['uuid'], 'fields' => array_keys($data)]);
        return $this->ok($this->present($cfg, $cfg['model']::find((int) $row['id'])));
    }

    public function destroy(Request $r): Response
    {
        $project = $this->project($r, 'project.update');
        $cfg = $this->cfg($r);
        $row = $this->item($r, $cfg, $project);
        $cfg['model']::deleteById((int) $row['id']);
        AuditLogger::log($r, 'project.' . $cfg['name'] . '_delete', $cfg['name'], $row['uuid'], ['project' => $project['uuid']]);
        return $this->ok(['deleted' => true]);
    }

    public function framework(Request $r): Response
    {
        $project = $this->project($r, 'project.viewDetails');
        $fw = ConceptualFramework::first(['project_id' => $project['id']]);
        return $this->ok($fw ? ['description' => $fw['description'], 'relationships' => json_decode((string) $fw['relationships'], true) ?: [], 'updated_at' => $fw['updated_at']] : null);
    }

    public function saveFramework(Request $r): Response
    {
        $project = $this->project($r, 'project.update');
        $data = $this->validate($r, ['description' => 'required|string|max:20000', 'relationships' => 'nullable|array|max:100']);
        $rels = [];
        foreach ($data['relationships'] ?? [] as $rel) {
            if (is_array($rel) && isset($rel['from'], $rel['to'])) {
                $rels[] = ['from' => mb_substr((string) $rel['from'], 0, 255), 'to' => mb_substr((string) $rel['to'], 0, 255), 'type' => mb_substr((string) ($rel['type'] ?? ''), 0, 100)];
            }
        }
        $fw = ConceptualFramework::first(['project_id' => $project['id']]);
        $payload = ['description' => $data['description'], 'relationships' => $rels, 'updated_by' => $this->user($r)['id']];
        $fw ? ConceptualFramework::updateById((int) $fw['id'], $payload) : ConceptualFramework::create($payload + ['project_id' => $project['id']]);
        AuditLogger::log($r, 'project.framework_save', 'project', $project['uuid'], ['project' => $project['uuid']]);
        return $this->ok(['description' => $data['description'], 'relationships' => $rels]);
    }
}
