<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HttpException;
use App\Support\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testValidDataPassesAndIsCast(): void
    {
        $out = Validator::make(['n' => '5', 'e' => ' a@b.co ', 'f' => 'true'], ['n' => 'required|int|min:1|max:10', 'e' => 'required|email', 'f' => 'bool'])->validate();
        $this->assertSame(5, $out['n']);
        $this->assertSame('a@b.co', $out['e']);
        $this->assertTrue($out['f']);
    }

    public function testUnknownKeysAreDropped(): void
    {
        $out = Validator::make(['a' => 'x', 'is_admin' => 1], ['a' => 'required|string'])->validate();
        $this->assertSame(['a' => 'x'], $out);
    }

    public function testErrorsAreCollected(): void
    {
        try {
            Validator::make(['e' => 'nope', 's' => 'zzz'], ['e' => 'required|email', 's' => 'in:a,b', 'r' => 'required'])->validate();
            $this->fail('expected exception');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->status);
            $this->assertArrayHasKey('e', $e->details);
            $this->assertArrayHasKey('s', $e->details);
            $this->assertArrayHasKey('r', $e->details);
        }
    }

    public function testStringLengthLimits(): void
    {
        $this->expectException(HttpException::class);
        Validator::make(['t' => str_repeat('a', 11)], ['t' => 'string|max:10'])->validate();
    }

    public function testNullableAllowsEmpty(): void
    {
        $out = Validator::make(['d' => ''], ['d' => 'nullable|date'])->validate();
        $this->assertNull($out['d']);
    }
}
