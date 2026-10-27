<?php
declare(strict_types=1);

namespace App\Services\AI;

/** JSON schemas + validators for non-review structured outputs. */
final class Schemas
{
    private static function o(array $p): array { return ['type' => 'object', 'properties' => $p, 'required' => array_keys($p), 'additionalProperties' => false]; }
    private static function s(): array { return ['type' => 'string']; }
    private static function l(): array { return ['type' => 'array', 'items' => ['type' => 'string']]; }

    public static function supervisionBrief(): array
    {
        return self::o([
            'summary' => self::s(), 'agenda_items' => self::l(), 'follow_up_questions' => self::l(), 'unresolved_issues' => self::l(),
            'suggested_actions' => ['type' => 'array', 'items' => self::o(['title' => self::s(), 'description' => self::s(), 'owner' => ['type' => 'string', 'enum' => ['student', 'supervisor', 'both']]])],
        ]);
    }

    public static function kbAnswer(): array
    {
        return self::o(['answered' => ['type' => 'boolean'], 'answer' => self::s(), 'source_ids' => self::l()]);
    }

    public static function vivaQuestion(): array
    {
        return self::o(['question' => self::s(), 'topic' => self::s()]);
    }

    public static function vivaFeedback(): array
    {
        return self::o(['feedback' => self::s(), 'strengths' => self::l(), 'improvements' => self::l(), 'follow_up_question' => self::s(), 'follow_up_topic' => self::s()]);
    }

    /** @param string[] $required */
    public static function requireKeys(array $data, array $required): void
    {
        $missing = array_filter($required, fn($k) => !array_key_exists($k, $data));
        if ($missing) {
            throw new AIInvalidResponse(array_map(fn($k) => "{$k} missing", $missing));
        }
    }

    public static function brief(array $d): array
    {
        self::requireKeys($d, ['summary']);
        $list = fn($v, $n, $m) => array_slice(array_values(array_filter(array_map(fn($x) => is_string($x) ? ReviewSchema::clip($x, $m) : '', is_array($v) ? $v : []))), 0, $n);
        $actions = [];
        foreach (is_array($d['suggested_actions'] ?? null) ? $d['suggested_actions'] : [] as $a) {
            if (is_array($a) && !empty($a['title']) && is_string($a['title'])) {
                $actions[] = ['title' => ReviewSchema::clip($a['title'], 300), 'description' => ReviewSchema::clip($a['description'] ?? '', 1000), 'owner' => in_array($a['owner'] ?? '', ['student', 'supervisor', 'both'], true) ? $a['owner'] : 'student'];
            }
        }
        return [
            'summary' => ReviewSchema::clip($d['summary'], 3000), 'agenda_items' => $list($d['agenda_items'] ?? [], 12, 300),
            'follow_up_questions' => $list($d['follow_up_questions'] ?? [], 12, 400), 'unresolved_issues' => $list($d['unresolved_issues'] ?? [], 15, 500),
            'suggested_actions' => array_slice($actions, 0, 12),
        ];
    }

    public static function kb(array $d, array $allowedIds): array
    {
        self::requireKeys($d, ['answered', 'answer', 'source_ids']);
        $ids = array_values(array_unique(array_filter(is_array($d['source_ids']) ? $d['source_ids'] : [], fn($i) => is_string($i) && in_array($i, $allowedIds, true))));
        $answered = (bool) $d['answered'];
        // An answer must cite retrieved sources; otherwise it is not accepted as an answer.
        if ($answered && !$ids) {
            $answered = false;
            return ['answered' => false, 'answer' => 'The AI did not cite a supplied guideline source, so no answer is given. Please read the sources listed below or consult the Graduate School.', 'source_ids' => []];
        }
        return ['answered' => $answered, 'answer' => ReviewSchema::clip($d['answer'], 4000), 'source_ids' => $ids];
    }

    public static function vivaQuestion_(array $d): array
    {
        self::requireKeys($d, ['question']);
        $q = ReviewSchema::clip($d['question'], 1500);
        if ($q === '') {
            throw new AIInvalidResponse(['question empty']);
        }
        return ['question' => $q, 'topic' => ReviewSchema::clip($d['topic'] ?? '', 250)];
    }

    public static function vivaFeedback_(array $d): array
    {
        self::requireKeys($d, ['feedback']);
        $list = fn($v) => array_slice(array_values(array_filter(array_map(fn($x) => is_string($x) ? ReviewSchema::clip($x, 500) : '', is_array($v) ? $v : []))), 0, 8);
        return [
            'feedback' => ReviewSchema::clip($d['feedback'], 3000), 'strengths' => $list($d['strengths'] ?? []), 'improvements' => $list($d['improvements'] ?? []),
            'follow_up_question' => ReviewSchema::clip($d['follow_up_question'] ?? '', 1500), 'follow_up_topic' => ReviewSchema::clip($d['follow_up_topic'] ?? '', 250),
        ];
    }
}
