<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\Factory;
use Tests\TestCase;

final class LiteratureTest extends TestCase
{
    public function testRegisterVerifyAndEvidence(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $pid = $a['project']['uuid'];
        $s = $this->assertOk($this->postJson("/api/projects/$pid/literature", ['title' => 'Mobile money and savings', 'authors' => 'Jack W., Suri T.', 'year' => 2016, 'doi' => '10.1257/aer.104.1.183', 'literature_type' => 'empirical']));
        $this->assertSame('unverified', $s['verification_status'], 'sources start unverified');
        $this->assertError($this->postJson("/api/projects/$pid/literature", ['title' => 'Dup', 'authors' => 'x', 'doi' => '10.1257/AER.104.1.183']), 409);
        $this->assertError($this->postJson("/api/projects/$pid/literature", ['title' => 'Bad', 'authors' => 'x', 'doi' => 'not-a-doi']), 422);
        $this->assertError($this->postJson("/api/projects/$pid/literature", ['title' => 'Bad', 'authors' => 'x', 'url' => 'javascript:alert(1)']), 422);
        $v = $this->assertOk($this->postJson("/api/projects/$pid/literature/{$s['id']}/verify", ['method' => 'doi_lookup', 'outcome' => 'verified', 'notes' => 'Resolved at doi.org']));
        $this->assertSame('verified', $v['verification_status']);
        $this->assertSame('doi_lookup', $v['last_verification']['method']);
        $e = $this->assertOk($this->postJson("/api/projects/$pid/literature/evidence", ['source_id' => $s['id'], 'evidence_type' => 'empirical', 'excerpt' => 'Mobile money users saved more.', 'page' => 12]));
        $this->assertCount(1, $this->assertOk($this->getJson("/api/projects/$pid/literature/evidence")));
        $list = $this->assertOk($this->getJson("/api/projects/$pid/literature"));
        $this->assertCount(1, $list);
    }

    public function testLiteratureIsPrivateToProjectMembers(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $s = $this->assertOk($this->postJson('/api/projects/' . $a['project']['uuid'] . '/literature', ['title' => 'T', 'authors' => 'A']));
        $other = Factory::user($a['org'], 'student');
        Factory::project($a['org'], $other, $a['sup']);
        $this->actingAs($other);
        $this->assertError($this->getJson('/api/projects/' . $a['project']['uuid'] . '/literature'), 404);
        $this->assertError($this->postJson('/api/projects/' . $a['project']['uuid'] . '/literature/' . $s['id'] . '/verify', ['method' => 'manual_inspection', 'outcome' => 'verified']), 404);
        $this->actingAs(Factory::user($a['org'], 'coordinator'));
        $this->assertError($this->getJson('/api/projects/' . $a['project']['uuid'] . '/literature'), 403);
    }
}
