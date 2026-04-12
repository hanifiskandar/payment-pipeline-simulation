<?php

namespace Tests\Unit;

use App\Parsers\CommandParser;
use PHPUnit\Framework\TestCase;

class CommandParserTest extends TestCase
{
    private CommandParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CommandParser;
    }

    public function test_empty_string_returns_null(): void
    {
        $this->assertNull($this->parser->parse(''));
    }

    public function test_whitespace_only_returns_null(): void
    {
        $this->assertNull($this->parser->parse('   '));
    }

    public function test_valid_create_command_parses_correctly(): void
    {
        $result = $this->parser->parse('CREATE P1001 10.00 MYR M01');

        $this->assertSame(['CREATE', 'P1001', '10.00', 'MYR', 'M01'], $result);
    }

    public function test_inline_comment_at_position_6_is_stripped(): void
    {
        $result = $this->parser->parse('CREATE P1001 10.00 MYR M01 # this is a comment');

        $this->assertSame(['CREATE', 'P1001', '10.00', 'MYR', 'M01'], $result);
    }

    public function test_hash_at_position_1_is_not_a_comment(): void
    {
        // '#' is the first token — not treated as comment, becomes unknown command
        $result = $this->parser->parse('# CREATE P1001 10.00 MYR M01');

        $this->assertSame(['#', 'CREATE', 'P1001', '10.00', 'MYR', 'M01'], $result);
    }

    public function test_hash_at_position_3_is_stripped(): void
    {
        // AUTHORIZE (pos 1), P1001 (pos 2), # (pos 3) — stripped
        $result = $this->parser->parse('AUTHORIZE P1001 # retry');

        $this->assertSame(['AUTHORIZE', 'P1001'], $result);
    }

    public function test_hash_at_position_2_is_not_stripped(): void
    {
        // AUTHORIZE (pos 1), # (pos 2) — NOT stripped (pos < 3)
        $result = $this->parser->parse('AUTHORIZE # P1001');

        $this->assertSame(['AUTHORIZE', '#', 'P1001'], $result);
    }

    public function test_embedded_hash_in_token_is_not_stripped(): void
    {
        // REASON#CODE is a single token — # is not standalone
        $result = $this->parser->parse('VOID P1001 REASON#CODE');

        $this->assertSame(['VOID', 'P1001', 'REASON#CODE'], $result);
    }

    public function test_single_command_without_args(): void
    {
        $result = $this->parser->parse('LIST');

        $this->assertSame(['LIST'], $result);
    }

    public function test_extra_whitespace_is_normalised(): void
    {
        $result = $this->parser->parse('  EXIT  ');

        $this->assertSame(['EXIT'], $result);
    }

    public function test_hash_exactly_at_position_3_with_nothing_after(): void
    {
        $result = $this->parser->parse('AUTHORIZE P1001 #');

        $this->assertSame(['AUTHORIZE', 'P1001'], $result);
    }
}
