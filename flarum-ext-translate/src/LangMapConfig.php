<?php

namespace Twikura\Translate;

class LangMapConfig
{
    /**
     * Parse the lang_map setting string into an associative array.
     *
     * Input format (one "code: name" per line):
     *   zh:Simplified Chinese
     *   ja:Japanese
     *   en:English
     *
     * Output: ['zh' => 'Simplified Chinese', 'ja' => 'Japanese', 'en' => 'English']
     *
     * Fault tolerance:
     *   - Empty lines are skipped.
     *   - Lines without a colon are skipped.
     *   - Leading/trailing whitespace around code and name is trimmed.
     */
    public static function parse(string $raw): array
    {
        $map   = [];
        $lines = explode("\n", $raw);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $pos = mb_strpos($line, ':');

            if ($pos === false) {
                continue;
            }

            $code = trim(mb_substr($line, 0, $pos));
            $name = trim(mb_substr($line, $pos + 1));

            if ($code === '' || $name === '') {
                continue;
            }

            $map[$code] = $name;
        }

        return $map;
    }

    /**
     * Parse and look up a single language name.
     *
     * Returns the human-readable name, or the code itself if not found.
     */
    public static function getName(string $raw, string $code): string
    {
        $map = self::parse($raw);

        return $map[$code] ?? $code;
    }
}
