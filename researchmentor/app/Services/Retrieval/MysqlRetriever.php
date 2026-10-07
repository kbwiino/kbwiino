<?php
declare(strict_types=1);

namespace App\Services\Retrieval;

use App\Support\Database;

/**
 * MySQL InnoDB FULLTEXT (natural language) plus a keyword-overlap fallback.
 * The fallback covers short tokens, stop words and rows that the full-text index cannot see yet.
 * Every query is hard-scoped to one institution and to approved, access-permitted documents.
 */
final class MysqlRetriever implements RetrieverInterface
{
    private const STOP = ['the', 'and', 'for', 'are', 'what', 'how', 'can', 'does', 'must', 'should', 'with', 'that', 'this', 'from', 'have', 'will', 'was', 'were', 'which', 'about', 'when', 'who', 'where', 'there', 'their', 'your', 'our', 'any', 'all', 'not', 'but', 'its'];

    public function retrieve(string $query, array $scope, int $limit = 6): array
    {
        $institutionId = (int) ($scope['institution_id'] ?? 0);
        if ($institutionId <= 0) {
            throw new \InvalidArgumentException('Retrieval requires an institution scope.');
        }
        $levels = $scope['access_levels'] ?: ['all_users'];
        $tokens = self::tokens($query);
        if (!$tokens) {
            return [];
        }
        $base = 'FROM knowledge_document_chunks c JOIN knowledge_documents d ON d.id = c.knowledge_document_id
                 WHERE c.institution_id = ? AND d.institution_id = ? AND d.status = \'approved\' AND d.access_level IN (' . Database::placeholders($levels) . ')';
        $params = array_merge([$institutionId, $institutionId], $levels);
        if (!empty($scope['category'])) {
            $base .= ' AND d.category = ?';
            $params[] = $scope['category'];
        }
        $cols = 'c.id AS chunk_id, d.uuid AS document_uuid, d.title, d.category, d.version_label, c.page_number, c.heading, c.content';

        $results = [];
        // 1) full-text
        $boolean = implode(' ', array_map(fn($t) => $t, $tokens));
        $rows = Database::fetchAll("SELECT $cols, MATCH(c.heading, c.content) AGAINST (? IN NATURAL LANGUAGE MODE) AS score $base AND MATCH(c.heading, c.content) AGAINST (? IN NATURAL LANGUAGE MODE) ORDER BY score DESC LIMIT " . (int) $limit, array_merge([$boolean], $params, [$boolean]));
        foreach ($rows as $r) {
            $results[(int) $r['chunk_id']] = self::shape($r, (float) $r['score']);
        }
        // 2) keyword overlap fallback
        if (count($results) < $limit) {
            $likeParts = [];
            $likeParams = [];
            foreach ($tokens as $t) {
                $likeParts[] = '(c.content LIKE ? OR IFNULL(c.heading, "") LIKE ?)';
                $like = '%' . addcslashes($t, '%_\\') . '%';
                array_push($likeParams, $like, $like);
            }
            $scoreExpr = implode(' + ', array_map(fn($p) => "($p)", $likeParts));
            $rows = Database::fetchAll(
                "SELECT $cols, ($scoreExpr) AS hits $base AND (" . implode(' OR ', $likeParts) . ') ORDER BY hits DESC, c.id LIMIT ' . (int) ($limit * 3),
                array_merge($likeParams, $params, $likeParams)
            );
            foreach ($rows as $r) {
                $id = (int) $r['chunk_id'];
                if (!isset($results[$id]) && count($results) < $limit) {
                    // Scale below genuine full-text scores; require roughly a third of the query terms to be present.
                    if ((int) $r['hits'] >= max(1, (int) ceil(count($tokens) / 3))) {
                        $results[$id] = self::shape($r, (float) $r['hits'] / count($tokens));
                    }
                }
            }
        }
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_values(array_slice($results, 0, $limit));
    }

    /** @return string[] */
    public static function tokens(string $q): array
    {
        $q = mb_strtolower($q);
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\-]{1,}/u', $q, $m);
        $out = [];
        foreach ($m[0] as $t) {
            if (mb_strlen($t) >= 3 && !in_array($t, self::STOP, true)) {
                $out[$t] = $t;
            }
        }
        return array_slice(array_values($out), 0, 12);
    }

    private static function shape(array $r, float $score): array
    {
        return [
            'chunk_id' => (int) $r['chunk_id'], 'document_uuid' => $r['document_uuid'], 'title' => $r['title'], 'category' => $r['category'], 'version' => $r['version_label'],
            'page' => $r['page_number'] !== null ? (int) $r['page_number'] : null, 'heading' => $r['heading'], 'content' => $r['content'], 'score' => $score,
        ];
    }
}
