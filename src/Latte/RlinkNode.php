<?php
declare(strict_types=1);

namespace Nelson\Resizer\Latte;

use Latte\Compiler\Nodes\Php\Expression\ArrayNode;
use Latte\Compiler\Nodes\Php\ExpressionNode;
use Latte\Compiler\Nodes\Php\ModifierNode;
use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;
use Nelson\Resizer\DI\ResizerExtension;
use Nette\Application\LinkGenerator;


final class RlinkNode extends StatementNode
{
	public ExpressionNode $destination;
	public ArrayNode $args;
	public ModifierNode $modifier;
	public string $mode;


	public static function create(Tag $tag): ?static
	{
		$tag->outputMode = $tag::OutputKeepIndentation;
		$tag->expectArguments();
		$node = new static;
		$tag->parser->stream->tryConsume(',');
		$node->args = $tag->parser->parseArguments();
		$node->modifier = $tag->parser->parseModifier();
		$node->modifier->escape = true;
		$node->modifier->check = false;
		$node->mode = $tag->name;

		if ($tag->isNAttribute()) {
			// move at the beginning
			array_unshift($tag->htmlElement->attributes->children, $node);
			return null;
		}

		return $node;
	}


	public function print(PrintContext $context): string
	{
		return $context->format(
			''
			. '$lg = $this->global->uiPresenter ?? $this->global->uiControl;'
			. 'echo %modify('
			. '$lg->link(Nelson\Resizer\Latte\RlinkNode::getLink($lg), %node)) %line;',
			$this->modifier,
			$this->args,
			$this->position,
		);
	}


	public function &getIterator(): \Generator
	{
		yield $this->args;
		yield $this->modifier;
	}


	public static function getLink(object $class): string
	{
		return ResizerExtension::getResizerLink(!($class instanceof LinkGenerator));
	}
}
