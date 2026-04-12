<?php

namespace App\Parsers;

class CommandParser
{
    /**
     * Parse a raw input line into a token array.
     *
     * Returns null for empty/whitespace-only lines.
     * Strips a standalone '#' token at position 3 or beyond (1-indexed),
     * along with everything after it. An embedded '#' within a token (e.g.
     * "REASON#CODE") is NOT treated as a comment delimiter.
     *
     * @return array<int, string>|null
     */
    public function parse(string $line): ?array
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        $tokens = preg_split('/\s+/', $line);
        $tokens = array_values(array_filter($tokens, fn (string $t) => $t !== ''));

        // Strip standalone '#' at 0-based index >= 2 (position 3+ in 1-indexed terms)
        foreach ($tokens as $index => $token) {
            if ($index >= 2 && $token === '#') {
                $tokens = array_slice($tokens, 0, $index);
                break;
            }
        }

        return empty($tokens) ? null : $tokens;
    }
}
