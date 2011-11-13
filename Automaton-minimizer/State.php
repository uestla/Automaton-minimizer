<?php

/**
 * @property bool $initial
 * @property bool $final
 */
class State extends Nette\Object
{
	/** @var string */
	protected $id;

	/** @var bool */
	protected $initial;

	/** @var bool */
	protected $final;

	/** @var array of State instances */
	protected $transitions = array();



	public function __construct($id)
	{
		$this->id = (string) $id;
	}



	public function __toString()
	{
		return $this->id;
	}



	public function _print($space, $forProgtest = FALSE)
	{
		if ($this->initial)
			if ($this->final)
				echo ($prefix = Automaton::INIT_ST . Automaton::FINAL_ST);
			else
				echo ($prefix = ' ' . Automaton::INIT_ST);
		elseif ($this->final)
			echo ($prefix = ' ' . Automaton::FINAL_ST);
		else
			echo ($prefix = '  ');

		echo $this->id . $space( $prefix . $this->id );

		// transitions
		foreach ($this->transitions as $targets) {
			echo ($value = (count($targets)
					? implode( Automaton::STATES_SEP, $targets )
					: Automaton::NULL_POINTER ) )
				. $space( $value );
		}

		echo "\n";
	}



	public function getId()
	{
		return $this->id;
	}



	public function getInitial()
	{
		return $this->initial;
	}



	public function setInitial($i = TRUE)
	{
		$this->initial = $i;
		return $this;
	}



	public function getFinal()
	{
		return $this->final;
	}



	public function setFinal($f = TRUE)
	{
		$this->final = $f;
		return $this;
	}



	public function getTransitions()
	{
		return $this->transitions;
	}



	public function setTransitions(array $t)
	{
		$this->transitions = $t;
		return $this;
	}



	public function getAlphabet()
	{
		return array_keys($this->transitions);
	}



	public function removeEpsilon()
	{
		$epsKey = Automaton::EPS;
		$this->getEpsilonUnion($this, $union);

		if (count($union)) {
			$transitions = array();

			foreach ($union as $state) {
				if ($state->final) {
					$this->final = TRUE;
				}

				foreach ($this->getAlphabet() as $letter) {
					if ($letter === $epsKey) continue;

					if (!isset($transitions[$letter])) {
						$transitions[$letter] = array();
					}

					$transitions[$letter] = array_merge($transitions[$letter], $state->transitions[$letter]);
					usort($transitions[$letter], __CLASS__ . '::compare');
					$transitions[$letter] = array_unique( $transitions[$letter] );
				}
			}

			$this->transitions = $transitions;

		} else {
			unset($this->transitions[ $epsKey ]);
		}
	}



	private function getEpsilonUnion($state, & $union = NULL)
	{
		$epsKey = Automaton::EPS;
		if ($union === NULL) {
			$union = array();
		}

		if (isset($state->transitions[$epsKey]) && count($state->transitions[$epsKey])) {
			if (!in_array($state, $union, TRUE)) {
				$union[] = $state;
			}

			foreach ($state->transitions[$epsKey] as $s) {
				if (!in_array($s, $union, TRUE) ) {
					$union[] = $s;
					$this->getEpsilonUnion($s, $union);
				}
			}
		}
	}



	public function normalize()
	{
		ksort($this->transitions);
		return $this;
	}



	public function isBlind()
	{
		if ($this->final) return FALSE;

		foreach ($this->transitions as $targets) {
			if (count($targets) > 1 || (count($targets) === 1 && $targets[0] !== $this))
				return FALSE;
		}

		return TRUE;
	}



	public function hasMultipleTransitions()
	{
		foreach ($this->transitions as $letter => $targets) {
			if (count($targets) > 1) return TRUE;
		}

		return FALSE;
	}



	public function removeStateById($id)
	{
		foreach ($this->transitions as $letter => $targets) {
			foreach ($targets as $key => $state) {
				if ($state->id === $id) {
					unset($targets[$key]);
				}
			}

			$this->transitions[$letter] = array_values($targets);
		}
	}



	public function removeTransition($letter, State $state)
	{
		if (($key = array_search($state, (array) $this->transitions[$letter], TRUE)) === FALSE) {
			throw new Exception("State '$state' not found in '$letter' transition of '$this' state.");
		}

		unset($this->transitions[$letter][$key]);
	}



	public static function compare(State $s1, State $s2)
	{
		return (int) $s1->id - (int) $s2->id;
	}
}