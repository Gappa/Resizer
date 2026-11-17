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
use Nette\Application\UI\Component;
use Nette\InvalidArgumentException;
use Nette\InvalidStateException;
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
		return match ($this->mode) {
			// attribute value, escaped
			'rsrcset' => $context->format(
				'echo %modify(Nelson\Resizer\Latte\RlinkNode::srcset($this->global, %node)) %line;',
				$this->modifier,
				$this->args,
				$this->position,
			),
			// HTML built and escaped by ResizerLinkGenerator::picture()
			'rpicture' => $context->format(
				'echo Nelson\Resizer\Latte\RlinkNode::picture($this->global, %node) %line;',
				$this->args,
				$this->position,
			),
			default => $context->format(
				'echo %modify(Nelson\Resizer\Latte\RlinkNode::generate($this->global, %dump, %node)) %line;',
				$this->modifier,
				$this->mode === 'rlinkabs',
				$this->args,
				$this->position,
			),
		};
	}


	/**
	 * Runtime part of {rsrcset $file, [$params, ...], $format}, named arguments file, params, format, absolute.
	 * @param array<int|string, mixed> $args
	 */
	public static function srcset(stdClass $global, array $args): string
	{
		[$linker, $linkGenerator] = self::getLinkers($global, 'rsrcset');
		$format = $args[2] ?? $args['format'] ?? null;

		if ($format !== null && !is_string($format)) {
			throw new InvalidArgumentException('{rsrcset}: format must be a string or null.');
		}

		return $linkGenerator->srcset(
			$linker,
			self::getFile($args, 'rsrcset'),
			self::getParamsList($args, 'rsrcset'),
			$format,
			(bool) ($args['absolute'] ?? false),
		);
	}


	/**
	 * Runtime part of {rpicture $file, [$params, ...], attribute: value, ...}, named arguments file, params,
	 * absolute, any other named argument is an attribute of the <img>.
	 * @param array<int|string, mixed> $args
	 */
	public static function picture(stdClass $global, array $args): string
	{
		[$linker, $linkGenerator] = self::getLinkers($global, 'rpicture');
		$attributes = [];

		foreach ($args as $name => $value) {
			if (is_int($name) || in_array($name, ['file', 'params', 'absolute'], true)) {
				continue;
			}

			if ($value !== null && !is_scalar($value)) {
				throw new InvalidArgumentException("{rpicture}: attribute '$name' must be a scalar or null.");
			}

			$attributes[$name] = $value;
		}

		return $linkGenerator->picture(
			$linker,
			self::getFile($args, 'rpicture'),
			self::getParamsList($args, 'rpicture'),
			$attributes,
			(bool) ($args['absolute'] ?? false),
		);
	}


	/** @return array{Component|LinkGenerator, ResizerLinkGenerator} */
	private static function getLinkers(stdClass $global, string $tag): array
	{
		$linker = $global->uiPresenter ?? $global->uiControl ?? null;
		$linkGenerator = $global->{self::PROVIDER} ?? null;

		if (!($linker instanceof Component || $linker instanceof LinkGenerator) || !$linkGenerator instanceof ResizerLinkGenerator) {
			throw new InvalidStateException("{{$tag}} needs a presenter/control (or LinkGenerator) and ResizerLinkGenerator, register the resizer DI extension.");
		}

		return [$linker, $linkGenerator];
	}


	/** @param array<int|string, mixed> $args */
	private static function getFile(array $args, string $tag): string
	{
		$file = $args[0] ?? $args['file'] ?? null;

		if (!is_string($file)) {
			throw new InvalidArgumentException("{{$tag}}: the file must be a string.");
		}

		return $file;
	}


	/**
	 * @param array<int|string, mixed> $args
	 * @return list<string>
	 */
	private static function getParamsList(array $args, string $tag): array
	{
		$paramsList = $args[1] ?? $args['params'] ?? null;
		$list = [];

		foreach (is_array($paramsList) ? $paramsList : [] as $params) {
			if (!is_string($params)) {
				$list = [];
				break;
			}

			$list[] = $params;
		}

		if ($list === []) {
			throw new InvalidArgumentException("{{$tag}}: params must be a non-empty array of strings, e.g. ['400x', '800x'].");
		}

		return $list;
	}


	/**
	 * Runtime part of {rlink}/{rlinkabs}. In static mode returns a direct URL of an existing thumbnail, with signing enabled a signed link;
	 * otherwise a link to the resize presenter with the format filled in, so the thumbnail
	 * it generates is found by the next static lookup. Works with LinkGenerator (e-mails) too.
	 * @param array<int|string, mixed> $args file, params, format - positional or named
	 */
	public static function generate(stdClass $global, bool $absolute, array $args): string
	{
		/** @var \Nette\Application\UI\Component|LinkGenerator $lg */
		$lg = $global->uiPresenter ?? $global->uiControl;
		$linkGenerator = $global->{self::PROVIDER} ?? null;

		if ($linkGenerator instanceof ResizerLinkGenerator && ($linkGenerator->isStatic() || $linkGenerator->isSigned())) {
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
