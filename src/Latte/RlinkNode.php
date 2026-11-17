<?php
declare(strict_types=1);

namespace Nelson\Resizer\Latte;

use Latte\Compiler\Nodes\Php\Expression\ArrayNode;
use Latte\Compiler\Nodes\Php\ModifierNode;
use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;
use Nelson\Resizer\DI\ResizerExtension;
use Nelson\Resizer\ResizerLinkGenerator;
use Nette\Application\LinkGenerator;
use stdClass;

final class RlinkNode extends StatementNode
{
	/** Name of the Latte provider holding ResizerLinkGenerator */
	public const string PROVIDER = 'resizerLinkGenerator';

	public ArrayNode $args;
	public ModifierNode $modifier;
	public string $mode;


	public static function create(Tag $tag): static
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

		return $node;
	}


	public function print(PrintContext $context): string
	{
		return $context->format(
			'echo %modify(Nelson\Resizer\Latte\RlinkNode::generate($this->global, %dump, %node)) %line;',
			$this->modifier,
			$this->mode === 'rlinkabs',
			$this->args,
			$this->position,
		);
	}


	/**
	 * Runtime part of {rlink}/{rlinkabs}. In static mode returns a direct URL of an existing thumbnail,
	 * otherwise a link to the resize presenter with the format filled in, so the thumbnail
	 * it generates is found by the next static lookup. Works with LinkGenerator (e-mails) too.
	 * @param array<int|string, mixed> $args file, params, format - positional or named
	 */
	public static function generate(stdClass $global, bool $absolute, array $args): string
	{
		/** @var \Nette\Application\UI\Component|LinkGenerator $lg */
		$lg = $global->uiPresenter ?? $global->uiControl;
		$linkGenerator = $global->{self::PROVIDER} ?? null;

		if ($linkGenerator instanceof ResizerLinkGenerator && $linkGenerator->isStatic()) {
			$named = !array_key_exists(0, $args);
			$file = $args[$named ? 'file' : 0] ?? null;
			$params = $args[$named ? 'params' : 1] ?? null;
			$format = $args[$named ? 'format' : 2] ?? null;

			if (is_string($file) && (is_string($params) || $params === null) && (is_string($format) || $format === null)) {
				return $linkGenerator->link($lg, $file, $params, $format, $absolute);
			}
		}

		return (string) $lg->link(($absolute ? '//' : '') . self::getLink($lg), $args);
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
