<?php
declare(strict_types=1);

namespace App\Services\Retrieval;

/**
 * Retrieval abstraction. The MVP implements MySQL full-text/keyword retrieval; a semantic (embedding)
 * implementation can be added later without touching callers.
 */
interface RetrieverInterface
{
    /**
     * @param array{institution_id:int,access_levels:string[],category?:?string} $scope  institution scope is mandatory
     * @return array<int,array{chunk_id:int,document_uuid:string,title:string,category:string,version:string,page:?int,heading:?string,content:string,score:float}>
     */
    public function retrieve(string $query, array $scope, int $limit = 6): array;
}
