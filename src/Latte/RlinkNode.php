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
		$tag->parser->stream->tryConsume(',');

		$node = new static;
		$node->args = $tag->parser->parseArguments();
		$node->modifier = $tag->parser->parseModifier();
		$node->modifier->escape = true;
		$node->modifier->check = false;
		$node->mode = $tag->name;

		if ($tag->isNAttribute()) {
			$children = $tag->htmlElement?->attributes?->children;

			if ($children !== null) {
				// move at the beginning
				array_unshift($children, $node);
				return null;
			}
		}

		return $node;
	}


	public function print(PrintContext $context): string
	{
		$abs = $this->mode === 'rlinkabs' ? '"//" . ' : '';

		return $context->format(
			''
			. '$lg = $this->global->uiPresenter ?? $this->global->uiControl;'
			. 'echo %modify('
			. '$lg->link(' . $abs . ' Nelson\Resizer\Latte\RlinkNode::getLink($lg), %node)) %line;',
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
