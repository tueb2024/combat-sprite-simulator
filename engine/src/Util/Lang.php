<?php

namespace JumPHP\Util;

/**
 * Translations. Source strings are the keys; config/lang.yaml gives the other languages:
 *   fr: { 'START': 'JOUER', 'Score {n}': 'Score {n}' }
 * A missing translation returns the key itself, with its {placeholders} filled.
 */
class Lang
{
    private static ?self $instance = null;

    /** @param array<string, array<string, string>> $tables */
    public function __construct(private string $lang = 'en', private array $tables = [])
    {
        self::$instance = $this;
    }

    /** From config/lang.yaml of the game: { fr: { 'START': 'JOUER' }, ... } */
    public static function fromConfig(\JumPHP\Kernel\GameConfig $config): self
    {
        return new self($config->lang(), array_replace_recursive(self::engineTables(), $config->file('lang.yaml')));
    }

    /** The engine's own texts (the runtime notice, badge and guide...) in every language: src/Util/engine-lang.yaml; the game's lang.yaml wins. */
    public static function engineTables(): array
    {
        static $tables = null;
        if ($tables === null) {
            $file = __DIR__ . '/engine-lang.yaml';
            $tables = is_file($file) ? (\Symfony\Component\Yaml\Yaml::parseFile($file) ?? []) : [];
        }
        return $tables;
    }

    public static function t(string $key, array $params = []): string
    {
        return (self::$instance ?? new self())->translate($key, $params);
    }

    public function translate(string $key, array $params = []): string
    {
        $text = $this->tables[$this->lang][$key] ?? $key;
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string)$value, $text);
        }
        return $text;
    }

    /** @param array<string, array<string, string>> $tables new translations (hot reload of config/lang.yaml) */
    public function setTables(array $tables): void
    {
        $this->tables = $tables;
    }

    public function setLang(string $lang): void
    {
        $this->lang = $lang;
    }

    public function getLang(): string
    {
        return $this->lang;
    }

    /** @return string[] the languages with a table, plus the source one */
    public function available(string $source = 'en'): array
    {
        return array_values(array_unique(array_merge([$source], array_keys($this->tables))));
    }
}
