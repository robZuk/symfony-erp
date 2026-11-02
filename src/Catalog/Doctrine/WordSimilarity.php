<?php

namespace App\Catalog\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: WORD_SIMILARITY(word, text) from pg_trgm: 0 to 1, how closely the word matches a part of the text.
 */
final class WordSimilarity extends FunctionNode
{
    private Node $word;
    private Node $text;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->word = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->text = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return \sprintf('word_similarity(%s, %s)', $sqlWalker->walkStringPrimary($this->word), $sqlWalker->walkStringPrimary($this->text));
    }
}
