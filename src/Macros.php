<?php

namespace Nelson\Resizer;

use Exception;
use Latte;
use Latte\HtmlNode;
use Latte\MacroNode;
use Latte\PhpWriter;

class Macros extends Latte\Macros\MacroSet
{

	public static function install(Latte\Compiler $parser)
	{
		$me = new static($parser);

		$me->addMacro('rsrc', function (MacroNode $node, PhpWriter $writer) use ($me) {
			return $me->macroSrc($node, $writer);
		}, NULL, function(MacroNode $node, PhpWriter $writer) use ($me) {
			self::checkIsImgTag($node->htmlNode);
			self::checkAttrNotDuplicate($node->htmlNode, 'src');
			return ' ?> src="<?php ' . $me->macroResizer($node, $writer) . ' ?>"<?php ';
		});

		$me->addMacro('rhref', function (MacroNode $node, PhpWriter $writer) use ($me) {
			return $me->macroSrc($node, $writer);
		}, NULL, function(MacroNode $node, PhpWriter $writer) use ($me) {
			self::checkAttrNotDuplicate($node->htmlNode, 'href');
			return ' ?> href="<?php ' . $me->macroResizer($node, $writer) . ' ?>"<?php ';
		});
	}


	public function macroResizer(MacroNode $node, PhpWriter $writer)
	{
		$absolute = substr($node->args, 0, 2) === '//' ? '//' : '';
		$args = $absolute ? substr($node->args, 2) : $node->args;
		return $writer->write('echo %escape(%modify($presenter->link("' . $absolute . ':Base:Resizer:Resize:", Nelson\Resizer\Macros::prepareArguments([' . $args . ']))))');
	}


	public static function prepareArguments(array $arguments): array
	{
		return $arguments;
	}


	public static function checkIsImgTag(HtmlNode $node): void
	{
		$tagName = $node->name;

		if ($tagName !== 'img') {
			throw new Exception(sprintf('Macro n:rsrc can only be used in <img> tag, <%s> used', $tagName));
		}
	}


	public static function checkAttrNotDuplicate(HtmlNode $node, string $attr): void
	{
		if (array_key_exists($attr, $node->attrs)) {
			throw new Exception(sprintf('Attribute "%s" already defined with value "%s"', $attr, $node->attrs[$attr]));
		}
	}

}