<?php
declare(strict_types=1);

namespace App\Agents;

final class AgentRegistry
{
    public static function all(): array
    {
        return [
            'proposal' => new ProposalReviewAgent(), 'literature' => new LiteratureReviewAgent(), 'gap' => new ResearchGapAgent(),
            'methodology' => new MethodologyAuditor(), 'alignment' => new AlignmentAgent(), 'writing' => new AcademicWritingAgent(),
            'integrity' => new AcademicIntegrityAgent(), 'statistics' => new StatisticsAssistant(), 'constitution_check' => new ConstitutionCheckAgent(),
        ];
    }

    public static function for(string $type): ReviewAgent
    {
        return self::all()[$type] ?? throw new \InvalidArgumentException("No agent for review type {$type}");
    }
}
