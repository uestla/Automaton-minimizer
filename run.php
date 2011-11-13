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

	echo "\n\n================================ Source automaton =================================\n\n";

	$a = Automaton::fromFile( getcwd() . '/' . $argv[1] )
		->_print();

	echo "\n\n\n================================= Epsilon removed =================================\n\n";

	$a->removeEpsilon()
		->_print();

	echo "\n\n\n=================================== Determinized ==================================\n\n";

	$a->determinize()
		->_print();

	echo "\n\n\n==================================== Minimized ====================================\n\n";

	$a->minimize()
		->_print();

	$a->save( getcwd() . '/' . $argv[2] );
	echo "\n\n\n=== Minimized automaton successfully saved to '$argv[2]'.\n\n";

} catch (Exception $e) {
	echo "Error: {$e->getMessage()}\n\n";
	die();
}