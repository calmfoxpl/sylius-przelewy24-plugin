<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every key the code and the template ask for has a sentence in both languages.
 *
 * A missing one is not an error anywhere — Symfony shows the key, and a shopkeeper reads
 * `calmfox_przelewy24.form.crc` instead of "CRC key". The files are flat and dotted, so they are
 * read line by line rather than with a YAML parser, which keeps this runnable without vendor.
 */
final class TranslationsTest extends TestCase
{
    private const DOMAINS = ['messages', 'flashes', 'validators'];

    private const LOCALES = ['en', 'pl'];

    public function testBothLanguagesHaveTheSameKeys(): void
    {
        foreach (self::DOMAINS as $domain) {
            self::assertSame(
                array_keys(self::entries($domain, 'en')),
                array_keys(self::entries($domain, 'pl')),
                sprintf('%s.en.yaml and %s.pl.yaml differ', $domain, $domain),
            );
        }
    }

    public function testEveryKeyTheCodeUsesIsTranslated(): void
    {
        $known = [];
        foreach (self::DOMAINS as $domain) {
            $known += self::entries($domain, 'en');
        }

        // The code and the template, plus the gateway label, which Sylius reads from services.yaml.
        $sources = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src')) as $file) {
            if ($file->isFile()) {
                $sources[] = $file->getPathname();
            }
        }
        $sources = array_merge($sources, glob(\dirname(__DIR__, 2) . '/templates/admin/{,*/}*.twig', \GLOB_BRACE) ?: []);

        $used = ['calmfox_przelewy24.gateway' => 'services.yaml'];
        foreach ($sources as $source) {
            preg_match_all("/'(calmfox_przelewy24\\.[a-z0-9_.]+)'/", (string) file_get_contents($source), $matches);
            foreach ($matches[1] as $key) {
                $used[$key] = basename($source);
            }
        }

        self::assertGreaterThan(10, \count($used));
        foreach ($used as $key => $source) {
            self::assertArrayHasKey($key, $known, sprintf('%s (used in %s) has no translation', $key, $source));
        }
    }

    public function testNoTranslationIsLeftEmpty(): void
    {
        foreach (self::DOMAINS as $domain) {
            foreach (self::LOCALES as $locale) {
                foreach (self::entries($domain, $locale) as $key => $text) {
                    self::assertNotSame('', trim($text), sprintf('%s is empty in %s.%s.yaml', $key, $domain, $locale));
                }
            }
        }
    }

    /** @return array<string, string> */
    private static function entries(string $domain, string $locale): array
    {
        $lines = file(\dirname(__DIR__, 2) . sprintf('/translations/%s.%s.yaml', $domain, $locale), \FILE_IGNORE_NEW_LINES) ?: [];
        $entries = [];
        foreach ($lines as $line) {
            if (1 === preg_match("/^([a-z0-9_.]+): '(.*)'$/", $line, $m)) {
                $entries[$m[1]] = str_replace("''", "'", $m[2]);
            }
        }
        ksort($entries);

        return $entries;
    }
}
