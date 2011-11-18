<?php

use Nette\Diagnostics\Debugger;


require_once __DIR__ . '/3rd-party/Nette/loader.php';
require_once __DIR__ . '/Automaton-minimizer/Automaton.php';
require_once __DIR__ . '/Automaton-minimizer/State.php';

Debugger::enable( Debugger::DEVELOPMENT );
Debugger::$strictMode = TRUE;
Debugger::$maxDepth = 6;

if (!isset($argv[1])) {
	echo "Source automaton file not specified.\n\n";
	die();

} elseif (!isset($argv[2])) {
	$argv[2] = 'output.txt';
}



try {
	set_time_limit(0);

	$a = Automaton::fromFile( getcwd() . '/' . $argv[1] );

	echo "\n\n================================ Source automaton =================================\n\n";

	$a->_print();

	echo "\n\n\n================================= Epsilon removed =================================\n\n";

	$a->removeEpsilon()
		->_print();

	echo "\n\n\n=================================== Determinized ==================================\n\n";

	$a->determinize()
		->_print();

	echo "\n\n\n==================================== Minimized ====================================\n\n";

	$a->minimize()
		->_print();

	echo "\n\n\n==================================== Normalized ====================================\n\n";

	$a->normalize()
		->_print();

	$a->save( getcwd() . '/' . $argv[2] );
	echo "\n\n\n=== Minimized & normalized automaton successfully saved to '$argv[2]'.\n\n";

} catch (Exception $e) {
	echo "\nError: {$e->getMessage()}\n\n";
	die();
}