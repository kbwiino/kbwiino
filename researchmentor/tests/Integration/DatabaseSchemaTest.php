<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Models\{Document, DocumentVersion, Institution, ResearchProject, User};
use App\Support\Database;
use Tests\Support\{Factory, Fixtures};
use Tests\TestCase;

final class DatabaseSchemaTest extends TestCase
{
    private const REQUIRED_TABLES = [
        'institutions', 'faculties', 'departments', 'programmes', 'academic_years', 'users', 'roles', 'user_roles',
        'research_projects', 'research_project_members', 'research_constitutions', 'research_objectives', 'research_questions', 'research_hypotheses',
        'research_variables', 'research_theories', 'conceptual_frameworks', 'research_milestones', 'research_stage_history',
        'documents', 'document_versions', 'document_comments', 'document_access_permissions', 'document_extractions',
        'ai_review_jobs', 'ai_reviews', 'ai_review_issues', 'ai_usage_logs', 'ai_conversations', 'ai_messages', 'prompt_templates',
        'literature_sources', 'literature_evidence', 'source_verification_records', 'project_literature',
        'supervision_sessions', 'supervision_agendas', 'supervision_action_items', 'supervision_comments', 'supervision_approvals',
        'knowledge_bases', 'knowledge_documents', 'knowledge_document_chunks', 'knowledge_retrieval_logs',
        'viva_sessions', 'viva_questions', 'viva_answers', 'viva_feedback',
        'notifications', 'audit_logs', 'system_settings', 'background_jobs',
    ];

    public function testEveryRequiredTableExistsAsInnoDbUtf8mb4(): void
    {
        $db = \App\Support\Config::get('database.database');
        $rows = Database::fetchAll('SELECT TABLE_NAME t, ENGINE e, TABLE_COLLATION c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$db]);
        $byName = array_column($rows, null, 't');
        foreach (self::REQUIRED_TABLES as $t) {
            $this->assertArrayHasKey($t, $byName, "missing table {$t}");
            $this->assertSame('InnoDB', $byName[$t]['e'], "{$t} must be InnoDB");
            $this->assertStringStartsWith('utf8mb4', $byName[$t]['c'], "{$t} must be utf8mb4");
        }
    }

    public function testExposedEntitiesHaveUniqueOpaqueIds(): void
    {
        $db = \App\Support\Config::get('database.database');
        foreach (['institutions', 'users', 'research_projects', 'documents', 'document_versions', 'ai_reviews', 'ai_review_issues', 'supervision_sessions', 'supervision_action_items', 'research_constitutions', 'viva_sessions', 'knowledge_documents', 'notifications'] as $t) {
            $col = Database::fetchOne("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH len FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'uuid'", [$db, $t]);
            $this->assertNotNull($col, "{$t}.uuid");
            $this->assertSame(36, (int) $col['len']);
            $idx = Database::fetchOne("SELECT COUNT(*) n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'uuid' AND NON_UNIQUE = 0", [$db, $t]);
            $this->assertGreaterThan(0, (int) $idx['n'], "{$t}.uuid must be unique");
        }
    }

    public function testForeignKeyConstraintsAreDeclaredWithDeletionPolicies(): void
    {
        $db = \App\Support\Config::get('database.database');
        $fks = Database::fetchAll('SELECT TABLE_NAME t, DELETE_RULE d FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ?', [$db]);
        $this->assertGreaterThan(100, count($fks));
        $by = [];
        foreach ($fks as $f) $by[$f['t']][] = $f['d'];
        foreach (['research_projects', 'documents', 'document_versions', 'ai_reviews', 'supervision_action_items', 'research_project_members', 'knowledge_document_chunks', 'viva_answers'] as $t) {
            $this->assertArrayHasKey($t, $by, "{$t} must have foreign keys");
        }
        // research content cascades from its project; people are protected from accidental deletion
        $rules = array_column(Database::fetchAll("SELECT CONSTRAINT_NAME n, DELETE_RULE d FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_NAME IN ('fk_documents_project','fk_projects_owner','fk_users_institution')", [$db]), 'd', 'n');
        $this->assertSame('CASCADE', $rules['fk_documents_project']);
        $this->assertSame('RESTRICT', $rules['fk_projects_owner']);
        $this->assertSame('RESTRICT', $rules['fk_users_institution']);
    }

    public function testForeignKeysAreEnforcedByTheDatabase(): void
    {
        $this->expectException(\PDOException::class);
        Database::insert('research_project_members', ['project_id' => 999999, 'user_id' => 999999, 'member_role' => 'student']);
    }

    public function testDeletingAProjectCascadesToItsContentButNotToPeople(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $d = $this->assertOk($this->call('POST', '/api/documents/upload', ['project_id' => $a['project']['uuid']], [], Fixtures::txt()));
        $this->assertOk($this->postJson('/api/projects/' . $a['project']['uuid'] . '/objectives', ['body' => 'x']));
        $this->assertOk($this->postJson('/api/ai/writing-review', ['project_id' => $a['project']['uuid'], 'document_id' => $d['id']]));
        $docId = Document::findByUuid($d['id'])['id'];
        $this->assertGreaterThan(0, (int) Database::fetchValue('SELECT COUNT(*) FROM document_extractions e JOIN document_versions v ON v.id = e.version_id WHERE v.document_id = ?', [$docId]));
        Database::execute('DELETE FROM research_projects WHERE id = ?', [$a['project']['id']]);
        $this->assertSame(0, Document::count(['project_id' => $a['project']['id']]));
        $this->assertSame(0, DocumentVersion::count(['document_id' => $docId]));
        $this->assertSame(0, (int) Database::fetchValue('SELECT COUNT(*) FROM ai_reviews WHERE project_id = ?', [$a['project']['id']]));
        $this->assertSame(0, (int) Database::fetchValue('SELECT COUNT(*) FROM research_objectives WHERE project_id = ?', [$a['project']['id']]));
        $this->assertNotNull(User::find($a['student']['id']), 'people survive project deletion');
    }

    public function testUsersAndInstitutionsAreProtectedFromDeletionWhileInUse(): void
    {
        $a = $this->scenario();
        try {
            Database::execute('DELETE FROM users WHERE id = ?', [$a['student']['id']]);
            $this->fail('student owns a project: deletion must be refused');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('foreign key', strtolower($e->getMessage()));
        }
        $this->expectException(\PDOException::class);
        Database::execute('DELETE FROM institutions WHERE id = ?', [$a['org']['institution']['id']]);
    }

    public function testTimestampsAreStoredInUtc(): void
    {
        $this->assertSame('+00:00', Database::fetchValue('SELECT @@session.time_zone'));
        $org = Factory::institution();
        $created = Institution::find($org['institution']['id'])['created_at'];
        $this->assertEqualsWithDelta(time(), strtotime($created . ' UTC'), 5);
    }

    public function testIndexesExistOnHotQueryPaths(): void
    {
        $db = \App\Support\Config::get('database.database');
        $need = ['research_projects' => ['ix_projects_institution_stage', 'ix_projects_owner'], 'supervision_action_items' => ['ix_tasks_assignee', 'ix_tasks_project'], 'audit_logs' => ['ix_audit_entity', 'ix_audit_user'], 'notifications' => ['ix_notifications_user'], 'knowledge_document_chunks' => ['ft_chunks', 'ix_chunks_institution'], 'document_versions' => ['uq_versions_number']];
        foreach ($need as $t => $names) {
            $have = array_column(Database::fetchAll('SELECT DISTINCT INDEX_NAME i FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]), 'i');
            foreach ($names as $n) $this->assertContains($n, $have, "{$t} needs index {$n}");
        }
    }

    public function testMigrationsAreTrackedAndIdempotent(): void
    {
        $m = new \App\Support\Migrator();
        $status = $m->status();
        $this->assertNotEmpty($status);
        $this->assertNotContains(false, $status, 'all migrations applied');
        $this->assertSame([], $m->migrate(), 'running migrations again changes nothing');
    }

    public function testRolesAreSeededByMigration(): void
    {
        $slugs = array_column(Database::fetchAll('SELECT slug FROM roles'), 'slug');
        sort($slugs);
        $this->assertSame(['coordinator', 'external_examiner', 'grad_admin', 'student', 'supervisor', 'sysadmin'], $slugs);
    }
}
