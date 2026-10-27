<?php
declare(strict_types=1);

namespace App\Services;

use App\Agents\VivaPreparationAgent;
use App\Models\{VivaSession};
use App\Policies\Gate;
use App\Services\AI\{AIException, AiGuard};
use App\Support\{Database, HttpException, Request, Uuid};

final class VivaService
{
    public const MAX_QUESTIONS = 12;

    public static function start(Request $r, array $user, array $project, string $mode): array
    {
        Gate::authorize($user, 'viva.start', $project);
        if (!isset(VivaPreparationAgent::MODES[$mode])) {
            throw HttpException::unprocessable('Unknown viva mode.', ['mode' => ['Choose a valid mode.']]);
        }
        $svc = AiGuard::assertAllowed($user, $project, 'viva');
        try {
            $q = VivaPreparationAgent::question($svc, $user, $project, $mode, []);
        } catch (AIException $e) {
            throw new HttpException(502, 'ai_failed', $e->getMessage());
        }
        $id = Database::transaction(function () use ($user, $project, $mode, $svc, $q) {
            $id = VivaSession::create(['project_id' => $project['id'], 'student_id' => $user['id'], 'mode' => $mode, 'provider' => $svc->provider(), 'is_mock' => $svc->isMock() ? 1 : 0]);
            Database::insert('viva_questions', ['uuid' => Uuid::v4(), 'session_id' => $id, 'seq' => 1, 'question' => $q['question'], 'topic' => $q['topic'] ?: null]);
            return $id;
        });
        $s = VivaSession::find($id);
        AuditLogger::log($r, 'viva.start', 'viva_session', $s['uuid'], ['project' => $project['uuid'], 'mode' => $mode]);
        return $s;
    }

    public static function answer(Request $r, array $user, array $project, array $session, string $questionUuid, string $answer): array
    {
        Gate::authorize($user, 'viva.view', $session);
        if ($session['status'] !== 'active') {
            throw HttpException::conflict('This practice session has ended.');
        }
        $q = Database::fetchOne('SELECT * FROM viva_questions WHERE uuid = ? AND session_id = ?', [$questionUuid, $session['id']]);
        if (!$q) {
            throw HttpException::notFound('Question');
        }
        if (Database::fetchValue('SELECT COUNT(*) FROM viva_answers WHERE question_id = ?', [$q['id']])) {
            throw HttpException::conflict('This question has already been answered.');
        }
        $svc = AiGuard::assertAllowed($user, $project, 'viva');
        try {
            $fb = VivaPreparationAgent::feedback($svc, $user, $project, $session['mode'], $q['question'], $answer);
        } catch (AIException $e) {
            throw new HttpException(502, 'ai_failed', $e->getMessage());
        }
        $nextSeq = (int) $q['seq'] + 1;
        $followUp = null;
        Database::transaction(function () use ($q, $answer, $fb, $session, $nextSeq, &$followUp) {
            $aid = Database::insert('viva_answers', ['question_id' => $q['id'], 'answer' => $answer]);
            Database::insert('viva_feedback', ['answer_id' => $aid, 'feedback' => $fb['feedback'], 'strengths' => json_encode($fb['strengths']), 'improvements' => json_encode($fb['improvements'])]);
            if ($fb['follow_up_question'] !== '' && $nextSeq <= self::MAX_QUESTIONS) {
                $uuid = Uuid::v4();
                Database::insert('viva_questions', ['uuid' => $uuid, 'session_id' => $session['id'], 'seq' => $nextSeq, 'question' => $fb['follow_up_question'], 'topic' => $fb['follow_up_topic'] ?: null, 'is_follow_up' => 1]);
                $followUp = ['id' => $uuid, 'seq' => $nextSeq, 'question' => $fb['follow_up_question'], 'topic' => $fb['follow_up_topic'], 'is_follow_up' => true];
            }
        });
        return [
            'feedback' => ['text' => $fb['feedback'], 'strengths' => $fb['strengths'], 'improvements' => $fb['improvements'], 'origin' => 'ai_suggestion', 'label' => 'Formative AI feedback - not a mark or an examiner\'s judgement'],
            'next_question' => $followUp, 'session_full' => $followUp === null,
            'notice' => VivaPreparationAgent::NOTICE,
        ];
    }

    public static function end(Request $r, array $user, array $session): array
    {
        Gate::authorize($user, 'viva.view', $session);
        if ($session['status'] === 'active') {
            $answered = (int) Database::fetchValue('SELECT COUNT(*) FROM viva_answers a JOIN viva_questions q ON q.id = a.question_id WHERE q.session_id = ?', [$session['id']]);
            $total = (int) Database::fetchValue('SELECT COUNT(*) FROM viva_questions WHERE session_id = ?', [$session['id']]);
            VivaSession::updateById((int) $session['id'], ['status' => 'completed', 'ended_at' => gmdate('Y-m-d H:i:s'), 'summary' => "Practice session ended: {$answered} of {$total} question(s) answered. This is practice history, not an examination result."]);
            AuditLogger::log($r, 'viva.end', 'viva_session', $session['uuid'], ['answered' => $answered]);
        }
        return VivaSession::find((int) $session['id']);
    }

    public static function present(array $s, bool $full = true): array
    {
        $label = VivaPreparationAgent::MODES[$s['mode']][0];
        $out = [
            'id' => $s['uuid'], 'mode' => $s['mode'], 'mode_label' => $label, 'status' => $s['status'], 'started_at' => $s['started_at'], 'ended_at' => $s['ended_at'], 'summary' => $s['summary'],
            'practice' => true, 'notice' => VivaPreparationAgent::NOTICE, 'mock' => (bool) $s['is_mock'],
        ];
        if ($full) {
            $rows = Database::fetchAll(
                'SELECT q.uuid, q.seq, q.question, q.topic, q.is_follow_up, a.answer, f.feedback, f.strengths, f.improvements FROM viva_questions q
                 LEFT JOIN viva_answers a ON a.question_id = q.id LEFT JOIN viva_feedback f ON f.answer_id = a.id WHERE q.session_id = ? ORDER BY q.seq', [$s['id']]
            );
            $out['exchanges'] = array_map(fn($x) => [
                'id' => $x['uuid'], 'seq' => (int) $x['seq'], 'question' => $x['question'], 'topic' => $x['topic'], 'is_follow_up' => (bool) $x['is_follow_up'], 'answer' => $x['answer'],
                'feedback' => $x['feedback'] === null ? null : ['text' => $x['feedback'], 'strengths' => json_decode((string) $x['strengths'], true) ?: [], 'improvements' => json_decode((string) $x['improvements'], true) ?: [], 'origin' => 'ai_suggestion'],
            ], $rows);
        }
        return $out;
    }
}
