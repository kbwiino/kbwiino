<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Models\PromptTemplate;
use App\Support\{Config, Database};

/**
 * Prompt templates live in resources/prompts/*.md (version controlled) and may be overridden by an
 * active row in prompt_templates (administrator-managed, versioned). {{placeholders}} are substituted
 * in a single pass so user text can never inject further placeholders.
 */
final class PromptManager
{
    /** @return array{system:string,user:string,version:string} */
    public static function render(string $key, array $vars = []): array
    {
        $tpl = self::load($key);
        $map = [];
        foreach ($vars as $k => $v) {
            // "<name>_raw" variables are markup built by this application (already neutralised inside); all others are untrusted text.
            if (str_ends_with($k, '_raw')) {
                $map['{{' . substr($k, 0, -4) . '}}'] = (string) $v;
            } else {
                $map['{{' . $k . '}}'] = self::neutralise((string) $v);
            }
        }
        $user = strtr($tpl['user'], $map);
        // Remove placeholders that were not supplied (e.g. optional blocks).
        $user = preg_replace('/\{\{[a-z_]+\}\}/', '', $user) ?? $user;
        $system = self::common() . "\n\n" . strtr($tpl['system'], $map);
        return ['system' => trim($system), 'user' => trim(preg_replace("/\n{3,}/", "\n\n", $user) ?? $user), 'version' => $tpl['version']];
    }

    /** Delimiter tags used in our templates. User text must not be able to close or open them. */
    private const TAGS = ['document', 'project', 'constitution', 'sources', 'source', 'partial_reviews', 'alignment_skeleton', 'computed_summary', 'history', 'question', 'answer', 'activity'];

    public static function neutralise(string $text): string
    {
        return preg_replace('#<(/?)(' . implode('|', self::TAGS) . ')(\b[^>]*)>#i', '&lt;$1$2$3&gt;', $text) ?? $text;
    }

    public static function common(): string
    {
        return trim((string) file_get_contents(Config::basePath('resources/prompts/_common.md')));
    }

    /** @return array{system:string,user:string,version:string} */
    public static function load(string $key): array
    {
        $row = Database::fetchOne('SELECT * FROM prompt_templates WHERE template_key = ? AND is_active = 1 ORDER BY version DESC LIMIT 1', [$key]);
        if ($row) {
            return ['system' => $row['system_prompt'], 'user' => $row['user_template'], 'version' => 'db:' . $row['version']];
        }
        return self::fromFile($key);
    }

    public static function fromFile(string $key): array
    {
        if (!preg_match('/^[a-z0-9_.]+$/', $key)) {
            throw new \InvalidArgumentException('Bad prompt key');
        }
        $path = Config::basePath("resources/prompts/{$key}.md");
        if (!is_file($path)) {
            throw new \RuntimeException("Prompt template missing: {$key}");
        }
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^---.*?---\s*/s', '', $raw, 1) ?? $raw;
        if (!preg_match('/## SYSTEM\s*(.*?)\s*## USER\s*(.*)$/s', $raw, $m)) {
            throw new \RuntimeException("Prompt template malformed: {$key}");
        }
        return ['system' => trim($m[1]), 'user' => trim($m[2]), 'version' => 'file'];
    }

    /** Import file templates into prompt_templates (inactive duplicates are not created). */
    public static function sync(): int
    {
        $n = 0;
        foreach (glob(Config::basePath('resources/prompts/*.md')) ?: [] as $file) {
            $key = basename($file, '.md');
            if ($key[0] === '_') {
                continue;
            }
            $tpl = self::fromFile($key);
            $existing = PromptTemplate::first(['template_key' => $key]);
            if ($existing) {
                continue; // administrators own subsequent versions
            }
            PromptTemplate::create(['template_key' => $key, 'version' => 1, 'description' => 'Imported from file', 'system_prompt' => $tpl['system'], 'user_template' => $tpl['user'], 'is_active' => 1]);
            $n++;
        }
        return $n;
    }
}
