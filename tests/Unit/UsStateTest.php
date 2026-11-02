<?php

namespace Tests\Unit;

use App\Support\UsState;
use PHPUnit\Framework\TestCase;

class UsStateTest extends TestCase
{
    public function test_full_state_names_become_codes(): void
    {
        $this->assertSame('FL', UsState::toCode('Florida'));
        $this->assertSame('FL', UsState::toCode('  florida '));
        $this->assertSame('NY', UsState::toCode('New   York'));
        $this->assertSame('DC', UsState::toCode('District of Columbia'));
    }

    public function test_codes_are_kept_and_uppercased(): void
    {
        $this->assertSame('FL', UsState::toCode('FL'));
        $this->assertSame('FL', UsState::toCode('fl'));
    }

    public function test_unknown_values_are_left_for_validation(): void
    {
        $this->assertSame('Floridaa', UsState::toCode('Floridaa'));
        $this->assertSame('', UsState::toCode(null));
    }
}
