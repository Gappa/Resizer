<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Exception;
use Latte\Compiler;
use Latte\HtmlNode;
use Latte\MacroNode;
use Latte\Macros\MacroSet;
use Latte\PhpWriter;
use Nelson\Resizer\DI\ResizerExtension;
use Nette\Application\LinkGenerator;

final class Macros extends MacroSet
{
	public static function install(Compiler $parser): void
	{
		$me = new static($parser);
		$me->addMacro('rlink', [$me, 'macroResizer']);
	}


	public function macroResizer(MacroNode $node, PhpWriter $writer): string
	{
		$absolute = substr($node->args, 0, 2) === '//' ? '"//" . ' : '';
		$args = $absolute ? substr($node->args, 2) : $node->args;
		return $writer->using($node, $this->getCompiler())
			->write(
				'echo %escape(%modify('
				. '$this->global->uiControl'
				. '->link('
					. $absolute
					. 'Nelson\Resizer\Macros::getLink($this->global->uiControl)' . ','
					. self::prepareArgs($args)
				. ')))'
			);
	}


	public static function getLink(object $class): string
	{
		return ResizerExtension::getResizerLink(!($class instanceof LinkGenerator));
	}


	public static function prepareArgs(string $args): string
	{
		return '[' . $args . ']';
	}

}
