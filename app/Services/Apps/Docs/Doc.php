<?php

namespace App\Services\Apps\Docs;

/**
 * A generated document: a title, a one-line subject, and ordered sections.
 *
 * The body of a section is a small list of block shapes rather than a string of
 * HTML or Markdown, because the same document is read three ways — rendered in
 * the builder, downloaded as a file, and handed to a model over MCP — and only
 * one of those wants markup. Each renderer walks the same structure.
 *
 * The block shapes, all of them:
 *   ['type' => 'p',     'text' => string]
 *   ['type' => 'h',     'text' => string]                      a heading inside a section
 *   ['type' => 'note',  'text' => string]                      an aside, set apart
 *   ['type' => 'ul',    'items' => list<string>]
 *   ['type' => 'steps', 'items' => list<string>]               numbered, in order
 *   ['type' => 'kv',    'items' => list<array{k: string, v: string}>]
 *   ['type' => 'table', 'head' => list<string>, 'rows' => list<list<string>>]
 *   ['type' => 'tree',  'items' => list<array{depth: int, text: string, meta?: string}>]
 */
final class Doc
{
    /**
     * @param  list<array{id: string, heading: string, body: list<array<string, mixed>>}>  $sections
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $subject,
        public readonly array $sections,
    ) {}

    /**
     * @return array{kind: string, title: string, subject: string, sections: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'subject' => $this->subject,
            'sections' => $this->sections,
        ];
    }

    /**
     * The same document as Markdown — what a download and an MCP reader get.
     */
    public function toMarkdown(): string
    {
        $out = ['# '.$this->title, '', '_'.$this->subject.'_', ''];

        foreach ($this->sections as $section) {
            $out[] = '## '.$section['heading'];
            $out[] = '';

            foreach ($section['body'] as $block) {
                foreach ($this->blockToMarkdown($block) as $line) {
                    $out[] = $line;
                }
                $out[] = '';
            }
        }

        // Collapse the runs of blank lines the per-block spacing leaves behind.
        $text = (string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $out));

        return rtrim($text)."\n";
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<string>
     */
    private function blockToMarkdown(array $block): array
    {
        return match ($block['type'] ?? '') {
            'h' => ['### '.$block['text']],
            'p' => [(string) $block['text']],
            'note' => ['> '.str_replace("\n", "\n> ", (string) $block['text'])],
            'ul' => array_map(fn ($i): string => '- '.$i, $block['items']),
            'steps' => array_map(
                fn ($i, $n): string => ($n + 1).'. '.$i,
                $block['items'],
                array_keys($block['items']),
            ),
            'kv' => array_map(fn (array $i): string => '- **'.$i['k'].'**: '.$i['v'], $block['items']),
            'table' => $this->tableToMarkdown($block),
            'tree' => array_map(
                fn (array $i): string => str_repeat('  ', (int) $i['depth']).'- '.$i['text']
                    .(($i['meta'] ?? '') !== '' ? '  `'.$i['meta'].'`' : ''),
                $block['items'],
            ),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<string>
     */
    private function tableToMarkdown(array $block): array
    {
        $escape = fn (string $cell): string => str_replace('|', '\\|', $cell);

        $lines = ['| '.implode(' | ', array_map($escape, $block['head'])).' |'];
        $lines[] = '|'.str_repeat(' --- |', count($block['head']));

        foreach ($block['rows'] as $row) {
            $lines[] = '| '.implode(' | ', array_map($escape, $row)).' |';
        }

        return $lines;
    }
}
