<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Models\{AcademicYear, ConceptualFramework, Department, Faculty, Institution, KnowledgeDocument, Programme, ProjectMember, ResearchHypothesis, ResearchMilestone, ResearchObjective, ResearchProject, ResearchQuestion, ResearchTheory, ResearchVariable, Role, StageHistory, User};
use App\Services\{AiReviewService, AuthService, ConstitutionService, DocumentService, KnowledgeService, ProjectService, SupervisionService};
use App\Services\AI\{AIFactory, MockAIService};
use App\Support\{Container, Database, Request, Session, UploadedFile};

/**
 * Demo data: Demo University, 3 supervisors, 10 students, 10 projects, sample documents, meetings, tasks,
 * guidelines and AI review results. Everything goes through the real services (so it obeys the same rules
 * as production). AI results are produced by the clearly-labelled MOCK provider and are flagged is_mock.
 * DEVELOPMENT / DEMONSTRATION ONLY.
 */
final class DemoSeeder
{
    private array $org = [];
    private array $users = [];
    private string $password;
    private Request $req;
    private array $tmp = [];

    public function __construct(?string $password = null)
    {
        $this->password = $password ?: ($_ENV['DEMO_PASSWORD'] ?? getenv('DEMO_PASSWORD') ?: 'Demo-Passw0rd!2026');
        $this->req = new Request('POST', '/seed', [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], Session::array());
    }

    public function run(): array
    {
        if (Institution::first(['code' => 'DEMO'])) {
            throw new \RuntimeException('Demo University already exists. Run "php bin/migrate.php fresh" first (development only).');
        }
        Container::instance('ai.service', new MockAIService());
        Database::transaction(function () {
            $this->institution();
            $this->people();
        });
        $this->projects();
        $this->guidelines();
        $this->activity();
        $this->cleanup();
        return $this->credentials();
    }

    private function institution(): void
    {
        $iid = Institution::create(['name' => 'Demo University', 'code' => 'DEMO', 'country' => 'UG', 'privacy_notice' => "Demo University processes your research supervision records to support postgraduate supervision.\nThis is DEMONSTRATION text. Contact the Data Protection Officer at dpo@demo.edu to exercise your rights under the Uganda Data Protection and Privacy Act, 2019.", 'retention_days' => 1825]);
        $fid = Faculty::create(['institution_id' => $iid, 'name' => 'Faculty of Science and Technology', 'code' => 'FST']);
        $fid2 = Faculty::create(['institution_id' => $iid, 'name' => 'Faculty of Education', 'code' => 'FED']);
        $d1 = Department::create(['institution_id' => $iid, 'faculty_id' => $fid, 'name' => 'Computer Science', 'code' => 'CS']);
        $d2 = Department::create(['institution_id' => $iid, 'faculty_id' => $fid2, 'name' => 'Educational Foundations', 'code' => 'EDF']);
        $p1 = Programme::create(['institution_id' => $iid, 'department_id' => $d1, 'name' => 'MSc Computer Science', 'code' => 'MSC-CS', 'degree_level' => 'masters']);
        $p2 = Programme::create(['institution_id' => $iid, 'department_id' => $d1, 'name' => 'PhD Computer Science', 'code' => 'PHD-CS', 'degree_level' => 'phd']);
        $p3 = Programme::create(['institution_id' => $iid, 'department_id' => $d2, 'name' => 'MA Education', 'code' => 'MA-EDU', 'degree_level' => 'masters']);
        $y = AcademicYear::create(['institution_id' => $iid, 'label' => '2025/2026', 'starts_on' => '2025-08-01', 'ends_on' => '2026-07-31', 'is_current' => 1]);
        $this->org = compact('iid', 'fid', 'fid2', 'd1', 'd2', 'p1', 'p2', 'p3', 'y');
    }

    private function user(string $email, string $first, string $last, string $role, ?int $dept = null, ?int $prog = null): array
    {
        $id = User::create([
            'institution_id' => $this->org['iid'], 'department_id' => $dept, 'programme_id' => $prog, 'email' => $email,
            'password_hash' => password_hash($this->password, PASSWORD_DEFAULT), 'first_name' => $first, 'last_name' => $last,
        ]);
        Database::execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$id, Role::first(['slug' => $role])['id']]);
        return AuthService::loadUser($id);
    }

    private function people(): void
    {
        $o = $this->org;
        $this->users['sysadmin'] = $this->user('sysadmin@demo.edu', 'Sam', 'Systems', 'sysadmin');
        $this->users['gradadmin'] = $this->user('gradadmin@demo.edu', 'Grace', 'Namugga', 'grad_admin');
        $this->users['coordinator'] = $this->user('coordinator@demo.edu', 'Peter', 'Ssemakula', 'coordinator', $o['d1']);
        $this->users['examiner'] = $this->user('examiner@demo.edu', 'Prof. Joan', 'Achieng', 'external_examiner');
        $this->users['sup1'] = $this->user('supervisor1@demo.edu', 'Grace', 'Nakato', 'supervisor', $o['d1']);
        $this->users['sup2'] = $this->user('supervisor2@demo.edu', 'Samuel', 'Okello', 'supervisor', $o['d1']);
        $this->users['sup3'] = $this->user('supervisor3@demo.edu', 'Amina', 'Namukasa', 'supervisor', $o['d2']);
        $names = [['Brian', 'Mugisha'], ['Esther', 'Atim'], ['David', 'Kato'], ['Sarah', 'Nabirye'], ['Isaac', 'Opio'], ['Lydia', 'Nakirya'], ['Moses', 'Wandera'], ['Ruth', 'Akello'], ['Joseph', 'Tumusiime'], ['Faith', 'Namatovu']];
        foreach ($names as $i => [$f, $l]) {
            $dept = $i < 7 ? $o['d1'] : $o['d2'];
            $prog = $i === 6 ? $o['p2'] : ($i < 7 ? $o['p1'] : $o['p3']);
            $this->users['student' . ($i + 1)] = $this->user('student' . ($i + 1) . '@demo.edu', $f, $l, 'student', $dept, $prog);
        }
    }

    private function projects(): void
    {
        $o = $this->org;
        // [student#, supervisor, dept, programme, faculty, degree, stage, title, area, approach, design, problem, purpose]
        $defs = [
            [1, 'sup1', 'd1', 'p1', 'fid', 'masters', 'proposal_development', 'Effects of mobile money adoption on household savings in rural Uganda', 'Financial inclusion', 'quantitative', 'Cross-sectional survey', 'Rural households have limited access to formal savings products, and it is unclear whether mobile money use changes saving behaviour.', 'To examine the relationship between mobile money use and household savings among rural households.'],
            [2, 'sup1', 'd1', 'p1', 'fid', 'masters', 'data_collection', 'A machine-learning approach to early detection of maize diseases from smartphone images', 'Applied machine learning', 'quantitative', 'Experimental design', 'Smallholder farmers detect maize diseases late, which reduces yields.', 'To develop and evaluate a lightweight image classifier for common maize diseases.'],
            [3, 'sup2', 'd1', 'p1', 'fid', 'masters', 'thesis_writing', 'Usability of a hospital appointment system in public health facilities', 'Human-computer interaction', 'mixed', 'Explanatory sequential mixed methods', 'Patients report long waiting times despite a digital appointment system.', 'To assess usability of the appointment system and explain usability problems.'],
            [4, 'sup2', 'd1', 'p1', 'fid', 'masters', 'proposal_defense', 'Cybersecurity awareness among small and medium enterprises in Kampala', 'Information security', 'quantitative', 'Descriptive survey', 'SMEs are increasingly targeted by cyber attacks, but their awareness levels are unknown.', 'To determine the level of cybersecurity awareness among SME employees.'],
            [5, 'sup1', 'd1', 'p1', 'fid', 'masters', 'topic_selection', 'Adoption of e-learning platforms by secondary school teachers', 'Educational technology', 'qualitative', 'Multiple case study', 'Teachers rarely use available e-learning platforms.', 'To explore factors shaping teachers\' adoption of e-learning platforms.'],
            [6, 'sup2', 'd1', 'p1', 'fid', 'masters', 'ethics_approval', 'Blockchain-based land records: stakeholder perceptions in Wakiso District', 'Information systems', 'qualitative', 'Phenomenological study', 'Land disputes are common and record-keeping is weak.', 'To understand stakeholders\' perceptions of blockchain-based land records.'],
            [7, 'sup1', 'd1', 'p2', 'fid', 'phd', 'proposal_development', 'A framework for explainable credit-scoring models in microfinance institutions', 'Explainable AI', 'mixed', 'Design science research', 'Microfinance lenders cannot explain automated credit decisions to borrowers.', 'To design and evaluate a framework for explainable credit scoring.'],
            [8, 'sup3', 'd2', 'p3', 'fid2', 'masters', 'data_analysis', 'Peer mentoring and academic performance of first-year students', 'Higher education', 'quantitative', 'Quasi-experimental design', 'First-year attrition is high, and peer mentoring is under-researched locally.', 'To establish the effect of peer mentoring on first-year academic performance.'],
            [9, 'sup3', 'd2', 'p3', 'fid2', 'masters', 'internal_review', 'Teachers\' perceptions of competence-based curriculum implementation', 'Curriculum studies', 'qualitative', 'Interpretive case study', 'Teachers report difficulty implementing the competence-based curriculum.', 'To explore teachers\' perceptions of implementation challenges.'],
            [10, 'sup3', 'd2', 'p3', 'fid2', 'masters', 'proposal_development', 'Parental involvement and learner retention in primary schools', 'Educational psychology', 'quantitative', 'Correlational survey', 'Learner dropout is high in rural primary schools.', 'To examine the relationship between parental involvement and learner retention.'],
        ];
        $this->projects = [];
        foreach ($defs as $d) {
            [$sn, $sup, $dk, $pk, $fk, $deg, $stage, $title, $area, $approach, $design, $problem, $purpose] = $d;
            $student = $this->users['student' . $sn];
            $pid = ResearchProject::create([
                'institution_id' => $o['iid'], 'faculty_id' => $o[$fk], 'department_id' => $o[$dk], 'programme_id' => $o[$pk], 'academic_year_id' => $o['y'],
                'owner_user_id' => $student['id'], 'degree_level' => $deg, 'title' => $title, 'research_area' => $area, 'problem_statement' => $problem, 'purpose' => $purpose,
                'approach' => $approach, 'design' => $design, 'stage' => $stage, 'status' => 'active', 'created_by' => $student['id'],
                'population' => $approach === 'qualitative' ? 'Purposively selected participants' : 'Households / respondents in the study area',
                'sampling_approach' => $approach === 'qualitative' ? 'Purposive sampling until saturation' : 'Stratified random sampling',
                'data_collection_methods' => $approach === 'qualitative' ? 'Semi-structured interviews' : 'Structured questionnaire',
                'data_analysis_methods' => $approach === 'qualitative' ? 'Thematic analysis' : 'Descriptive statistics and regression',
                'ethics_information' => 'Informed consent; anonymised data; ethics clearance from the institutional committee.',
                'ai_consent_at' => gmdate('Y-m-d H:i:s'), 'ai_consent_by' => $student['id'],
            ]);
            ProjectMember::create(['project_id' => $pid, 'user_id' => $student['id'], 'member_role' => 'student']);
            ProjectMember::create(['project_id' => $pid, 'user_id' => $this->users[$sup]['id'], 'member_role' => 'supervisor', 'assigned_by' => $this->users['gradadmin']['id']]);
            StageHistory::create(['project_id' => $pid, 'to_stage' => 'topic_selection', 'changed_by' => $student['id'], 'notes' => 'Project created']);
            if ($stage !== 'topic_selection') {
                StageHistory::create(['project_id' => $pid, 'from_stage' => 'topic_selection', 'to_stage' => $stage, 'changed_by' => $this->users[$sup]['id'], 'notes' => 'Moved by supervisor']);
            }
            $this->projects[$sn] = ResearchProject::find($pid);
            $this->design($this->projects[$sn], $student, $approach);
            ResearchMilestone::create(['project_id' => $pid, 'title' => 'Submit research proposal', 'due_date' => '2026-03-15', 'completed_at' => $sn <= 4 ? '2026-03-10 10:00:00' : null, 'created_by' => $student['id']]);
            ResearchMilestone::create(['project_id' => $pid, 'title' => 'Ethics clearance', 'due_date' => '2026-05-30', 'completed_at' => in_array($sn, [2, 3, 8], true) ? '2026-05-20 12:00:00' : null, 'created_by' => $student['id']]);
            ResearchMilestone::create(['project_id' => $pid, 'title' => 'Complete data collection', 'due_date' => gmdate('Y-m-d', strtotime('+60 days')), 'created_by' => $student['id']]);
        }
        \App\Policies\ProjectAccess::flush();
        // external examiner explicitly assigned to one project only
        ProjectMember::create(['project_id' => $this->projects[3]['id'], 'user_id' => $this->users['examiner']['id'], 'member_role' => 'examiner', 'assigned_by' => $this->users['gradadmin']['id']]);
        \App\Policies\ProjectAccess::flush();
    }

    private array $projects = [];

    private function design(array $p, array $student, string $approach): void
    {
        $id = (int) $p['id'];
        $mk = fn(string $model, array $row) => $model::create($row + ['project_id' => $id, 'origin' => 'student', 'created_by' => $student['id']]);
        $o1 = $mk(ResearchObjective::class, ['body' => 'To establish the level of the main phenomenon among the study population.', 'position' => 1, 'objective_type' => 'specific']);
        $o2 = $mk(ResearchObjective::class, ['body' => 'To examine the relationship between the key study variables.', 'position' => 2, 'objective_type' => 'specific']);
        $q1 = $mk(ResearchQuestion::class, ['body' => 'What is the level of the main phenomenon among the study population?', 'position' => 1, 'objective_id' => $o1]);
        $q2 = $mk(ResearchQuestion::class, ['body' => 'What is the relationship between the key study variables?', 'position' => 2, 'objective_id' => $o2]);
        if ($approach !== 'qualitative') {
            $h = $mk(ResearchHypothesis::class, ['body' => 'H1: There is a significant relationship between the independent and dependent variables.', 'position' => 1, 'question_id' => $q2, 'kind' => 'alternative']);
            ResearchVariable::create(['project_id' => $id, 'hypothesis_id' => $h, 'name' => 'Independent variable', 'variable_role' => 'independent', 'indicators' => 'Frequency of use; intensity', 'instrument' => 'Questionnaire section B', 'origin' => 'student']);
            ResearchVariable::create(['project_id' => $id, 'hypothesis_id' => $h, 'name' => 'Dependent variable', 'variable_role' => 'dependent', 'indicators' => 'Reported outcome measure', 'instrument' => 'Questionnaire section C', 'origin' => 'student']);
        }
        ResearchTheory::create(['project_id' => $id, 'name' => $approach === 'qualitative' ? 'Technology Acceptance Model' : 'Diffusion of Innovations', 'proponents' => $approach === 'qualitative' ? 'Davis' : 'Rogers', 'origin' => 'student']);
        ConceptualFramework::create(['project_id' => $id, 'description' => 'The independent variable is expected to influence the dependent variable (demonstration framework).', 'relationships' => [['from' => 'Independent variable', 'to' => 'Dependent variable', 'type' => 'influences']], 'updated_by' => $student['id']]);
    }

    private function upload(array $actor, array $project, string $category, string $title, string $name, string $bytes, ?array $existing = null, array $extra = []): array
    {
        $path = tempnam(sys_get_temp_dir(), 'rmseed');
        file_put_contents($path, $bytes);
        $this->tmp[] = $path;
        $file = new UploadedFile($path, $name, strlen($bytes), UPLOAD_ERR_OK, true);
        $req = new Request('POST', '/seed', [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], Session::array());
        $req->attributes['user'] = $actor;
        return DocumentService::upload($req, $actor, $project, ['category' => $category, 'title' => $title] + $extra, $file, $existing);
    }

    private function sampleProposal(array $p): string
    {
        return "DEMONSTRATION SAMPLE TEXT (not a real thesis)\n\nChapter 1 Introduction\n\n1.1 Background\n" . $p['research_area'] . " has attracted growing attention in Uganda. This sample background paragraph exists only to demonstrate document handling.\n\n1.2 Problem statement\n" . $p['problem_statement'] . " However, no evidence is cited in this sample to support the claim.\n\n1.3 Purpose of the study\n" . $p['purpose'] . "\n\n1.4 Research objectives\nThe study objectives are listed in the project record. Some objectives in this sample are deliberately broad so the review tools have something to comment on.\n\nChapter 3 Methodology\n\n3.1 Research design\nThe study will use a " . $p['design'] . " design.\n\n3.4 Sampling\nA sample will be selected from the population using " . $p['sampling_approach'] . ". The sample size is not yet justified in this draft.\n\n3.6 Data analysis\nData will be analysed using " . $p['data_analysis_methods'] . ".\n";
    }

    private function guidelines(): void
    {
        $admin = $this->users['gradadmin'];
        $docs = [
            ['Graduate Research Handbook (sample)', 'graduate_guideline', "Demo University sample handbook - not a real policy.\n\nChapter 1 Supervision\n\n1.1 Supervision meetings\nStudents and supervisors should meet at least once every month during the proposal and data collection stages, and a record of each meeting must be kept.\n\n1.2 Progress reports\nA progress report is due at the end of each semester.\n\nChapter 2 Proposal\n\n2.1 Proposal length\nMaster's proposals should not exceed 6,000 words excluding references.\n\n2.2 Proposal defence\nA proposal must be defended before a departmental panel before data collection begins.\n"],
            ['Research Ethics Policy (sample)', 'ethics_policy', "Demo University sample ethics policy - not a real policy.\n\n1 Ethics clearance\nAll research involving human participants requires clearance from the institutional research ethics committee before data collection starts.\n\n2 Informed consent\nParticipants must give informed consent. For participants under 18, consent from a parent or guardian is also required.\n\n3 Data protection\nResearchers must store personal data securely and anonymise data before sharing, in line with the Uganda Data Protection and Privacy Act, 2019.\n"],
            ['Referencing Guide (sample)', 'referencing_guideline', "Demo University sample referencing guide.\n\n1 Style\nThe university uses the APA 7th edition referencing style for all theses and dissertations.\n\n2 Citations\nEvery in-text citation must have a matching entry in the reference list, and every reference must be cited in the text.\n"],
            ['Methodology Requirements (sample)', 'research_manual', "Demo University sample methodology requirements.\n\n3.1 Sample size\nQuantitative studies must justify the sample size using a recognised method such as Krejcie and Morgan or Yamane, and state the confidence level and margin of error.\n\n3.2 Validity and reliability\nQuantitative instruments must report content validity and a reliability coefficient (for example Cronbach's alpha) from a pilot study. Qualitative studies must explain how trustworthiness is established.\n"],
        ];
        $uploads = [];
        foreach ($docs as [$title, $cat, $text]) {
            $path = tempnam(sys_get_temp_dir(), 'rmkb');
            file_put_contents($path, $text);
            $this->tmp[] = $path;
            $file = new UploadedFile($path, strtolower(preg_replace('/\W+/', '-', $title)) . '.txt', strlen($text), UPLOAD_ERR_OK, true);
            $req = new Request('POST', '/seed', [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], Session::array());
            $req->attributes['user'] = $admin;
            $doc = KnowledgeService::upload($req, $admin, ['title' => $title, 'category' => $cat], $file);
            KnowledgeService::approve($req, $admin, $doc);
        }
    }

    private function activity(): void
    {
        $sup = fn(int $n) => $this->users[['sup1', 'sup1', 'sup2', 'sup2', 'sup1', 'sup2', 'sup1', 'sup3', 'sup3', 'sup3'][$n - 1]];
        $reqFor = function (array $user) {
            $r = new Request('POST', '/seed', [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], Session::array());
            $r->attributes['user'] = $user;
            return $r;
        };
        // documents: proposals for projects 1-8, second versions, a dataset, a private draft
        $docs = [];
        for ($n = 1; $n <= 8; $n++) {
            $st = $this->users['student' . $n];
            $docs[$n] = $this->upload($st, $this->projects[$n], $n >= 3 ? 'chapter_3' : 'proposal', ($n >= 3 ? 'Chapter 3 draft' : 'Research proposal') . ' - ' . $st['last_name'], 'proposal-' . $n . '.txt', $this->sampleProposal($this->projects[$n]), null, ['description' => 'Demonstration sample']);
        }
        $this->upload($this->users['student1'], $this->projects[1], 'proposal', 'Research proposal - Mugisha', 'proposal-1-v2.txt', $this->sampleProposal($this->projects[1]) . "\nRevised in response to supervisor comments: the sampling section now cites a justification for the sample size.\n", $docs[1]['document'], ['change_note' => 'Addressed supervisor comments']);
        $this->upload($this->users['student2'], $this->projects[2], 'dataset', 'Pilot image metadata (sample)', 'pilot.csv', "image_id,disease,accuracy\n1,rust,0.91\n2,blight,0.88\n3,healthy,0.97\n4,rust,0.85\n5,blight,0.9\n", null);
        $this->upload($this->users['student3'], $this->projects[3], 'chapter_4', 'Chapter 4 rough notes (private)', 'notes.txt', "Private working notes - not shared with the supervisor yet.\n", null, ['visibility' => 'private']);
        $pdf = new \App\Support\SimplePdf('Sample proposal');
        $pdf->heading('Chapter 1 Introduction')->paragraph('1.2 Problem statement')->paragraph($this->projects[4]['problem_statement'])->pageBreak()->paragraph('3.4 Sampling design')->paragraph('A sample of respondents will be drawn using ' . $this->projects[4]['sampling_approach'] . '.');
        $this->upload($this->users['student4'], $this->projects[4], 'proposal', 'Research proposal (PDF) - Nabirye', 'proposal-4.pdf', $pdf->render());

        // constitutions for the more advanced projects; one pending proposal
        foreach ([2, 3, 4, 8, 9] as $n) {
            ConstitutionService::initialize($reqFor($sup($n)), $sup($n), $this->projects[$n], 'Approved at proposal defence (demonstration).');
        }
        ConstitutionService::propose($reqFor($this->users['student2']), $this->users['student2'], $this->projects[2], ['design' => 'Quasi-experimental design'], 'Pilot results suggest a quasi-experimental comparison is more suitable.');

        // AI reviews through the real pipeline (MOCK provider)
        foreach ([1, 2, 3, 4] as $n) {
            $st = $this->users['student' . $n];
            $doc = $docs[$n]['document'];
            AiReviewService::request($reqFor($st), $st, $this->projects[$n], $n === 3 ? 'methodology' : 'proposal', \App\Models\Document::find((int) $doc['id']));
        }
        $st = $this->users['student1'];
        AiReviewService::request($reqFor($st), $st, $this->projects[1], 'alignment', null);
        // one review decided by a human, to show the workflow
        $review = \App\Models\AiReview::first(['project_id' => $this->projects[1]['id'], 'review_type' => 'proposal']);
        if ($review) {
            AiReviewService::decide($reqFor($sup(1)), $sup(1), $review, 'modified', 'Agree with the sampling concern; the objectives comment is not applicable (demonstration).');
        }

        // meetings, tasks, comments
        foreach ([1, 2, 3, 4] as $n) {
            $s = $sup($n);
            $past = SupervisionService::createMeeting($reqFor($s), $s, $this->projects[$n], ['title' => 'Progress meeting', 'scheduled_at' => gmdate('c', strtotime('-14 days')), 'agenda_items' => ['Review draft', 'Agree next steps']]);
            \App\Models\SupervisionSession::updateById((int) $past['id'], ['discussion_notes' => 'Discussed the draft and the next milestone (demonstration notes).']);
            SupervisionService::recordOutcome($reqFor($s), $s, $this->projects[$n], \App\Models\SupervisionSession::find((int) $past['id']), 'Student to revise the methodology section and resubmit within two weeks.');
            $next = SupervisionService::createMeeting($reqFor($s), $s, $this->projects[$n], ['title' => 'Review of revisions', 'scheduled_at' => gmdate('c', strtotime('+' . (3 + $n) . ' days')), 'agenda_items' => ['Revised methodology']]);
            if ($n === 1) {
                SupervisionService::generateBrief($reqFor($s), $s, $this->projects[1], $next);
            }
        }
        $s1 = $this->users['sup1'];
        $st1 = $this->users['student1'];
        $overdue = SupervisionService::createTask($reqFor($s1), $s1, $this->projects[1], ['title' => 'Revise sampling section with a sample-size justification', 'due_date' => gmdate('Y-m-d', strtotime('-5 days'))]);
        SupervisionService::createTask($reqFor($s1), $s1, $this->projects[1], ['title' => 'Add three recent empirical studies to Chapter 2', 'due_date' => gmdate('Y-m-d', strtotime('+10 days'))]);
        $rev = SupervisionService::createTask($reqFor($s1), $s1, $this->projects[1], ['title' => 'Revise the problem statement', 'kind' => 'revision_request', 'document_id' => $docs[1]['document']['uuid'], 'due_date' => gmdate('Y-m-d', strtotime('+7 days'))]);
        SupervisionService::updateTask($reqFor($st1), $st1, $this->projects[1], \App\Models\SupervisionActionItem::find((int) $rev['id']), ['state' => 'submitted', 'student_response' => 'Revised version uploaded as version 2.']);
        $s2 = $this->users['sup2'];
        $t = SupervisionService::createTask($reqFor($s2), $s2, $this->projects[3], ['title' => 'Prepare Chapter 4 results tables', 'due_date' => gmdate('Y-m-d', strtotime('+14 days'))]);
        SupervisionService::updateTask($reqFor($this->users['student3']), $this->users['student3'], $this->projects[3], \App\Models\SupervisionActionItem::find((int) $t['id']), ['state' => 'in_progress']);
        $done = SupervisionService::createTask($reqFor($s2), $s2, $this->projects[4], ['title' => 'Submit ethics application', 'due_date' => gmdate('Y-m-d', strtotime('-20 days'))]);
        SupervisionService::updateTask($reqFor($s2), $s2, $this->projects[4], \App\Models\SupervisionActionItem::find((int) $done['id']), ['state' => 'completed']);
        \App\Models\SupervisionComment::create(['project_id' => $this->projects[1]['id'], 'author_id' => $s1['id'], 'origin' => 'supervisor', 'body' => 'Please make the research gap explicit and support it with citations.']);
    }

    private function cleanup(): void
    {
        foreach ($this->tmp as $p) {
            @unlink($p);
        }
    }

    public function credentials(): array
    {
        $out = [];
        foreach ($this->users as $key => $u) {
            $out[] = ['email' => $u['email'], 'name' => $u['name'], 'roles' => implode(', ', $u['roles'])];
        }
        return $out;
    }
}
