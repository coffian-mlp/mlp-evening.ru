<?php
namespace LLM;

/**
 * Snowball Russian, self-contained PHP implementation of the published algorithm.
 * Independent PHP port for this project; not part of the Snowball distribution.
 * Source: https://snowballstem.org/algorithms/russian/stemmer.html
 * Algorithm: https://github.com/snowballstem/snowball/blob/master/algorithms/russian.sbl
 * SPDX-License-Identifier: BSD-3-Clause
 * Upstream COPYING: snowballstem/snowball@6389c79f91cd67ed2de3ebdcf84ec8c2d2d23c11
 *
 * Copyright (c) 2001, Dr Martin Porter
 * Copyright (c) 2004,2005, Richard Boulton
 * Copyright (c) 2013, Yoshiki Shibukawa
 * Copyright (c) 2006-2025, Olly Betts
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   1. Redistributions of source code must retain the above copyright notice,
 *      this list of conditions and the following disclaimer.
 *   2. Redistributions in binary form must reproduce the above copyright notice,
 *      this list of conditions and the following disclaimer in the documentation
 *      and/or other materials provided with the distribution.
 *   3. Neither the name of the Snowball project nor the names of its contributors
 *      may be used to endorse or promote products derived from this software
 *      without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */
final class RussianStemmer
{
    private const VOWELS = 'аеиоуыэюя';
    private static array $cache = [];

    public static function stem(string $word): string
    {
        $word = str_replace('ё', 'е', mb_strtolower($word));
        if (!preg_match('/^[а-я]+$/u', $word)) return $word;
        if (isset(self::$cache[$word])) return self::$cache[$word];
        $v = self::VOWELS;
        if (!preg_match('/['.$v.']/u', $word, $first, PREG_OFFSET_CAPTURE)) return $word;
        $rvStart = $first[0][1] + strlen($first[0][0]);
        $r1 = self::region($word, 0);
        $r2 = self::region($word, $r1);
        $rv = substr($word, $rvStart);

        if (!self::remove($rv, '/(?:ившись|ывшись|ивши|ывши|ив|ыв|(?<=[ая])(?:вшись|вши|в))$/u')) {
            self::remove($rv, '/(?:ся|сь)$/u');
            if (self::remove($rv, '/(?:ими|ыми|его|ого|ему|ому|ее|ие|ые|ое|ей|ий|ый|ой|ем|им|ым|ом|их|ых|ую|юю|ая|яя|ою|ею)$/u')) {
                self::remove($rv, '/(?:ивш|ывш|ующ|(?<=[ая])(?:ем|нн|вш|ющ|щ))$/u');
            } elseif (!self::remove($rv, '/(?:ейте|уйте|ила|ыла|ена|ите|или|ыли|ило|ыло|ено|ует|уют|ены|ить|ыть|ишь|ила|ена|ей|уй|ил|ыл|им|ым|ен|ят|ит|ыт|ую|ю|(?<=[ая])(?:нно|ете|йте|ешь|ла|на|ли|ло|но|ет|ют|ны|ть|й|л|ем|н))$/u')) {
                self::remove($rv, '/(?:иями|ией|иям|ием|иях|ев|ов|ие|ье|ями|ами|еи|ии|ей|ой|ий|ям|ем|ам|ом|ах|ях|ию|ью|ия|ья|а|е|и|й|о|у|ы|ь|ю|я)$/u');
            }
        }
        self::remove($rv, '/и$/u');
        if (preg_match('/(?:ость|ост)$/u', $rv, $suffix, PREG_OFFSET_CAPTURE) && $rvStart + $suffix[0][1] >= $r2) {
            $rv = substr($rv, 0, $suffix[0][1]);
        }
        if (!self::remove($rv, '/(?<=н)н$/u')) {
            if (self::remove($rv, '/(?:ейше|ейш)$/u')) self::remove($rv, '/(?<=н)н$/u');
            else self::remove($rv, '/ь$/u');
        }
        if (count(self::$cache) >= 8192) self::$cache = [];
        return self::$cache[$word] = substr($word, 0, $rvStart).$rv;
    }

    private static function region(string $word, int $start): int
    {
        $v = self::VOWELS;
        return preg_match('/['.$v.'][^'.$v.']/u', $word, $match, PREG_OFFSET_CAPTURE, $start)
            ? $match[0][1] + strlen($match[0][0]) : strlen($word);
    }

    private static function remove(string &$region, string $pattern): bool
    {
        if (!preg_match($pattern, $region, $match, PREG_OFFSET_CAPTURE)) return false;
        $region = substr($region, 0, $match[0][1]);
        return true;
    }
}
