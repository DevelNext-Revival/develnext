<?php
namespace ide\autocomplete\php;

use develnext\lexer\inspector\entry\FunctionEntry;
use develnext\lexer\inspector\entry\TypeEntry;
use ide\autocomplete\AutoComplete;
use ide\Ide;
use php\gui\designer\UXAbstractCodeArea;
use php\gui\UXTooltip;
use php\lib\arr;
use php\lib\Char;
use php\lib\str;

/**
 * Hover documentation + same-file go-to-definition for the PHP code editor, reusing the
 * existing autocomplete engine's real (tokenizer/reflection-backed) type resolution
 * instead of any new parsing.
 *
 * Scope for this first milestone: the inspector (AutoComplete::getInspector()) is
 * project-wide but its TypeEntry/FunctionEntry results don't track which source file
 * they came from -- so go-to-definition here only jumps within the currently open file
 * (verified by checking the resolved declaration's line actually contains the expected
 * name), and doesn't yet support jumping to a definition living in another file.
 */
class PhpEditorAssistant
{
    /** @var UXAbstractCodeArea */
    protected $area;

    /** @var AutoComplete */
    protected $complete;

    /** @var UXTooltip */
    protected $tooltip;

    public function __construct(UXAbstractCodeArea $area, AutoComplete $complete)
    {
        $this->area = $area;
        $this->complete = $complete;

        $area->onHoverStart(function () {
            $this->showHover();
        });

        $area->onHoverEnd(function () {
            $this->hideHover();
        });
    }

    protected function isIdentifierChar($ch)
    {
        return $ch !== '' && (Char::isLetterOrDigit($ch) || $ch === '_' || $ch === '$');
    }

    /**
     * @return array [line, column, expression-up-to-and-including-the-hovered-word]
     */
    protected function resolveWordAt($offset)
    {
        $text = $this->area->text;

        if ($offset < 0 || $offset > str::length($text)) {
            return null;
        }

        $line = 0;
        $lastNewLine = -1;

        for ($i = 0; $i < $offset; $i++) {
            if ($text[$i] === "\n") {
                $line++;
                $lastNewLine = $i;
            }
        }

        $column = $offset - $lastNewLine - 1;

        $lineText = $this->area->getParagraph($line)['text'];

        $endCol = $column;
        while ($endCol < str::length($lineText) && $this->isIdentifierChar($lineText[$endCol])) {
            $endCol++;
        }

        if ($endCol === 0) {
            return null;
        }

        $expr = str::sub($lineText, 0, $endCol);

        // hint the resolver this is a call, e.g. hovering "form" in "$this->form()"
        if ($endCol < str::length($lineText) && $lineText[$endCol] === '(') {
            $expr .= '()';
        }

        $word = str::trim($expr);
        $wordStart = $endCol;
        while ($wordStart > 0 && $this->isIdentifierChar($lineText[$wordStart - 1])) {
            $wordStart--;
        }
        $word = str::sub($lineText, $wordStart, $endCol);

        return [$line, $column, $expr, $word];
    }

    protected function describeType($type)
    {
        if ($type instanceof TypeEntry) {
            return "class {$type->fulledName}";
        }

        if ($type instanceof FunctionEntry) {
            return "function {$type->name}()";
        }

        if (is_string($type)) {
            return $type;
        }

        return null;
    }

    protected function showHover()
    {
        $offset = $this->area->hoverCharacterIndex;

        if ($offset < 0) {
            return;
        }

        $resolved = $this->resolveWordAt($offset);

        if (!$resolved) {
            return;
        }

        $line = $resolved[0];
        $column = $resolved[1];
        $expr = $resolved[2];
        $word = $resolved[3];

        if (!$word) {
            return;
        }

        $region = $this->complete->findRegion($line, $column);
        $types = $this->complete->identifyAndFetchType($expr, $region);

        $type = arr::first($types);
        $description = $this->describeType($type);

        if (!$description) {
            return;
        }

        $this->hideHover();

        $this->tooltip = new UXTooltip();
        $this->tooltip->text = $description;
        $this->tooltip->show($this->area->form, $this->area->hoverScreenX, $this->area->hoverScreenY + 15);
    }

    protected function hideHover()
    {
        if ($this->tooltip) {
            $this->tooltip->hide();
            $this->tooltip = null;
        }
    }

    /**
     * Jump to a class/function's own declaration if it's the currently open file.
     * Returns true if a jump happened.
     */
    public function goToDefinition()
    {
        $resolved = $this->resolveWordAt($this->area->caretPosition);

        if (!$resolved) {
            return false;
        }

        $word = $resolved[3];

        if (!$word) {
            return false;
        }

        $inspector = $this->complete->getInspector();

        $entry = $inspector->findType($word) ?: $inspector->findTypeByShortName($word) ?: $inspector->findFunction($word);

        if (!$entry || $entry->startLine === null) {
            Ide::toast("Определение для '$word' не найдено в этом файле.");
            return false;
        }

        $paragraph = $this->area->getParagraph($entry->startLine);
        $declarationLine = $paragraph ? $paragraph['text'] : '';

        if (!$declarationLine || !str::contains($declarationLine, $word)) {
            Ide::toast("Определение '$word', возможно, находится в другом файле (пока не поддерживается).");
            return false;
        }

        $this->area->requestFocus();
        $this->area->jumpToLine($entry->startLine, $entry->startPosition ?: 0);

        return true;
    }
}
