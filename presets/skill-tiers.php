<?php
/**
 * Named skill tiers, the common shape in social and coed leagues.
 *
 * "Rec" before "Recreational" is not the ordering that matters: aliases are
 * matched longest first regardless of the order written here.
 */

return array(
	array( 'key' => 'recreational', 'label' => 'Recreational', 'order' => 10, 'aliases' => array( 'recreational', 'rec', 'social' ) ),
	array( 'key' => 'intermediate', 'label' => 'Intermediate', 'order' => 20, 'aliases' => array( 'intermediate', 'int' ) ),
	array( 'key' => 'competitive',  'label' => 'Competitive',  'order' => 30, 'aliases' => array( 'competitive', 'comp', 'advanced' ) ),
);
